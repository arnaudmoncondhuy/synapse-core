<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ToolAvailability;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Trait\TimestampableEntityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseToolConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Configuration administrative d'un outil (Tool) enregistré via DI.
 *
 * Les outils sont définis en code (tag `synapse.tool`) mais l'admin peut
 * contrôler leur disponibilité runtime via cette entité. La table est
 * synchronisée avec le {@see \ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry}
 * par {@see \ArnaudMoncondhuy\SynapseCore\Engine\ToolConfigService::sync()} :
 * - les outils présents en code mais pas en base sont créés avec ACTIVE par défaut ;
 * - les outils présents en base mais disparus du code sont supprimés.
 */
#[ORM\Entity(repositoryClass: SynapseToolConfigRepository::class)]
#[ORM\Table(name: 'synapse_tool_config')]
#[ORM\HasLifecycleCallbacks]
class SynapseToolConfig
{
    use TimestampableEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Nom technique de l'outil (doit correspondre à AiToolInterface::getName()).
     */
    #[ORM\Column(type: Types::STRING, length: 100, unique: true)]
    private string $toolName = '';

    /**
     * Niveau de disponibilité administratif.
     */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: ToolAvailability::class)]
    private ToolAvailability $availability = ToolAvailability::ACTIVE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $toolName = '', ToolAvailability $availability = ToolAvailability::ACTIVE)
    {
        $this->toolName = $toolName;
        $this->availability = $availability;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function setToolName(string $toolName): self
    {
        $this->toolName = $toolName;

        return $this;
    }

    public function getAvailability(): ToolAvailability
    {
        return $this->availability;
    }

    public function setAvailability(ToolAvailability $availability): self
    {
        $this->availability = $availability;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
