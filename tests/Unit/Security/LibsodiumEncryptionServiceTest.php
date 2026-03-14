<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Security;

use ArnaudMoncondhuy\SynapseCore\Security\LibsodiumEncryptionService;
use PHPUnit\Framework\TestCase;

class LibsodiumEncryptionServiceTest extends TestCase
{
    private LibsodiumEncryptionService $service;

    protected function setUp(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('Extension libsodium non disponible');
        }

        $this->service = new LibsodiumEncryptionService('une-cle-de-test-suffisamment-longue-pour-sha256');
    }

    // -------------------------------------------------------------------------
    // Chiffrement / Déchiffrement
    // -------------------------------------------------------------------------

    public function testEncryptProducesBase64String(): void
    {
        $encrypted = $this->service->encrypt('secret');

        $this->assertNotEmpty($encrypted);
        $this->assertNotSame('secret', $encrypted);
        $this->assertNotFalse(base64_decode($encrypted, true), 'Le résultat doit être du base64 valide');
    }

    public function testDecryptRestoresOriginalValue(): void
    {
        $original = 'ma valeur secrète';
        $encrypted = $this->service->encrypt($original);

        $this->assertSame($original, $this->service->decrypt($encrypted));
    }

    public function testEncryptProducesUniqueNonceEachTime(): void
    {
        $a = $this->service->encrypt('même texte');
        $b = $this->service->encrypt('même texte');

        // Les nonces étant aléatoires, les deux ciphertexts doivent être différents
        $this->assertNotSame($a, $b);
    }

    public function testEncryptDecryptRoundTripWithSpecialChars(): void
    {
        $text = "Ligne 1\nLigne 2\tTabulation\0NullByte";
        $this->assertSame($text, $this->service->decrypt($this->service->encrypt($text)));
    }

    public function testEncryptDecryptRoundTripWithLongText(): void
    {
        $text = str_repeat('A', 10000);
        $this->assertSame($text, $this->service->decrypt($this->service->encrypt($text)));
    }

    // -------------------------------------------------------------------------
    // Erreurs attendues
    // -------------------------------------------------------------------------

    public function testEncryptThrowsOnEmptyString(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->encrypt('');
    }

    public function testDecryptThrowsOnEmptyString(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->decrypt('');
    }

    public function testDecryptThrowsOnInvalidBase64(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->decrypt('!!!pas-du-base64!!!');
    }

    public function testDecryptThrowsOnWrongKey(): void
    {
        $encrypted = $this->service->encrypt('secret');

        $otherService = new LibsodiumEncryptionService('une-autre-cle-completement-differente');

        $this->expectException(\RuntimeException::class);
        $otherService->decrypt($encrypted);
    }

    public function testDecryptThrowsOnTruncatedCiphertext(): void
    {
        // Un ciphertext trop court (inférieur à nonce + mac)
        $tooShort = base64_encode(str_repeat("\x00", 5));

        $this->expectException(\RuntimeException::class);
        $this->service->decrypt($tooShort);
    }

    // -------------------------------------------------------------------------
    // isEncrypted()
    // -------------------------------------------------------------------------

    public function testIsEncryptedReturnsTrueForEncryptedValue(): void
    {
        $encrypted = $this->service->encrypt('valeur');
        $this->assertTrue($this->service->isEncrypted($encrypted));
    }

    public function testIsEncryptedReturnsFalseForPlainText(): void
    {
        $this->assertFalse($this->service->isEncrypted('plaintext'));
    }

    public function testIsEncryptedReturnsFalseForShortBase64(): void
    {
        // base64 valide mais trop court pour être un ciphertext (nonce=24 + mac=16 + 1)
        $short = base64_encode(str_repeat('x', 10));
        $this->assertFalse($this->service->isEncrypted($short));
    }

    // -------------------------------------------------------------------------
    // Constructeur — clé au format "base64:..."
    // -------------------------------------------------------------------------

    public function testAcceptsBase64PrefixedKey(): void
    {
        $rawKey = random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $prefixedKey = 'base64:'.base64_encode($rawKey);

        $service = new LibsodiumEncryptionService($prefixedKey);
        $encrypted = $service->encrypt('test');

        $this->assertSame('test', $service->decrypt($encrypted));
    }

    public function testAcceptsExact32ByteKey(): void
    {
        $key = str_repeat('k', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES); // exactement 32 bytes

        $service = new LibsodiumEncryptionService($key);
        $encrypted = $service->encrypt('test');

        $this->assertSame('test', $service->decrypt($encrypted));
    }

    // -------------------------------------------------------------------------
    // generateKey()
    // -------------------------------------------------------------------------

    public function testGenerateKeyProducesValidBase64(): void
    {
        $key = LibsodiumEncryptionService::generateKey();

        $this->assertNotEmpty($key);
        $decoded = base64_decode($key, true);
        $this->assertNotFalse($decoded);
        $this->assertSame(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($decoded));
    }

    public function testGenerateKeyIsUniqueEachCall(): void
    {
        $this->assertNotSame(
            LibsodiumEncryptionService::generateKey(),
            LibsodiumEncryptionService::generateKey(),
        );
    }

    public function testGeneratedKeyCanBeUsedForEncryption(): void
    {
        $rawKey = LibsodiumEncryptionService::generateKey();
        $service = new LibsodiumEncryptionService('base64:'.$rawKey);

        $encrypted = $service->encrypt('bonjour');
        $this->assertSame('bonjour', $service->decrypt($encrypted));
    }
}
