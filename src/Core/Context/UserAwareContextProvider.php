<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Context;

use ArnaudMoncondhuy\SynapseCore\Contract\ContextProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Fournisseur de contexte avec support utilisateur
 *
 * Classe de base abstraite qui structure le prompt système en plusieurs parties:
 * - Identité de base (abstract - défini par le projet)
 * - Contexte de date/heure (générique)
 * - Contexte utilisateur (override possible)
 * - Instructions (override possible, support multilingue)
 *
 * Les projets étendent cette classe et implémentent getBaseIdentity() pour
 * définir leur identité spécifique tout en bénéficiant de la structure commune.
 */
abstract class UserAwareContextProvider implements ContextProviderInterface
{
    public function __construct(
        protected ?Security $security = null,
        protected string $language = 'fr'
    ) {
    }

    /**
     * Construit le prompt système complet en assemblant les différentes parties
     */
    final public function getSystemPrompt(): string
    {
        $parts = [
            $this->getBaseIdentity(),
            $this->getDateContext(),
        ];

        $user = $this->security?->getUser();
        if ($user instanceof ConversationOwnerInterface) {
            $parts[] = $this->getUserContext($user);
        }

        $parts[] = $this->getInstructions();

        return implode("\n\n", array_filter($parts));
    }

    /**
     * Fournit le contexte initial pour la conversation
     */
    final public function getInitialContext(): array
    {
        $context = [
            'date' => (new \DateTimeImmutable())->format('d/m/Y'),
            'time' => (new \DateTimeImmutable())->format('H:i'),
        ];

        $user = $this->security?->getUser();
        if ($user instanceof ConversationOwnerInterface) {
            $context['user'] = $this->extractUserData($user);
        }

        return $context;
    }

    /**
     * Identité de base de l'assistant (à implémenter par le projet)
     *
     * Exemple: "Tu es un assistant virtuel pour [nom de l'application]."
     */
    abstract protected function getBaseIdentity(): string;

    /**
     * Contexte de date/heure
     *
     * Override possible pour personnaliser le format
     */
    protected function getDateContext(): string
    {
        $now = new \DateTimeImmutable();
        return sprintf("Nous sommes le %s à %s",
            $now->format('d/m/Y'),
            $now->format('H:i')
        );
    }

    /**
     * Contexte utilisateur
     *
     * Par défaut affiche simplement l'identifiant.
     * Les projets peuvent override pour ajouter nom, rôle, etc.
     */
    protected function getUserContext(ConversationOwnerInterface $user): string
    {
        return "Utilisateur connecté : " . $user->getIdentifier();
    }

    /**
     * Instructions générales pour l'assistant
     *
     * Support multilingue intégré.
     * Override possible pour instructions personnalisées.
     */
    protected function getInstructions(): string
    {
        return match($this->language) {
            'fr' => <<<FR
            Instructions :
            - Réponds TOUJOURS en français
            - Sois concis mais complet
            - Si tu ne sais pas, dis-le clairement
            - Reste professionnel et bienveillant
            FR,
            'en' => <<<EN
            Instructions:
            - Always respond in English
            - Be concise but complete
            - If you don't know, say so clearly
            - Remain professional and helpful
            EN,
            default => ''
        };
    }

    /**
     * Extrait les données utilisateur pour le contexte initial
     *
     * Override possible pour ajouter plus de données.
     */
    protected function extractUserData(ConversationOwnerInterface $user): array
    {
        return [
            'identifier' => $user->getIdentifier(),
        ];
    }
}
