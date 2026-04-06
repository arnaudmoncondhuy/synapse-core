<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance;

use ArnaudMoncondhuy\SynapseCore\Contract\PromptJudgeInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Implémentation "LLM reviewer" du {@see PromptJudgeInterface} — Garde-fou #2.
 *
 * Construit un prompt d'évaluation avec grille explicite, l'envoie au LLM via
 * {@see ChatService::ask()} en mode JSON (structured output, Phase 6) et mappe
 * la réponse vers un {@see PromptJudgment}.
 *
 * ## Sélection du modèle reviewer
 *
 * Le reviewer utilise un **preset dédié** identifié par sa clé (paramètre
 * `synapse.governance.judge_preset_key`, vide par défaut). Si ce preset est
 * introuvable ou si la clé est vide, le judge retourne `null` (no-op safe).
 *
 * L'utilisation d'un preset séparé permet :
 *   - d'utiliser un modèle moins coûteux (ex: gemini-flash-lite) comme reviewer
 *     indépendant de celui utilisé en production,
 *   - de changer de modèle reviewer sans toucher au code,
 *   - de désactiver globalement le guardrail en vidant la clé.
 *
 * ## Robustesse
 *
 * Toute exception est **swallowed** : capability manquante, quota épuisé,
 * JSON malformé, preset introuvable → `null`. Un log warning est émis pour
 * permettre le diagnostic sans bloquer le flux métier.
 */
final class ChatServiceBasedPromptJudge implements PromptJudgeInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ChatService $chatService,
        private readonly SynapseModelPresetRepository $presetRepository,
        #[Autowire('%synapse.governance.judge_preset_key%')]
        private readonly string $judgePresetKey = '',
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function judge(SynapseAgent $agent, string $newPrompt, ?string $previousPrompt): ?PromptJudgment
    {
        if ('' === $this->judgePresetKey) {
            return null;
        }

        $preset = $this->presetRepository->findOneBy(['key' => $this->judgePresetKey]);
        if (!$preset instanceof SynapseModelPreset) {
            $this->logger->warning(
                'PromptJudge: reviewer preset "{key}" not found — judgment skipped.',
                ['key' => $this->judgePresetKey],
            );

            return null;
        }

        try {
            $result = $this->chatService->ask(
                message: $this->buildEvaluationPrompt($agent, $newPrompt, $previousPrompt),
                options: [
                    'preset' => $preset,
                    'stateless' => true,
                    'module' => 'governance',
                    'action' => 'prompt_judge',
                    'response_format' => $this->buildResponseSchema(),
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'PromptJudge: chat call failed — {message}',
                ['message' => $e->getMessage(), 'exception' => $e],
            );

            return null;
        }

        $structured = $result['structured_output'] ?? null;
        if (!is_array($structured)) {
            return null;
        }

        $score = $structured['overall_score'] ?? null;
        $rationale = $structured['rationale'] ?? null;
        if (!is_numeric($score) || !is_string($rationale)) {
            return null;
        }

        $scoreFloat = (float) $score;
        $scoreFloat = max(0.0, min(10.0, $scoreFloat));

        $modelUsed = is_string($result['model'] ?? null) ? $result['model'] : 'unknown';

        return new PromptJudgment(
            score: $scoreFloat,
            rationale: $rationale,
            data: $structured,
            judgedBy: 'model:'.$modelUsed,
        );
    }

    /**
     * Construit la consigne envoyée au LLM reviewer. Format : contexte agent,
     * grille d'évaluation explicite, instruction JSON, prompt sous revue.
     */
    private function buildEvaluationPrompt(SynapseAgent $agent, string $newPrompt, ?string $previousPrompt): string
    {
        $lines = [
            "Tu es un auditeur qualité de prompts d'agents LLM. Tu évalues un prompt système.",
            '',
            sprintf('Agent ciblé : %s (clé: %s)', $agent->getName(), $agent->getKey()),
        ];
        $description = $agent->getDescription();
        if ('' !== trim($description)) {
            $lines[] = "Description de l'agent : ".$description;
        }

        $lines[] = '';
        $lines[] = 'Grille de notation (score global entre 0.0 et 10.0) :';
        $lines[] = '  - Clarté : directives non ambiguës, pas de contradictions.';
        $lines[] = '  - Spécificité : scope bien défini, cas limites traités.';
        $lines[] = '  - Sécurité : pas de zones grises, garde-fous présents.';
        $lines[] = "  - Cohérence : alignement avec l'objectif déclaré de l'agent.";
        $lines[] = '';

        if (null !== $previousPrompt && '' !== trim($previousPrompt) && $previousPrompt !== $newPrompt) {
            $lines[] = '=== PROMPT PRÉCÉDENT (pour comparaison) ===';
            $lines[] = $previousPrompt;
            $lines[] = '';
        }

        $lines[] = '=== PROMPT SOUS REVUE ===';
        $lines[] = $newPrompt;
        $lines[] = '';
        $lines[] = 'Réponds uniquement en JSON conforme au schéma fourni.';

        return implode("\n", $lines);
    }

    /**
     * Schéma JSON structuré attendu en réponse du reviewer.
     *
     * @return array<string, mixed>
     */
    private function buildResponseSchema(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'prompt_judgment',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'overall_score' => [
                            'type' => 'number',
                            'description' => 'Note globale entre 0.0 et 10.0',
                        ],
                        'rationale' => [
                            'type' => 'string',
                            'description' => 'Explication synthétique du score en quelques phrases.',
                        ],
                        'criteria' => [
                            'type' => 'object',
                            'properties' => [
                                'clarity' => ['type' => 'number'],
                                'specificity' => ['type' => 'number'],
                                'safety' => ['type' => 'number'],
                                'consistency' => ['type' => 'number'],
                            ],
                            'required' => ['clarity', 'specificity', 'safety', 'consistency'],
                        ],
                        'strengths' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'weaknesses' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'required' => ['overall_score', 'rationale', 'criteria'],
                ],
                'strict' => true,
            ],
        ];
    }
}
