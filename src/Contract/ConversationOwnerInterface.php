<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

/**
 * Interface pour le propriétaire d'une conversation.
 *
 * Permet au bundle d'être agnostique vis-à-vis de l'entité User de votre projet.
 * Chaque projet Symfony utilisant ce bundle doit faire implémenter cette interface à son entité User
 * (ou toute entité capable de posséder une conversation).
 *
 * @example
 * ```php
 * namespace App\Entity;
 *
 * use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
 * use Doctrine\ORM\Mapping as ORM;
 *
 * #[ORM\Entity]
 * class User implements ConversationOwnerInterface
 * {
 *     // ...
 *
 *     public function getId(): ?int
 *     {
 *         return $this->id;
 *     }
 *
 *     public function getIdentifier(): string
 *     {
 *         return $this->email;
 *     }
 * }
 * ```
 */
interface ConversationOwnerInterface
{
    /**
     * Retourne l'identifiant technique unique du propriétaire.
     *
     * Cet identifiant sera utilisé pour lier les conversations en base de données via une relation ManyToOne.
     *
     * @return int|string|null L'ID du propriétaire (généralement son ID de base de données)
     */
    public function getId(): int|string|null;

    /**
     * Retourne un identifiant humainement lisible du propriétaire.
     *
     * Cet identifiant est utilisé principalement pour l'affichage dans l'interface d'administration
     * de Synapse, les logs d'audit et le suivi de consommation.
     *
     * @return string Un identifiant tel que l'email, le pseudo ou le nom complet.
     */
    public function getIdentifier(): string;
}
