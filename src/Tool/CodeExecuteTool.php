<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\CodeExecutorInterface;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseCodeExecutedEvent;
use ArnaudMoncondhuy\SynapseCore\Manager\ConversationManager;
use ArnaudMoncondhuy\SynapseCore\Service\AttachmentStorageService;
use ArnaudMoncondhuy\SynapseCore\Service\ConversationContextHolder;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseCodeExecution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Outil LLM qui expose l'exécution de code Python via {@see CodeExecutorInterface}.
 *
 * Le tool est **toujours enregistré** dans le ToolRegistry, indépendamment
 * de l'état du sandbox. Quand le backend configuré est {@see \ArnaudMoncondhuy\SynapseCore\CodeExecutor\NullCodeExecutor},
 * les appels retournent une erreur `BackendUnavailable` lisible plutôt que de
 * crasher — permettant aux agents de dégrader proprement.
 *
 * ## Équivalent Claude Code
 *
 * C'est le cousin de l'outil `Bash` de Claude Code : un multiplicateur
 * massif de capacités pour un agent. Avec cet outil, un agent peut parser
 * un CSV, calculer une moyenne mobile, appeler pandas, manipuler du JSON
 * complexe — sans que l'hôte ait à câbler un outil dédié pour chaque cas.
 *
 * ## Visibilité admin
 *
 * Même quand le sandbox n'est pas configuré, le tool reste listé dans
 * l'admin comme désactivé. Cela permet au mainteneur de voir qu'il existe
 * et de l'activer via `synapse.code_executor.enabled: true` au moment où
 * un sandbox est disponible côté infra.
 */
#[Autoconfigure(tags: ['synapse.tool'])]
class CodeExecuteTool implements AiToolInterface
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB par fichier

    public function __construct(
        private readonly CodeExecutorInterface $executor,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?ConversationContextHolder $conversationContextHolder = null,
        private readonly ?ConversationManager $conversationManager = null,
        private readonly ?AttachmentStorageService $attachmentStorage = null,
    ) {
    }

    public function getName(): string
    {
        return 'code_execute';
    }

    public function getLabel(): string
    {
        return 'Exécuter du code Python';
    }

    public function getDescription(): string
    {
        return 'Exécute du code Python dans un environnement isolé et retourne le stdout, stderr, '
            .'et la valeur retournée. Utilise cet outil quand tu dois faire des calculs non-triviaux, '
            .'manipuler des données tabulaires (CSV, JSON), parser du texte avec des regex, ou quand '
            .'écrire un script est plus fiable que de raisonner le résultat toi-même. Le code s\'exécute '
            .'dans un sandbox sans accès réseau. Seule la bibliothèque standard Python est disponible '
            .'(csv, json, re, math, collections, itertools, etc.). N\'utilise PAS de librairies tierces '
            .'(pas de pandas, numpy, requests, etc.). '
            .'Quand tu présentes des résultats tabulaires à l\'utilisateur, formate-les en tableau Markdown. '
            .'IMPORTANT — Génération de fichiers : quand l\'utilisateur demande un fichier (CSV, Excel, rapport, export, etc.), '
            .'tu DOIS l\'écrire dans le répertoire OUTPUT_DIR pour qu\'il soit proposé en téléchargement. '
            .'OUTPUT_DIR est une variable Python DÉJÀ DÉFINIE dans l\'environnement (valeur: "_output"). '
            .'Ne la redéfinis PAS. N\'utilise PAS /mnt/data/ ni aucun chemin absolu — le filesystem est read-only sauf OUTPUT_DIR. '
            .'Exemple correct : import os; os.makedirs(OUTPUT_DIR, exist_ok=True); '
            .'f = open(os.path.join(OUTPUT_DIR, "rapport.csv"), "w")';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'string',
                    'description' => 'Le code source à exécuter. Pour Python : pose le résultat final dans une variable `result` qui sera retournée comme `return_value`.',
                ],
                'language' => [
                    'type' => 'string',
                    'enum' => ['python'],
                    'description' => 'Langage du code. Seul Python est supporté pour l\'instant.',
                    'default' => 'python',
                ],
            ],
            'required' => ['code'],
        ];
    }

    public function execute(array $parameters): mixed
    {
        $code = $parameters['code'] ?? null;
        if (!is_string($code) || '' === $code) {
            return [
                'success' => false,
                'error_type' => 'InvalidInput',
                'error_message' => 'Parameter "code" is required and must be a non-empty string.',
            ];
        }

        $language = isset($parameters['language']) && is_string($parameters['language'])
            ? $parameters['language']
            : 'python';

        // Collecter les fichiers uploadés dans la conversation pour les pré-stager dans le sandbox
        $files = $this->collectConversationFiles();

        $result = $this->executor->execute($code, $language, ['files' => $files]);
        $resultArray = $result->toArray();

        // Déposer les artefacts de sortie dans le context holder pour le pipeline generated_attachments.
        // Le base64 ne doit PAS aller dans le tool result (gaspillage de tokens) — seulement les métadonnées.
        if (!empty($result->outputFiles) && null !== $this->conversationContextHolder) {
            $artifacts = [];
            foreach ($result->outputFiles as $file) {
                $artifacts[] = [
                    'mime_type' => $file['mime_type'],
                    'data' => $file['data'],
                    'name' => $file['name'],
                ];
            }
            $this->conversationContextHolder->addGeneratedArtifacts($artifacts);

            // Métadonnées pour le LLM (noms et types seulement, pas le contenu)
            $resultArray['generated_files'] = array_map(
                fn (array $f) => ['name' => $f['name'], 'mime_type' => $f['mime_type']],
                $result->outputFiles,
            );
        }
        // Retirer output_files du result array envoyé au LLM (le base64 est dans le context holder)
        unset($resultArray['output_files']);

        // Audit trail persistant : stocke l'exécution dans `synapse_code_execution`
        // pour audit a posteriori. Try/catch pour que l'audit ne bloque JAMAIS
        // l'exécution du code si la DB est down ou la table manque.
        try {
            $execution = (new SynapseCodeExecution())
                ->setCode($code)
                ->setLanguage($language)
                ->setSuccess((bool) ($resultArray['success'] ?? false))
                ->setStdout(is_string($resultArray['stdout'] ?? null) ? $resultArray['stdout'] : null)
                ->setStderr(is_string($resultArray['stderr'] ?? null) ? $resultArray['stderr'] : null)
                ->setReturnValue($resultArray['return_value'] ?? null)
                ->setDurationMs(is_int($resultArray['duration_ms'] ?? null) ? $resultArray['duration_ms'] : 0)
                ->setErrorType(is_string($resultArray['error_type'] ?? null) ? $resultArray['error_type'] : null)
                ->setErrorMessage(is_string($resultArray['error_message'] ?? null) ? $resultArray['error_message'] : null);

            $this->entityManager->persist($execution);
            $this->entityManager->flush();
        } catch (\Throwable) {
            // Best-effort — si la DB est down ou la table manque, on continue
            // pour que le LLM ait quand même son résultat.
        }

        // Principe 8 : dispatch d'un event porteur du code source + résultat
        // complet pour que la transparency sidebar du chat puisse afficher
        // une carte dédiée avec syntax-highlighted Python + stdout + return_value.
        $this->eventDispatcher->dispatch(new SynapseCodeExecutedEvent(
            code: $code,
            language: $language,
            result: $resultArray,
        ));

        return $resultArray;
    }

    /**
     * Collecte les fichiers uploadés dans la conversation courante pour le pré-staging sandbox.
     *
     * @return list<array{name: string, content_base64: string}>
     */
    private function collectConversationFiles(): array
    {
        if (null === $this->conversationContextHolder) {
            return [];
        }

        $files = [];

        // 1. Attachments bruts du message courant (pas encore en base)
        foreach ($this->conversationContextHolder->getAttachments() as $att) {
            $name = $att['name'] ?? 'file';
            $data = $att['data'] ?? '';
            if ('' === $data) {
                continue;
            }
            $decoded = base64_decode($data, true);
            if (false === $decoded || \strlen($decoded) > self::MAX_FILE_SIZE) {
                continue;
            }
            $files[] = [
                'name' => $name,
                'content_base64' => $data,
            ];
        }

        // 2. Attachments des messages précédents (déjà persistés en base)
        $conversationId = $this->conversationContextHolder->getConversationId();
        if (null !== $conversationId && '' !== $conversationId && null !== $this->conversationManager && null !== $this->attachmentStorage) {
            try {
                $dbAttachments = $this->conversationManager->getAttachmentsByConversationId($conversationId);
                foreach ($dbAttachments as $att) {
                    $path = $this->attachmentStorage->getAbsolutePath($att);
                    if (!file_exists($path) || filesize($path) > self::MAX_FILE_SIZE) {
                        continue;
                    }
                    $files[] = [
                        'name' => $att->getOriginalName() ?? basename($path),
                        'content_base64' => base64_encode((string) file_get_contents($path)),
                    ];
                }
            } catch (\Throwable) {
                // Best-effort — DB down = on continue sans les fichiers historiques
            }
        }

        return $files;
    }
}
