<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Security;

use ArnaudMoncondhuy\SynapseCore\Security\NullEncryptionService;
use PHPUnit\Framework\TestCase;

class NullEncryptionServiceTest extends TestCase
{
    public function testEncryptReturnsPlaintext(): void
    {
        $service = new NullEncryptionService();

        $this->assertSame('my-secret', $service->encrypt('my-secret'));
    }

    public function testDecryptReturnsCiphertext(): void
    {
        $service = new NullEncryptionService();

        $this->assertSame('some-data', $service->decrypt('some-data'));
    }

    public function testIsEncryptedAlwaysReturnsFalse(): void
    {
        $service = new NullEncryptionService();

        $this->assertFalse($service->isEncrypted('anything'));
        $this->assertFalse($service->isEncrypted(''));
    }
}
