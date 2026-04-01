<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\RgpdInfo;

/**
 * Interface optionnelle pour les clients LLM capables d'évaluer leur conformité RGPD.
 *
 * La méthode est stateless : elle ne lit pas la base de données, elle reçoit
 * les credentials et les options du preset en paramètre.
 */
interface RgpdAwareInterface
{
    /**
     * Évalue la conformité RGPD pour la configuration donnée.
     *
     * @param array<string, mixed> $providerCredentials Credentials du provider (ex: ['region' => 'europe-west9'])
     * @param array<string, mixed> $presetOptions Options du preset (ex: ['vertex_region' => 'us-central1'])
     * @param string $model Identifiant du modèle utilisé
     */
    public function getRgpdInfo(array $providerCredentials, array $presetOptions, string $model): RgpdInfo;
}
