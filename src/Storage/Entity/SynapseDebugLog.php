<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;

/**
 * Journaux de debug persistants
 *
 * Stocke les traces d'exécution des appels LLM (requête, réponse, paramètres effectivement envoyés).
 */
#[ORM\Entity(repositoryClass: SynapseDebugLogRepository::class)]
#[ORM\Table(name: 'synapse_debug_log')]
class SynapseDebugLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Identifiant unique du debug (généré lors de l'appel LLM)
     */
    #[ORM\Column(type: Types::STRING, length: 50, unique: true)]
    private string $debugId;

    /**
     * ID de la conversation (optionnel, pour lier les appels à une conversation)
     */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $conversationId = null;

    /**
     * Timestamp de création
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Données du debug (JSON)
     *
     * Contient :
     * - preset_config : paramètres effectivement envoyés au LLM
     * - raw_request_body : body brut de la requête HTTP
     * - history : historique des messages
     * - usage : tokens consommés
     * - safety_ratings : résultats des contrôles de sécurité
     * - turns : détails de chaque tour de conversation
     * - tool_executions : exécutions d'outils (si applicable)
     */
    #[ORM\Column(type: Types::JSON)]
    private array $data = [];

    // Getters et Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDebugId(): string
    {
        return $this->debugId;
    }

    public function setDebugId(string $debugId): self
    {
        $this->debugId = $debugId;
        return $this;
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    public function setConversationId(?string $conversationId): self
    {
        $this->conversationId = $conversationId;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }
}
