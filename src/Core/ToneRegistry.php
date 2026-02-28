<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseTone;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseToneRepository;

/**
 * Registre des tons de réponse de l'IA.
 *
 * Un "ton" définit le style de communication du LLM : registre de langue,
 * format de réponse, posture, niveau de formalité.
 * Il n'affecte pas la capacité de raisonnement, uniquement la formulation.
 *
 * Les tons sont stockés en base de données et gérables depuis l'admin Synapse.
 */
class ToneRegistry
{
    public function __construct(
        private SynapseToneRepository $repository,
    ) {}

    /**
     * Retourne tous les tons actifs, indexés par clé slug.
     *
     * @return array<string, array> tableau associatif indexé par la clé du ton
     */
    public function getAll(): array
    {
        $tones = [];
        foreach ($this->repository->findAllActive() as $tone) {
            $tones[$tone->getKey()] = $tone->toArray();
        }
        return $tones;
    }

    /**
     * Récupère l'entité d'un ton spécifique par sa clé.
     *
     * @param string $key la clé unique du ton (ex : 'zen')
     */
    public function get(string $key): ?SynapseTone
    {
        return $this->repository->findByKey($key);
    }

    /**
     * Récupère uniquement le prompt système associé à un ton.
     *
     * @param string $key la clé unique du ton
     *
     * @return string|null le prompt ou null si introuvable / inactif
     */
    public function getSystemPrompt(string $key): ?string
    {
        $tone = $this->repository->findByKey($key);

        if ($tone === null || !$tone->isActive()) {
            return null;
        }

        return $tone->getSystemPrompt();
    }
}
