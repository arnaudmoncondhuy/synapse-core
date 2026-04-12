<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\CodeExecutor;

use ArnaudMoncondhuy\SynapseCore\Contract\CodeExecutorInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Implémentation de {@see CodeExecutorInterface} qui délègue à un container
 * sidecar HTTP isolé (Chantier E phase 2 — backend retenu).
 *
 * ## Architecture
 *
 * `basile-brain` (le container Symfony principal) fait des POST HTTP vers
 * `http://synapse-sandbox:8000/execute` via le réseau interne
 * `basile-network`. Le sidecar `synapse-sandbox` est un container Python
 * minimaliste (voir `basile/sandbox/server.py`) qui exécute le code dans
 * des sous-processes avec timeout + limites mémoire, capture stdout/stderr,
 * et retourne un JSON.
 *
 * Aucun port n'est exposé sur l'host pour le sandbox — il n'est accessible
 * que depuis le network Docker interne. Le sandbox tourne en user non-root,
 * filesystem read-only sauf tmpfs /tmp, cap_drop ALL, no-new-privileges,
 * mem_limit 256m, cpus 0.5. Voir `basile/docker-compose.yml` section
 * `synapse-sandbox` pour le détail.
 *
 * ## Dégradation propre
 *
 * Si le sidecar est down, `execute()` retourne
 * `ExecutionResult::backendUnavailable()` avec un message explicite plutôt
 * que de lever une exception. Ça permet à un agent autonome de continuer
 * son flow et d'essayer une approche alternative.
 *
 * ## Activation
 *
 * Par défaut, l'alias `CodeExecutorInterface` pointe sur `NullCodeExecutor`.
 * Pour activer ce backend, configurer `synapse.code_executor.enabled: true`
 * dans le projet hôte (voir `basile/config/packages/synapse.yaml`). La
 * logique d'override de l'alias est dans
 * {@see \ArnaudMoncondhuy\SynapseCore\DependencyInjection\SynapseCoreExtension::load()}.
 */
final class HttpCodeExecutor implements CodeExecutorInterface
{
    private const SUPPORTED_LANGUAGES = ['python'];
    private const DEFAULT_TIMEOUT_S = 10;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%synapse.code_executor.sandbox_url%')]
        private readonly string $sandboxUrl = 'http://synapse-sandbox:8000',
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function execute(string $code, string $language = 'python', array $inputs = [], array $options = []): ExecutionResult
    {
        if (!in_array($language, self::SUPPORTED_LANGUAGES, true)) {
            return ExecutionResult::unsupportedLanguage($language);
        }

        $timeout = $options['timeout_s'] ?? self::DEFAULT_TIMEOUT_S;
        if (!is_int($timeout) || $timeout <= 0) {
            $timeout = self::DEFAULT_TIMEOUT_S;
        }

        try {
            $payload = [
                'code' => $code,
                'language' => $language,
                'timeout_s' => $timeout,
            ];

            // Fichiers pré-stagés dans le sandbox (provenant de CodeExecuteTool)
            if (!empty($inputs['files']) && \is_array($inputs['files'])) {
                $payload['files'] = $inputs['files'];
            }

            $response = $this->httpClient->request('POST', $this->sandboxUrl.'/execute', [
                'json' => $payload,
                // Le timeout HTTP doit être légèrement supérieur au timeout
                // d'exécution pour laisser le sandbox rapatrier stdout/stderr
                // et renvoyer sa réponse.
                'timeout' => $timeout + 5,
            ]);

            $data = $response->toArray(throw: false);
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->warning('HttpCodeExecutor: sandbox unreachable — {msg}', [
                'msg' => $e->getMessage(),
                'url' => $this->sandboxUrl,
            ]);

            return ExecutionResult::backendUnavailable(
                sprintf('Code execution sandbox unreachable at %s: %s', $this->sandboxUrl, $e->getMessage())
            );
        }

        // Filtrer les output_files pour ne garder que les entrées valides
        $outputFiles = \is_array($data['output_files'] ?? null)
            ? array_values(array_filter(
                $data['output_files'],
                fn ($f) => \is_array($f) && isset($f['name'], $f['mime_type'], $f['data'])
                    && \is_string($f['name']) && \is_string($f['mime_type']) && \is_string($f['data']),
            ))
            : [];

        return new ExecutionResult(
            success: (bool) ($data['success'] ?? false),
            stdout: is_string($data['stdout'] ?? null) ? $data['stdout'] : '',
            stderr: is_string($data['stderr'] ?? null) ? $data['stderr'] : '',
            returnValue: $data['return_value'] ?? null,
            durationMs: is_int($data['duration_ms'] ?? null) ? $data['duration_ms'] : 0,
            errorType: is_string($data['error_type'] ?? null) ? $data['error_type'] : null,
            errorMessage: is_string($data['error_message'] ?? null) ? $data['error_message'] : null,
            outputFiles: $outputFiles,
        );
    }

    public function isAvailable(): bool
    {
        try {
            $response = $this->httpClient->request('GET', $this->sandboxUrl.'/health', [
                'timeout' => 2,
            ]);

            return 200 === $response->getStatusCode();
        } catch (HttpClientExceptionInterface $e) {
            return false;
        }
    }

    public function getSupportedLanguages(): array
    {
        return self::SUPPORTED_LANGUAGES;
    }
}
