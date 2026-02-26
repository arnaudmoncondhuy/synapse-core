<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Accounting;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseTokenUsage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service de tracking centralisé des tokens IA
 *
 * Permet de logger la consommation de tokens pour toutes les fonctionnalités
 * IA de l'application (pas seulement les conversations).
 *
 * Les conversations (chat) sont trackées via SynapseMessage.tokens,
 * ce service est pour les tâches automatisées et agrégations.
 */
class TokenAccountingService
{
    public function __construct(
        private \ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository $modelRepo,
        private EntityManagerInterface $em
    ) {}

    /**
     * Log l'usage de tokens pour une action IA
     *
     * @param string $module Module concerné (ex: 'gmail', 'calendar', 'summarization')
     * @param string $action Action spécifique (ex: 'email_draft', 'event_suggestion')
     * @param string $model Modèle IA utilisé (ex: 'gemini-2.5-flash')
     * @param array $usage Usage détaillé ['prompt' => int, 'completion' => int, 'thinking' => int]
     * @param string|int|null $userId ID de l'utilisateur concerné (nullable pour tâches système)
     * @param string|null $conversationId ID de la conversation concernée (si applicable)
     * @param array|null $metadata Métadonnées additionnelles (coût, durée, etc.)
     */
    public function logUsage(
        string $module,
        string $action,
        string $model,
        array $usage,
        string|int|null $userId = null,
        ?string $conversationId = null,
        ?array $metadata = null
    ): void {
        $tokenUsage = new SynapseTokenUsage();
        $tokenUsage->setModule($module);
        $tokenUsage->setAction($action);
        $tokenUsage->setModel($model);

        // Récupérer le tarif actuel pour ce modèle
        $pricingMap = $this->modelRepo->findAllPricingMap();
        $modelPricing = $pricingMap[$model] ?? ['input' => 0.0, 'output' => 0.0];

        // Tokens (format normalisé Synapse)
        $promptTokens     = $usage['prompt_tokens']     ?? 0;
        $completionTokens = $usage['completion_tokens'] ?? 0;
        $thinkingTokens   = $usage['thinking_tokens']   ?? 0;

        $tokenUsage->setPromptTokens($promptTokens);
        $tokenUsage->setCompletionTokens($completionTokens);
        $tokenUsage->setThinkingTokens($thinkingTokens);
        $tokenUsage->calculateTotalTokens();

        // User ID (convertir en string pour uniformiser)
        if ($userId !== null) {
            $tokenUsage->setUserId((string) $userId);
        }

        // SynapseConversation ID
        if ($conversationId !== null) {
            $tokenUsage->setConversationId($conversationId);
        }

        // Métadonnées
        if ($metadata === null) {
            $metadata = [];
        }

        // Calculer et stocker le coût
        $currentUsage = [
            'prompt_tokens'     => $promptTokens,
            'completion_tokens' => $completionTokens,
            'thinking_tokens'   => $thinkingTokens,
        ];
        $metadata['cost'] = $this->calculateCost($currentUsage, $modelPricing);
        $metadata['pricing'] = $modelPricing; // Stocker le tarif utilisé pour l'historique

        $tokenUsage->setMetadata($metadata);

        $this->em->persist($tokenUsage);
        $this->em->flush();
    }

    /**
     * Calcule le coût estimé d'un usage
     *
     * @param array $usage Usage détaillé ['prompt_tokens' => int, 'completion_tokens' => int, 'thinking_tokens' => int]
     * @param array $pricing Tarifs ['input' => float, 'output' => float] ($/1M tokens)
     * @return float Coût en dollars
     */
    public function calculateCost(array $usage, array $pricing): float
    {
        $promptTokens     = $usage['prompt_tokens']     ?? 0;
        $completionTokens = $usage['completion_tokens'] ?? 0;
        $thinkingTokens   = $usage['thinking_tokens']   ?? 0;

        $inputCost = ($promptTokens / 1_000_000) * ($pricing['input'] ?? 0);
        $outputCost = (($completionTokens + $thinkingTokens) / 1_000_000) * ($pricing['output'] ?? 0);

        return round($inputCost + $outputCost, 6);
    }
}
