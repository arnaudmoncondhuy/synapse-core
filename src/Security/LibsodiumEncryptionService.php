<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Security;

use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;

/**
 * Service de chiffrement basé sur libsodium
 *
 * Algorithme : sodium_crypto_secretbox (équivalent AES-256-GCM)
 * Format : base64(nonce + ciphertext)
 * Nonce : 24 bytes aléatoires (SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
 * Clé : 32 bytes (SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
 */
class LibsodiumEncryptionService implements EncryptionServiceInterface
{
    private const NONCE_LENGTH = \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES; // 24 bytes
    private const KEY_LENGTH = \SODIUM_CRYPTO_SECRETBOX_KEYBYTES; // 32 bytes

    private string $key;

    /**
     * @param string $key Clé de chiffrement (32 bytes ou dérivée via hash)
     * @throws \RuntimeException Si libsodium n'est pas disponible
     */
    public function __construct(string $key)
    {
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('Extension libsodium is required but not available');
        }

        // FIX: Support du format standard Symfony "base64:..."
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        // Si la clé n'a pas la bonne longueur, on la hash en SHA-256 (32 bytes)
        $this->key = mb_strlen($key, '8bit') === self::KEY_LENGTH
            ? $key
            : hash('sha256', $key, true);
    }

    public function encrypt(string $plaintext): string
    {
        if (empty($plaintext)) {
            throw new \RuntimeException('Cannot encrypt empty string');
        }

        try {
            // Générer un nonce aléatoire unique
            $nonce = random_bytes(self::NONCE_LENGTH);

            // Chiffrer
            $ciphertext = \sodium_crypto_secretbox($plaintext, $nonce, $this->key);

            // Format : base64(nonce + ciphertext)
            $encrypted = base64_encode($nonce . $ciphertext);

            // Nettoyer la mémoire
            \sodium_memzero($plaintext);

            return $encrypted;
        } catch (\Exception $e) {
            throw new \RuntimeException('Encryption failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function decrypt(string $ciphertext): string
    {
        if (empty($ciphertext)) {
            throw new \RuntimeException('Cannot decrypt empty string');
        }

        try {
            // Décoder base64
            $decoded = base64_decode($ciphertext, true);
            if ($decoded === false) {
                throw new \RuntimeException('Invalid base64 encoding');
            }

            // Extraire nonce (24 premiers bytes)
            $nonce = mb_substr($decoded, 0, self::NONCE_LENGTH, '8bit');
            $encrypted = mb_substr($decoded, self::NONCE_LENGTH, null, '8bit');

            // Déchiffrer
            $plaintext = \sodium_crypto_secretbox_open($encrypted, $nonce, $this->key);

            if ($plaintext === false) {
                throw new \RuntimeException('Decryption failed (invalid key or corrupted data)');
            }

            return $plaintext;
        } catch (\Exception $e) {
            throw new \RuntimeException('Decryption failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function isEncrypted(string $data): bool
    {
        // Vérifier si c'est du base64 valide
        if (base64_decode($data, true) === false) {
            return false;
        }

        $decoded = base64_decode($data);

        // Vérifier la longueur minimale (nonce + au moins 1 byte + MAC)
        // sodium_crypto_secretbox ajoute un MAC de 16 bytes
        $minLength = self::NONCE_LENGTH + 1 + \SODIUM_CRYPTO_SECRETBOX_MACBYTES;

        return mb_strlen($decoded, '8bit') >= $minLength;
    }

    /**
     * Génère une nouvelle clé de chiffrement aléatoire
     *
     * @return string Clé de 32 bytes en base64
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(self::KEY_LENGTH));
    }
}
