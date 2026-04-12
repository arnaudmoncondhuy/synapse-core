<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance\PresetArchitect;

use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\PresetValidator;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;

/**
 * Agent de génération de preset LLM.
 *
 * Combine une phase déterministe (scan des providers/modèles) avec une phase
 * optionnelle LLM (structured output) pour recommander un preset optimal.
 *
 * En first-boot (aucun preset existant), seule l'heuristique est utilisée.
 */
class PresetArchitect implements AgentInterface
{
    public function __construct(
        private readonly CandidateScanner $candidateScanner,
        private readonly HeuristicRecommender $heuristicRecommender,
        private readonly ChatService $chatService,
        private readonly SynapseModelPresetRepository $presetRepo,
        private readonly PresetValidator $presetValidator,
    ) {
    }

    public function getName(): string
    {
        return 'preset_generator';
    }

    public function getLabel(): string
    {
        return 'Générateur de preset LLM';
    }

    public function getDescription(): string
    {
        return 'Recommande et crée des presets LLM optimaux en combinant scan déterministe et assistance LLM optionnelle.';
    }

    public function call(Input $input, array $options = []): Output
    {
        $description = $input->getMessage();
        $providerFilter = $options['provider'] ?? null;
        $heuristicOnly = (bool) ($options['heuristic'] ?? false);

        $recommendation = $this->generate(
            $description,
            is_string($providerFilter) ? $providerFilter : null,
            $heuristicOnly,
        );

        return Output::ofData($recommendation->toArray());
    }

    /**
     * Génère une recommandation de preset.
     *
     * @throws \InvalidArgumentException si aucun modèle candidat n'est disponible
     */
    /**
     * @param bool $requireLlm Si true, ne fallback PAS vers l'heuristique en cas d'erreur LLM (lève l'exception)
     */
    public function generate(
        ?string $description = null,
        ?string $providerFilter = null,
        bool $heuristicOnly = false,
        bool $requireLlm = false,
    ): PresetRecommendation {
        // Étape 1 : scan déterministe des candidats
        $candidates = $this->candidateScanner->scan($providerFilter);

        if ([] === $candidates) {
            throw new \InvalidArgumentException('Aucun modèle éligible trouvé. Vérifiez qu\'au moins un provider est configuré avec un modèle text-generation actif et non déprécié.');
        }

        // Étape 2 : choix LLM ou heuristique
        $recommendation = $this->chooseRecommendation($candidates, $description, $heuristicOnly, $requireLlm);

        // Étape 3 : sanity check
        $presetEntity = $recommendation->toPresetEntity();
        if (!$this->presetValidator->isValid($presetEntity)) {
            $reason = $this->presetValidator->getInvalidReason($presetEntity);
            throw new \RuntimeException(sprintf('La recommandation générée est invalide : %s', $reason ?? 'raison inconnue'));
        }

        return $recommendation;
    }

    /**
     * @param list<array{modelId: string, provider: string, providerLabel: string, capabilities: \ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities}> $candidates
     */
    private function chooseRecommendation(
        array $candidates,
        ?string $description,
        bool $heuristicOnly,
        bool $requireLlm,
    ): PresetRecommendation {
        // Si heuristique forcée, pas de LLM
        if ($heuristicOnly) {
            return $this->heuristicRecommender->recommend($candidates);
        }

        // Vérifier si un LLM est disponible (preset actif existant)
        if (!$this->hasActiveLlm()) {
            if ($requireLlm) {
                throw new \RuntimeException('Aucun preset actif — impossible d\'utiliser le mode IA.');
            }

            return $this->heuristicRecommender->recommend($candidates);
        }

        // Tenter l'assistance LLM
        try {
            return $this->generateWithLlm($candidates, $description);
        } catch (\Throwable $e) {
            if ($requireLlm) {
                throw new \RuntimeException('Erreur du LLM : '.$e->getMessage(), 0, $e);
            }

            // Fallback silencieux vers l'heuristique (mode CLI/auto)
            return $this->heuristicRecommender->recommend($candidates);
        }
    }

    private function hasActiveLlm(): bool
    {
        try {
            $active = $this->presetRepo->findOneBy(['isActive' => true]);

            return null !== $active;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<array{modelId: string, provider: string, providerLabel: string, capabilities: \ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities}> $candidates
     */
    private function generateWithLlm(array $candidates, ?string $description): PresetRecommendation
    {
        $candidatesJson = $this->formatCandidatesForPrompt($candidates);

        $prompt = $this->buildLlmPrompt($candidatesJson, $description);

        $result = $this->chatService->ask($prompt, [
            'agent' => $this->getName(),
            'stateless' => true,
            'tools' => [],
            'module' => 'governance',
            'action' => 'preset_generate',
            'response_format' => $this->getResponseSchema(),
        ]);

        $structured = $result['structured_output'] ?? null;
        if (!is_array($structured)) {
            throw new \RuntimeException('Le LLM n\'a pas retourné de structured output.');
        }

        return $this->buildRecommendationFromLlmOutput($structured, $candidates);
    }

    /**
     * @param list<array{modelId: string, provider: string, providerLabel: string, capabilities: \ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities}> $candidates
     */
    private function formatCandidatesForPrompt(array $candidates): string
    {
        $rows = [];
        foreach ($candidates as $c) {
            $caps = $c['capabilities'];
            $rows[] = [
                'model' => $c['modelId'],
                'provider' => $c['provider'],
                'range' => $caps->range->value,
                'pricing_input' => $caps->pricingInput,
                'pricing_output' => $caps->pricingOutput,
                'currency' => $caps->currency,
                'supports_thinking' => $caps->supportsThinking,
                'supports_vision' => $caps->supportsVision,
                'supports_function_calling' => $caps->supportsFunctionCalling,
                'supports_streaming' => $caps->supportsStreaming,
                'supports_top_k' => $caps->supportsTopK,
                'supports_response_schema' => $caps->supportsResponseSchema,
                'max_input_tokens' => $caps->maxInputTokens,
                'max_output_tokens' => $caps->maxOutputTokens,
                'rgpd_risk' => $caps->rgpdRisk,
                'provider_regions' => $caps->providerRegions,
            ];
        }

        return (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function buildLlmPrompt(string $candidatesJson, ?string $description): string
    {
        $userContext = '';
        if (null !== $description && '' !== $description) {
            $userContext = sprintf(
                "\n\n## Besoin de l'utilisateur\n%s\n",
                $description,
            );
        }

        return <<<PROMPT
Tu es un expert en configuration de modèles LLM. Tu dois recommander le meilleur preset pour un système de chat.

## Modèles candidats disponibles
```json
{$candidatesJson}
```
{$userContext}
## Consignes
- Choisis UN modèle parmi les candidats ci-dessus.
- Recommande des paramètres de génération adaptés au modèle choisi et au besoin de l'utilisateur.
- temperature : entre 0.0 et 2.0 (plus bas = plus déterministe, plus haut = plus créatif)
- topP : entre 0.0 et 1.0 (nucleus sampling)
- topK : entier positif si le modèle le supporte (supports_top_k=true), sinon 0
- maxOutputTokens : 0 pour laisser le défaut du modèle, ou un entier adapté
- streaming : true sauf si le modèle ne le supporte pas
- Si le modèle supporte le thinking, active-le avec un budget raisonnable (25% du max_output_tokens)
- Si l'utilisateur n'a pas exprimé de besoin spécifique, privilégie un modèle équilibré (range=balanced)

## RGPD et Cloud Act — CRITIQUE
Le champ rgpd_risk dans les candidats a cette sémantique :
- null = Société européenne, hébergement UE, pas de Cloud Act. Le plus sûr pour les données sensibles.
- "tolerated" = Société US (Cloud Act applicable) mais régions UE disponibles et DPA possible. Acceptable sous conditions strictes mais risque juridique résiduel lié au Cloud Act.
- "risk" = Société US, hébergement hors UE, protection adéquate mais risque élevé.
- "danger" = INTERDIT pour toute donnée personnelle (ex: modèle gratuit qui entraîne sur les données).

CONTEXTE JURIDIQUE : Le Cloud Act américain permet aux autorités US d'exiger l'accès aux données hébergées par une société US, même si les serveurs sont en UE. Cela concerne Anthropic, Google, OpenAI, etc. Seuls les providers européens (ex: OVH, Mistral) échappent au Cloud Act.

Si l'utilisateur mentionne des données sensibles, des mineurs, des données de santé ou toute donnée à caractère personnel :
- PRIVILÉGIE FORTEMENT les modèles avec rgpd_risk=null (providers européens)
- Si aucun modèle européen n'est disponible, recommande un modèle "tolerated" en signalant clairement le risque Cloud Act dans ta justification
- Ne recommande JAMAIS un modèle avec rgpd_risk="danger" ou "risk"
- Signale TOUJOURS dans ta justification les implications juridiques du choix

Retourne ta recommandation au format JSON structuré.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function getResponseSchema(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'preset_recommendation',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'model' => ['type' => 'string', 'description' => 'ID exact du modèle choisi'],
                        'provider' => ['type' => 'string', 'description' => 'Slug du provider'],
                        'temperature' => ['type' => 'number', 'description' => 'Température recommandée (0.0 à 2.0)'],
                        'topP' => ['type' => 'number', 'description' => 'Top-P recommandé (0.0 à 1.0)'],
                        'topK' => ['type' => 'integer', 'description' => 'Top-K (0 si non applicable)'],
                        'maxOutputTokens' => ['type' => 'integer', 'description' => 'Max output tokens (0 pour laisser le défaut)'],
                        'streamingEnabled' => ['type' => 'boolean', 'description' => 'Activer le streaming'],
                        'enableThinking' => ['type' => 'boolean', 'description' => 'Activer le thinking si supporté'],
                        'thinkingBudget' => ['type' => 'integer', 'description' => 'Budget thinking tokens (0 si thinking désactivé)'],
                        'justification' => ['type' => 'string', 'description' => 'Justification du choix en français'],
                    ],
                    'required' => ['model', 'provider', 'temperature', 'topP', 'topK', 'maxOutputTokens', 'streamingEnabled', 'enableThinking', 'thinkingBudget', 'justification'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $output
     * @param list<array{modelId: string, provider: string, providerLabel: string, capabilities: \ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities}> $candidates
     */
    private function buildRecommendationFromLlmOutput(array $output, array $candidates): PresetRecommendation
    {
        $modelId = (string) ($output['model'] ?? '');
        $providerName = (string) ($output['provider'] ?? '');

        // Retrouver les capabilities du modèle choisi
        $chosen = null;
        foreach ($candidates as $c) {
            if ($c['modelId'] === $modelId && $c['provider'] === $providerName) {
                $chosen = $c;
                break;
            }
        }

        if (null === $chosen) {
            throw new \RuntimeException(sprintf('Le LLM a choisi un modèle inconnu : %s/%s', $providerName, $modelId));
        }

        $caps = $chosen['capabilities'];

        // Construire les provider options pour le thinking
        $providerOptions = null;
        if ((bool) ($output['enableThinking'] ?? false) && $caps->supportsThinking) {
            $budget = is_numeric($output['thinkingBudget'] ?? null) ? (int) $output['thinkingBudget'] : 0;
            if ($budget > 0) {
                $providerOptions = [
                    'thinking' => [
                        'type' => 'enabled',
                        'budget_tokens' => $budget,
                    ],
                ];
            }
        }

        // 0 = "pas de valeur" dans le schema (les types nullable ne sont pas supportés partout)
        $topKRaw = is_numeric($output['topK'] ?? null) ? (int) $output['topK'] : 0;
        $maxOutputRaw = is_numeric($output['maxOutputTokens'] ?? null) ? (int) $output['maxOutputTokens'] : 0;

        $suggestedName = sprintf('%s — %s (%s)', $chosen['providerLabel'], $modelId, $caps->range->label());
        $suggestedKey = $this->slugify($providerName.'_'.$modelId);

        return new PresetRecommendation(
            provider: $providerName,
            model: $modelId,
            suggestedName: $suggestedName,
            suggestedKey: $suggestedKey,
            temperature: (float) ($output['temperature'] ?? $caps->range->defaultTemperature()),
            topP: (float) ($output['topP'] ?? 0.95),
            topK: $caps->supportsTopK ? ($topKRaw > 0 ? $topKRaw : 40) : null,
            maxOutputTokens: $maxOutputRaw > 0 ? $maxOutputRaw : null,
            streamingEnabled: (bool) ($output['streamingEnabled'] ?? $caps->supportsStreaming),
            providerOptions: $providerOptions,
            range: $caps->range,
            rgpdRisk: $caps->rgpdRisk,
            justification: (string) ($output['justification'] ?? 'Recommandation LLM sans justification.'),
            llmAssisted: true,
        );
    }

    private function slugify(string $text): string
    {
        $text = strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9]+/', '_', $text);

        return trim($text, '_');
    }
}
