<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\CodeExecutor;

/**
 * Résultat d'une exécution de code via {@see \ArnaudMoncondhuy\SynapseCore\Contract\CodeExecutorInterface::execute()}.
 *
 * VO immutable (Chantier E scaffolding). Toutes les clés sont nommées pour
 * que le caller puisse extraire ce qui l'intéresse sans assumer d'ordre
 * positionnel.
 *
 * ## Sérialisation vers le LLM
 *
 * Quand `CodeExecuteTool` retourne ce résultat au LLM, il le transforme
 * typiquement en petite structure JSON :
 *
 * ```json
 * {
 *   "success": true,
 *   "stdout": "42\n",
 *   "stderr": "",
 *   "return_value": null,
 *   "duration_ms": 127
 * }
 * ```
 *
 * Les clés de gros volume (stdout/stderr tronqués) sont préservées telles
 * quelles ; l'amont est responsable de tronquer si nécessaire avant de
 * renvoyer au modèle.
 */
final readonly class ExecutionResult
{
    /**
     * @param bool $success `true` si l'exécution s'est terminée sans erreur fatale.
     *                      `false` si timeout, exception non rattrapée, OOM, ou
     *                      exécuteur indisponible.
     * @param string $stdout sortie standard capturée (déjà tronquée par l'exécuteur
     *                       à une limite safety configurable, ex: 1 MB)
     * @param string $stderr sortie d'erreur capturée (idem)
     * @param mixed $returnValue valeur retournée par le code (selon la sémantique de
     *                           l'exécuteur : pour Python, c'est typiquement la valeur
     *                           d'une variable `result` conventionnelle)
     * @param int $durationMs durée d'exécution réelle en millisecondes
     * @param string|null $errorType classe d'erreur quand `success = false` (ex:
     *                               `TimeoutException`, `MemoryLimitExceeded`,
     *                               `PythonSyntaxError`, `BackendUnavailable`)
     * @param string|null $errorMessage message d'erreur lisible
     */
    /**
     * @param list<array{name: string, mime_type: string, data: string}> $outputFiles Fichiers générés par le code
     *                                                                                dans le répertoire de sortie (_output/). Chaque entrée contient le nom, le type MIME et le contenu
     *                                                                                base64-encodé. Vide si aucun fichier produit.
     */
    public function __construct(
        public bool $success,
        public string $stdout = '',
        public string $stderr = '',
        public mixed $returnValue = null,
        public int $durationMs = 0,
        public ?string $errorType = null,
        public ?string $errorMessage = null,
        public array $outputFiles = [],
    ) {
    }

    /**
     * Raccourci pour construire un résultat d'erreur "backend non disponible".
     */
    public static function backendUnavailable(string $message): self
    {
        return new self(
            success: false,
            errorType: 'BackendUnavailable',
            errorMessage: $message,
        );
    }

    /**
     * Raccourci pour construire un résultat d'erreur "langage non supporté".
     */
    public static function unsupportedLanguage(string $language): self
    {
        return new self(
            success: false,
            errorType: 'UnsupportedLanguage',
            errorMessage: sprintf('Language "%s" is not supported by this executor.', $language),
        );
    }

    /**
     * Sérialisation sous forme tableau pour JSON/persistance.
     *
     * @return array{success: bool, stdout: string, stderr: string, return_value: mixed, duration_ms: int, error_type: string|null, error_message: string|null, output_files: list<array{name: string, mime_type: string, data: string}>}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'return_value' => $this->returnValue,
            'duration_ms' => $this->durationMs,
            'error_type' => $this->errorType,
            'error_message' => $this->errorMessage,
            'output_files' => $this->outputFiles,
        ];
    }
}
