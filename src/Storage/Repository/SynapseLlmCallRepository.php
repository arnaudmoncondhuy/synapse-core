<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseLlmCall;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité SynapseLlmCall.
 *
 * Source unique de vérité pour le token accounting :
 * toutes les requêtes LLM (chat, title_generation, agents, tâches automatisées)
 * sont enregistrées ici via TokenAccountingService::logUsage().
 *
 * Les requêtes analytics ne font plus de UNION avec synapse_message :
 * synapse_message est réservé à l'affichage des messages, pas au comptage.
 *
 * @extends ServiceEntityRepository<SynapseLlmCall>
 */
class SynapseLlmCallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseLlmCall::class);
    }

    /**
     * Récupère les statistiques globales d'usage avec coûts par devise.
     *
     * @param \DateTimeInterface $start Date de début
     * @param \DateTimeInterface $end   Date de fin
     * @return array{request_count: int, prompt_tokens: int, completion_tokens: int, thinking_tokens: int, total_tokens: int, costs: array<string, float>}
     */
    public function getGlobalStats(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        // Requête pour totaux généraux
        $globalResult = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT COUNT(*) AS request_count,
                    COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens,
                    COALESCE(SUM(completion_tokens), 0) AS completion_tokens,
                    COALESCE(SUM(thinking_tokens), 0) AS thinking_tokens,
                    COALESCE(SUM(total_tokens), 0) AS total_tokens
             FROM synapse_llm_call
             WHERE created_at >= :start AND created_at <= :end',
            ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')]
        )->fetchAssociative();

        // Requête pour coûts par devise
        $costResults = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT pricing_currency AS currency, COALESCE(SUM(cost_model_currency), 0) AS cost
             FROM synapse_llm_call
             WHERE created_at >= :start AND created_at <= :end
             GROUP BY pricing_currency',
            ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')]
        )->fetchAllAssociative();

        $costs = [];
        foreach ($costResults as $row) {
            $costs[$row['currency']] = (float) $row['cost'];
        }

        return [
            'request_count'     => (int) ($globalResult['request_count'] ?? 0),
            'prompt_tokens'     => (int) ($globalResult['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($globalResult['completion_tokens'] ?? 0),
            'thinking_tokens'   => (int) ($globalResult['thinking_tokens'] ?? 0),
            'total_tokens'      => (int) ($globalResult['total_tokens'] ?? 0),
            'costs'             => $costs,  // array: 'EUR' => X, 'USD' => Y
        ];
    }

    /**
     * Récupère les statistiques des tâches automatisées (hors conversations)
     *
     * @param \DateTimeInterface $start Date de début
     * @param \DateTimeInterface $end   Date de fin
     * @return array<string, array> Stats par module/action
     */
    public function getAutomatedTaskStats(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $results = $this->createQueryBuilder('t')
            ->select(
                't.module',
                't.action',
                't.model',
                'COUNT(t.id) as count',
                'COALESCE(SUM(t.promptTokens), 0) as prompt_tokens',
                'COALESCE(SUM(t.completionTokens), 0) as completion_tokens',
                'COALESCE(SUM(t.thinkingTokens), 0) as thinking_tokens',
                'COALESCE(SUM(t.totalTokens), 0) as total_tokens'
            )
            ->where('t.createdAt >= :start')
            ->andWhere('t.createdAt <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('t.module, t.action, t.model')
            ->orderBy('total_tokens', 'DESC')
            ->getQuery()
            ->getResult();

        $stats = [];
        foreach ($results as $result) {
            $key = $result['module'] . ':' . $result['action'];
            $stats[$key] = [
                'module'            => $result['module'],
                'action'            => $result['action'],
                'model'             => $result['model'],
                'count'             => (int) $result['count'],
                'prompt_tokens'     => (int) $result['prompt_tokens'],
                'completion_tokens' => (int) $result['completion_tokens'],
                'thinking_tokens'   => (int) $result['thinking_tokens'],
                'total_tokens'      => (int) $result['total_tokens'],
            ];
        }

        return $stats;
    }

    /**
     * Récupère les statistiques des conversations (appels liés à une conversation)
     *
     * @param \DateTimeInterface $start Date de début
     * @param \DateTimeInterface $end   Date de fin
     * @return array{count: int, prompt_tokens: int, completion_tokens: int, thinking_tokens: int, total_tokens: int}
     */
    public function getConversationStats(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $result = $conn->executeQuery(
            'SELECT COUNT(*) as count,
                    COALESCE(SUM(prompt_tokens), 0) as prompt_tokens,
                    COALESCE(SUM(completion_tokens), 0) as completion_tokens,
                    COALESCE(SUM(thinking_tokens), 0) as thinking_tokens,
                    COALESCE(SUM(total_tokens), 0) as total_tokens
             FROM synapse_llm_call
             WHERE conversation_id IS NOT NULL AND created_at >= :start AND created_at <= :end',
            ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')]
        )->fetchAssociative();

        return [
            'count'             => (int) ($result['count'] ?? 0),
            'prompt_tokens'     => (int) ($result['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($result['completion_tokens'] ?? 0),
            'thinking_tokens'   => (int) ($result['thinking_tokens'] ?? 0),
            'total_tokens'      => (int) ($result['total_tokens'] ?? 0),
        ];
    }

    /**
     * Récupère l'usage quotidien (série temporelle)
     *
     * @param \DateTimeInterface $start Date de début
     * @param \DateTimeInterface $end   Date de fin
     * @return array<string, array> Usage par jour
     */
    public function getDailyUsage(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $results = $conn->executeQuery(
            'SELECT DATE(created_at) as date,
                    COALESCE(SUM(prompt_tokens), 0) as prompt_tokens,
                    COALESCE(SUM(completion_tokens), 0) as completion_tokens,
                    COALESCE(SUM(thinking_tokens), 0) as thinking_tokens,
                    COALESCE(SUM(total_tokens), 0) as total_tokens
             FROM synapse_llm_call
             WHERE created_at >= :start AND created_at <= :end
             GROUP BY DATE(created_at)
             ORDER BY date ASC',
            ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')]
        )->fetchAllAssociative();

        $daily = [];
        foreach ($results as $result) {
            $daily[$result['date']] = [
                'date'              => $result['date'],
                'prompt_tokens'     => (int) $result['prompt_tokens'],
                'completion_tokens' => (int) $result['completion_tokens'],
                'thinking_tokens'   => (int) $result['thinking_tokens'],
                'total_tokens'      => (int) $result['total_tokens'],
            ];
        }

        return $daily;
    }

    /**
     * Récupère l'usage par module
     *
     * @param \DateTimeInterface $start Date de début
     * @param \DateTimeInterface $end   Date de fin
     * @return array<string, array> Usage par module
     */
    public function getUsageByModule(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $results = $this->createQueryBuilder('t')
            ->select(
                't.module',
                'COUNT(t.id) as count',
                'COALESCE(SUM(t.totalTokens), 0) as total_tokens'
            )
            ->where('t.createdAt >= :start')
            ->andWhere('t.createdAt <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('t.module')
            ->orderBy('total_tokens', 'DESC')
            ->getQuery()
            ->getResult();

        $usage = [];
        foreach ($results as $result) {
            $usage[$result['module']] = [
                'count'        => (int) $result['count'],
                'total_tokens' => (int) $result['total_tokens'],
            ];
        }

        return $usage;
    }

    /**
     * Récupère l'usage par modèle (avec coûts et devise)
     *
     * @param \DateTimeInterface $start Date de début
     * @param \DateTimeInterface $end   Date de fin
     * @return array<string, array{count: int, total_tokens: int, cost: float, currency: string}> Usage par modèle
     */
    public function getUsageByModel(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $results = $conn->executeQuery(
            'SELECT model,
                    pricing_currency,
                    COUNT(*) as count,
                    COALESCE(SUM(total_tokens), 0) as total_tokens,
                    COALESCE(SUM(cost_reference), 0) as cost
             FROM synapse_llm_call
             WHERE created_at >= :start AND created_at <= :end
             GROUP BY model, pricing_currency
             ORDER BY total_tokens DESC',
            ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')]
        )->fetchAllAssociative();

        $usage = [];
        foreach ($results as $result) {
            $usage[$result['model']] = [
                'model_id' => (string) $result['model'],
                'count'    => (int) $result['count'],
                'total_tokens' => (int) $result['total_tokens'],
                'cost'     => (float) $result['cost'],
                'currency' => (string) $result['pricing_currency'],
            ];
        }

        return $usage;
    }

    /**
     * Usage agrégé par utilisateur (tokens + coût) sur une période.
     *
     * @return list<array{user_id: string, count: int, total_tokens: int, cost: float}>
     */
    public function getUsageByUser(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $results = $conn->executeQuery(
            'SELECT user_id, COUNT(*) AS cnt, COALESCE(SUM(total_tokens), 0) AS total_tokens, COALESCE(SUM(cost_reference), 0) AS cost
             FROM synapse_llm_call
             WHERE user_id IS NOT NULL AND created_at >= :start AND created_at <= :end
             GROUP BY user_id
             ORDER BY cost DESC, total_tokens DESC',
            ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')]
        )->fetchAllAssociative();

        return array_map(fn ($row) => [
            'user_id'      => (string) $row['user_id'],
            'count'        => (int) $row['cnt'],
            'total_tokens' => (int) $row['total_tokens'],
            'cost'         => (float) $row['cost'],
        ], $results);
    }

    /**
     * Usage agrégé par preset (tokens + coût) sur une période.
     *
     * @return list<array{preset_id: int, count: int, total_tokens: int, cost: float}>
     */
    public function getUsageByPreset(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $results = $conn->executeQuery(
            'SELECT preset_id, COUNT(*) AS cnt, COALESCE(SUM(total_tokens), 0) AS total_tokens, COALESCE(SUM(cost_reference), 0) AS cost
             FROM synapse_llm_call
             WHERE preset_id IS NOT NULL AND created_at >= :start AND created_at <= :end
             GROUP BY preset_id
             ORDER BY cost DESC, total_tokens DESC',
            ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')]
        )->fetchAllAssociative();

        return array_map(fn ($row) => [
            'preset_id'    => (int) $row['preset_id'],
            'count'        => (int) $row['cnt'],
            'total_tokens' => (int) $row['total_tokens'],
            'cost'         => (float) $row['cost'],
        ], $results);
    }

    /**
     * Usage agrégé par mission (tokens + coût) sur une période.
     *
     * @return list<array{mission_id: int, count: int, total_tokens: int, cost: float}>
     */
    public function getUsageByMission(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $results = $conn->executeQuery(
            'SELECT mission_id, COUNT(*) AS cnt, COALESCE(SUM(total_tokens), 0) AS total_tokens, COALESCE(SUM(cost_reference), 0) AS cost
             FROM synapse_llm_call
             WHERE mission_id IS NOT NULL AND created_at >= :start AND created_at <= :end
             GROUP BY mission_id
             ORDER BY cost DESC, total_tokens DESC',
            ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')]
        )->fetchAllAssociative();

        return array_map(fn ($row) => [
            'mission_id'   => (int) $row['mission_id'],
            'count'        => (int) $row['cnt'],
            'total_tokens' => (int) $row['total_tokens'],
            'cost'         => (float) $row['cost'],
        ], $results);
    }

    /**
     * Coût total d'une conversation (tous les appels LLM liés).
     *
     * Retourne le coût agrégé avec le détail par appel, permettant
     * le debug et l'audit complet d'une conversation multi-tour.
     *
     * @return array{
     *     conversation_id: string,
     *     turn_count: int,
     *     prompt_tokens: int,
     *     completion_tokens: int,
     *     thinking_tokens: int,
     *     total_tokens: int,
     *     cost: float,
     *     currency: string,
     *     turns: list<array{call_id: string, created_at: string, model: string, prompt_tokens: int, completion_tokens: int, thinking_tokens: int, total_tokens: int, cost: float, pricing_input: float|null, pricing_output: float|null}>
     * }
     */
    public function getConversationCost(string $conversationId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $rows = $conn->executeQuery(
            'SELECT call_id, created_at, model, prompt_tokens, completion_tokens, thinking_tokens, total_tokens,
                    COALESCE(cost_reference, 0) AS cost,
                    pricing_input, pricing_output, pricing_currency
             FROM synapse_llm_call
             WHERE conversation_id = :id
             ORDER BY created_at ASC',
            ['id' => $conversationId]
        )->fetchAllAssociative();

        $turns = [];
        $totalCost = 0.0;
        $totalPrompt = 0;
        $totalCompletion = 0;
        $totalThinking = 0;
        $totalTokens = 0;
        $currency = null;

        foreach ($rows as $row) {
            $cost = (float) $row['cost'];
            $totalCost += $cost;
            $totalPrompt += (int) $row['prompt_tokens'];
            $totalCompletion += (int) $row['completion_tokens'];
            $totalThinking += (int) $row['thinking_tokens'];
            $totalTokens += (int) $row['total_tokens'];
            $currency ??= $row['pricing_currency'];

            $turns[] = [
                'call_id'           => $row['call_id'],
                'created_at'        => $row['created_at'],
                'model'             => $row['model'],
                'prompt_tokens'     => (int) $row['prompt_tokens'],
                'completion_tokens' => (int) $row['completion_tokens'],
                'thinking_tokens'   => (int) $row['thinking_tokens'],
                'total_tokens'      => (int) $row['total_tokens'],
                'cost'              => $cost,
                'pricing_input'     => $row['pricing_input'] !== null ? (float) $row['pricing_input'] : null,
                'pricing_output'    => $row['pricing_output'] !== null ? (float) $row['pricing_output'] : null,
            ];
        }

        return [
            'conversation_id'   => $conversationId,
            'turn_count'        => count($turns),
            'prompt_tokens'     => $totalPrompt,
            'completion_tokens' => $totalCompletion,
            'thinking_tokens'   => $totalThinking,
            'total_tokens'      => $totalTokens,
            'cost'              => round($totalCost, 6),
            'currency'          => $currency ?? 'N/A',
            'turns'             => $turns,
        ];
    }

    /**
     * Consommation (coût en devise de référence) sur une fenêtre pour un scope user, preset ou mission.
     *
     * Utilisé par SpendingLimitChecker. Lit depuis la colonne cost_reference (snapshot immuable).
     *
     * @param 'user'|'preset'|'mission' $scope
     */
    public function getConsumptionForWindow(string $scope, string $scopeId, \DateTimeInterface $start, \DateTimeInterface $end): float
    {
        $conn = $this->getEntityManager()->getConnection();

        $column = match ($scope) {
            'user'    => 'user_id',
            'mission' => 'mission_id',
            default   => 'preset_id',
        };

        $result = $conn->executeQuery(
            "SELECT COALESCE(SUM(cost_reference), 0) AS total FROM synapse_llm_call WHERE $column = :scopeId AND created_at >= :start AND created_at <= :end",
            ['scopeId' => $scopeId, 'start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')]
        )->fetchAssociative();

        return (float) ($result['total'] ?? 0);
    }
}
