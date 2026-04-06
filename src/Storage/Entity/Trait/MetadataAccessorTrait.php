<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity\Trait;

/**
 * Accès clé/valeur au champ $metadata (array JSON).
 *
 * L'entité utilisatrice doit déclarer une propriété `$metadata` de type ?array.
 */
trait MetadataAccessorTrait
{
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function setMetadataValue(string $key, mixed $value): static
    {
        if (null === $this->metadata) {
            $this->metadata = [];
        }
        $this->metadata[$key] = $value;

        return $this;
    }
}
