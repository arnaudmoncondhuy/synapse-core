<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Security;

use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;

/**
 * Service de chiffrement désactivé (pass-through)
 *
 * Utilisé quand le chiffrement est désactivé dans la configuration.
 * Ne fait aucune transformation des données.
 */
class NullEncryptionService implements EncryptionServiceInterface
{
    public function encrypt(string $plaintext): string
    {
        return $plaintext;
    }

    public function decrypt(string $ciphertext): string
    {
        return $ciphertext;
    }

    public function isEncrypted(string $data): bool
    {
        return false;
    }
}
