<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseTokenUsage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité SynapseTokenUsage
 *
 * Fournit des requêtes d'analytics avancées avec agrégation
 * des données de SynapseTokenUsage (tâches automatisées) + SynapseMessage (conversations).
 *
 * @extends ServiceEntityRepository<SynapseTokenUsage>
 */
class SynapseTokenUsageRepository extends ServiceEntityRepository
{
    private string $messageTableName;

    public function __construct(ManagerRegistry $registry, string $messageTableName = 'synapse_message')
    {
        parent::__construct($registry, SynapseTokenUsage::class);
        $this->messageTableName = $messageTableName;
    }

    /**
     * Configure le nom de la table des messages (pour override)
     *
     * Utile si le projet utilise un nom de table différent.
     *
     * @param string $tableName Nom de la table des messages
     */
    public function setMessageTableName(string $tableName): void
    {
        $this->messageTableName = $tableName;
    }

    /**
     * Récupère les statistiques globales d'usage (SynapseTokenUsage + SynapseMessage)
     *
     * @param \DateTimeInterface $start Date de début
     * @param \DateTimeInterface $end Date de fin
     * @return array{request_count: int, prompt_tokens: int, completion_tokens: int, thinking_tokens: int, total_tokens: int, cost: float}
     */
    public function getGlobalStats(\DateTimeInterface $start, \DateTimeInterface $end, array $pricing = []): array
    {
        $connection = $this->getEntityManager()->getConnection();

        $isPostgreSql = str_contains($connection->getDatabasePlatform()::class, 'PostgreSQL');
        $modelExpression = $isPostgreSql ? "metadata->>'model'" : "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.model'))";

        // Requête UNION ALL pour agréger SynapseTokenUsage + SynapseMessage
        $sql = <<<SQL
            SELECT
                prompt_tokens,
                completion_tokens,
                thinking_tokens,
                total_tokens,
                model,
                metadata
            FROM (
                SELECT
                    prompt_tokens,
                    completion_tokens,
                    thinking_tokens,
                    total_tokens,
                    model,
                    metadata
                FROM synapse_token_usage
                WHERE created_at >= :start AND created_at <= :end

                UNION ALL

                SELECT
                    COALESCE(prompt_tokens, 0) as prompt_tokens,
                    COALESCE(completion_tokens, 0) as completion_tokens,
                    COALESCE(thinking_tokens, 0) as thinking_tokens,
                    COALESCE(total_tokens, 0) as total_tokens,
                    $modelExpression as model,
                    metadata
                FROM {$this->messageTableName}
                WHERE created_at >= :start AND created_at <= :end
            ) AS combined
        SQL;

        $results = $connection->executeQuery($sql, [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        $stats = [
            'request_count' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'thinking_tokens' => 0,
            'total_tokens' => 0,
            'cost' => 0.0,
        ];

        foreach ($results as $row) {
            $stats['request_count']++;
            $stats['prompt_tokens'] += (int) $row['prompt_tokens'];
            $stats['completion_tokens'] += (int) $row['completion_tokens'];
            $stats['thinking_tokens'] += (int) $row['thinking_tokens'];
            $stats['total_tokens'] += (int) $row['total_tokens'];

            $metadata = json_decode($row['metadata'] ?? '{}', true);
            if (isset($metadata['cost'])) {
                $stats['cost'] += (float) $metadata['cost'];
            } else {
                // Fallback: calcul basé sur pricing fourni ou défaut
                $model = $row['model'] ?? 'default';
                $rowPricing = $pricing[$model] ?? ($pricing['default'] ?? ['input' => 0.30, 'output' => 2.50]);
                $stats['cost'] += $this->calculateCost($row, [
                    'default' => $rowPricing
                ]);
            }
        }

        return $stats;
    }

    /**
     * Récupère les statistiques des tâches automatisées uniquement
     *
     * @param \DateTimeInterface $start Date de début
     * @param \DateTimeInterface $end Date de fin
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
                'module' => $result['module'],
                'action' => $result['action'],
                'model' => $result['model'],
                'count' => (int) $result['count'],
                'prompt_tokens' => (int) $result['prompt_tokens'],
                'completion_tokens' => (int) $result['completion_tokens'],
                'thinking_tokens' => (int) $result['thinking_tokens'],
                'total_tokens' => (int) $result['total_tokens'],
            ];
        }

        return $stats;
    }

    /**
     * Récupère les statistiques des conversations uniquement
     *
     * @param \DateTimeInterface $start Date de début
     * @param \DateTimeInterface $end Date de fin
     * @return array{count: int, prompt_tokens: int, completion_tokens: int, thinking_tokens: int, total_tokens: int}
     */
    public function getConversationStats(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $connection = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            SELECT
                COUNT(*) as count,
                COALESCE(SUM(prompt_tokens), 0) as prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) as completion_tokens,
                COALESCE(SUM(thinking_tokens), 0) as thinking_tokens,
                COALESCE(SUM(total_tokens), 0) as total_tokens
            FROM {$this->messageTableName}
            WHERE created_at >= :start AND created_at <= :end
        SQL;

        $result = $connection->executeQuery($sql, [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ])->fetchAssociative();

        return [
            'count' => (int) $result['count'],
            'prompt_tokens' => (int) $result['prompt_tokens'],
            'completion_tokens' => (int) $result['completion_tokens'],
            'thinking_tokens' => (int) $result['thinking_tokens'],
            'total_tokens' => (int) $result['total_tokens'],
        ];
    }

    /**
     * Récupère l'usage quotidien (série temporelle)
     *
     * @param \DateTimeInterface $start Date de début
     * @param \DateTimeInterface $end Date de fin
     * @return array<string, array> Usage par jour
     */
    public function getDailyUsage(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $connection = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            SELECT
                DATE(created_at) as date,
                COALESCE(SUM(prompt_tokens), 0) as prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) as completion_tokens,
                COALESCE(SUM(thinking_tokens), 0) as thinking_tokens,
                COALESCE(SUM(total_tokens), 0) as total_tokens
            FROM (
                SELECT created_at, prompt_tokens, completion_tokens, thinking_tokens, total_tokens
                FROM synapse_token_usage
                WHERE created_at >= :start AND created_at <= :end

                UNION ALL

                SELECT created_at,
                       COALESCE(prompt_tokens, 0),
                       COALESCE(completion_tokens, 0),
                       COALESCE(thinking_tokens, 0),
                       COALESCE(total_tokens, 0)
                FROM {$this->messageTableName}
                WHERE created_at >= :start AND created_at <= :end
            ) AS combined
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        SQL;

        $results = $connection->executeQuery($sql, [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        $daily = [];
        foreach ($results as $result) {
            // Conserver la date comme clé du tableau ET l'exposer dans la valeur
            // afin que les templates Twig puissent accéder à "day.date".
            $daily[$result['date']] = [
                'date' => $result['date'],
                'prompt_tokens' => (int) $result['prompt_tokens'],
                'completion_tokens' => (int) $result['completion_tokens'],
                'thinking_tokens' => (int) $result['thinking_tokens'],
                'total_tokens' => (int) $result['total_tokens'],
            ];
        }

        return $daily;
    }

    /**
     * Récupère l'usage par module
     *
     * @param \DateTimeInterface $start Date de début
     * @param \DateTimeInterface $end Date de fin
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
                'count' => (int) $result['count'],
                'total_tokens' => (int) $result['total_tokens'],
            ];
        }

        // Ajouter les conversations comme module "chat"
        $conversationStats = $this->getConversationStats($start, $end);
        $usage['chat'] = [
            'count' => $conversationStats['count'],
            'total_tokens' => $conversationStats['total_tokens'],
        ];

        return $usage;
    }

    /**
     * Récupère l'usage par modèle
     *
     * @param \DateTimeInterface $start Date de début
     * @param \DateTimeInterface $end Date de fin
     * @return array<string, array> Usage par modèle
     */
    public function getUsageByModel(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $isPostgreSql = str_contains($connection->getDatabasePlatform()::class, 'PostgreSQL');
        $modelExpression = $isPostgreSql ? "metadata->>'model'" : "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.model'))";

        $sql = <<<SQL
            SELECT
                model,
                COUNT(*) as count,
                COALESCE(SUM(total_tokens), 0) as total_tokens
            FROM (
                SELECT total_tokens, model
                FROM synapse_token_usage
                WHERE created_at >= :start AND created_at <= :end

                UNION ALL

                SELECT
                    COALESCE(total_tokens, 0) as total_tokens,
                    $modelExpression as model
                FROM {$this->messageTableName}
                WHERE created_at >= :start AND created_at <= :end
            ) AS combined
            WHERE model IS NOT NULL
            GROUP BY model
            ORDER BY total_tokens DESC
        SQL;

        $results = $connection->executeQuery($sql, [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        $usage = [];
        foreach ($results as $result) {
            $usage[$result['model']] = [
                'count' => (int) $result['count'],
                'total_tokens' => (int) $result['total_tokens'],
            ];
        }

        return $usage;
    }

    /**
     * Calcule le coût estimé
     *
     * @param array $stats Statistiques de tokens
     * @param array $pricing Tarifs par modèle ($/1M tokens)
     * @return float Coût en dollars
     */
    private function calculateCost(array $stats, array $pricing): float
    {
        $defaultPricing = $pricing['default'] ?? ['input' => 0.30, 'output' => 2.50];

        $inputCost = ($stats['prompt_tokens'] / 1_000_000) * $defaultPricing['input'];
        // Version finale : Output = Completion (texte) + Thinking (réflexion)
        $outputCost = (($stats['completion_tokens'] + ($stats['thinking_tokens'] ?? 0)) / 1_000_000) * $defaultPricing['output'];

        return round($inputCost + $outputCost, 6);
    }
}
