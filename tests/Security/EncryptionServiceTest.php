<?php

namespace App\Tests\Security;

use App\Security\EncryptionService;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class EncryptionServiceTest extends MockeryTestCase
{
    private function validKey(): string
    {
        return bin2hex(sodium_crypto_secretbox_keygen());
    }

    public function testEncryptAndDecrypt(): void
    {
        $service = new EncryptionService($this->validKey());
        $plaintext = 'super-secret-api-key-12345';

        $encrypted = $service->encrypt($plaintext);

        $this->assertNotSame($plaintext, $encrypted);
        $this->assertSame($plaintext, $service->decrypt($encrypted));
    }

    public function testEncryptAndDecryptEmptyString(): void
    {
        $service = new EncryptionService($this->validKey());

        $encrypted = $service->encrypt('');

        $this->assertSame('', $service->decrypt($encrypted));
    }

    public function testEncryptProducesDifferentCiphertextEachTime(): void
    {
        $service = new EncryptionService($this->validKey());
        $plaintext = 'same-value-twice';

        $encrypted1 = $service->encrypt($plaintext);
        $encrypted2 = $service->encrypt($plaintext);

        $this->assertNotSame($encrypted1, $encrypted2);
        $this->assertSame($plaintext, $service->decrypt($encrypted1));
        $this->assertSame($plaintext, $service->decrypt($encrypted2));
    }

    public function testDecryptWithWrongKeyThrows(): void
    {
        $service1 = new EncryptionService($this->validKey());
        $service2 = new EncryptionService($this->validKey());

        $encrypted = $service1->encrypt('secret');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');

        $service2->decrypt($encrypted);
    }

    public function testDecryptWithCorruptedDataThrows(): void
    {
        $service = new EncryptionService($this->validKey());

        $this->expectException(\RuntimeException::class);

        $service->decrypt('not-valid-base64-!@#$');
    }

    public function testDecryptWithTamperedCiphertextThrows(): void
    {
        $service = new EncryptionService($this->validKey());

        $encrypted = $service->encrypt('secret');
        // Extract the version prefix and base64 payload
        preg_match('/^(v\d+:)(.+)$/', $encrypted, $matches);
        $prefix = $matches[1];
        $decoded = base64_decode($matches[2], true);
        // Flip a byte in the ciphertext portion (after the nonce)
        $tampered = $decoded;
        $tampered[SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1] = chr(
            ord($tampered[SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1]) ^ 0xFF,
        );
        $reEncoded = $prefix.base64_encode($tampered);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');

        $service->decrypt($reEncoded);
    }

    public function testDecryptWithTooShortDataThrows(): void
    {
        $service = new EncryptionService($this->validKey());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too short');

        $service->decrypt('v1:'.base64_encode('short'));
    }

    public function testConstructorRejectsInvalidKeyLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Encryption key must be exactly 32 bytes',
        );

        new EncryptionService(bin2hex(random_bytes(16)));
    }

    public function testConstructorRejectsEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EncryptionService('');
    }

    public function testHandlesUtf8Content(): void
    {
        $service = new EncryptionService($this->validKey());
        $plaintext = '日本語テスト 🔐 émojis & spëcîal chars';

        $encrypted = $service->encrypt($plaintext);

        $this->assertSame($plaintext, $service->decrypt($encrypted));
    }

    public function testHandlesLargeContent(): void
    {
        $service = new EncryptionService($this->validKey());
        $plaintext = str_repeat('A', 100_000);

        $encrypted = $service->encrypt($plaintext);

        $this->assertSame($plaintext, $service->decrypt($encrypted));
    }

    public function testEncryptOutputIncludesVersionPrefix(): void
    {
        $service = new EncryptionService($this->validKey());

        $encrypted = $service->encrypt('hello');

        $this->assertMatchesRegularExpression("/^v\d+:/", $encrypted);
    }

    public function testGetCurrentVersionDefaultsToOne(): void
    {
        $service = new EncryptionService($this->validKey());

        $this->assertSame(1, $service->getCurrentVersion());
    }

    public function testGetCurrentVersionWithPreviousKeys(): void
    {
        $key1 = $this->validKey();
        $key2 = $this->validKey();
        $currentKey = $this->validKey();

        $service = new EncryptionService($currentKey, "1:{$key1},2:{$key2}");

        $this->assertSame(3, $service->getCurrentVersion());
    }

    public function testDecryptWithPreviousKeyVersion(): void
    {
        $oldKey = $this->validKey();
        $newKey = $this->validKey();

        // Encrypt with old key as current (version 1)
        $oldService = new EncryptionService($oldKey);
        $encrypted = $oldService->encrypt('secret-data');

        // Create new service with new key as current and old key as previous
        $newService = new EncryptionService($newKey, "1:{$oldKey}");

        // Should be able to decrypt data encrypted with old key
        $this->assertSame('secret-data', $newService->decrypt($encrypted));
    }

    public function testEncryptUsesCurrentKeyVersion(): void
    {
        $oldKey = $this->validKey();
        $newKey = $this->validKey();

        $service = new EncryptionService($newKey, "1:{$oldKey}");

        $encrypted = $service->encrypt('new-data');

        // Should start with v2: since old key is v1 and current is v2
        $this->assertStringStartsWith('v2:', $encrypted);

        // Should decrypt with current service
        $this->assertSame('new-data', $service->decrypt($encrypted));
    }

    public function testDecryptRejectsUnknownKeyVersion(): void
    {
        $service = new EncryptionService($this->validKey());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'No encryption key available for version 99',
        );

        $service->decrypt('v99:'.base64_encode(random_bytes(100)));
    }

    public function testDecryptLegacyUnversionedData(): void
    {
        $key = $this->validKey();

        // Simulate legacy data: encrypt using raw sodium without version prefix
        $rawKey = sodium_hex2bin($key);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox('legacy-secret', $nonce, $rawKey);
        $legacyEncrypted = base64_encode($nonce.$ciphertext);

        // Should not have a version prefix
        $this->assertDoesNotMatchRegularExpression(
            "/^v\d+:/",
            $legacyEncrypted,
        );

        // Service should decrypt legacy data using current key
        $service = new EncryptionService($key);
        $this->assertSame('legacy-secret', $service->decrypt($legacyEncrypted));
    }

    public function testIsCurrentVersionReturnsTrueForCurrentVersion(): void
    {
        $service = new EncryptionService($this->validKey());

        $encrypted = $service->encrypt('test');

        $this->assertTrue($service->isCurrentVersion($encrypted));
    }

    public function testIsCurrentVersionReturnsFalseForOldVersion(): void
    {
        $oldKey = $this->validKey();
        $newKey = $this->validKey();

        $oldService = new EncryptionService($oldKey);
        $encrypted = $oldService->encrypt('test');

        $newService = new EncryptionService($newKey, "1:{$oldKey}");

        $this->assertFalse($newService->isCurrentVersion($encrypted));
    }

    public function testIsCurrentVersionReturnsFalseForUnversionedData(): void
    {
        $service = new EncryptionService($this->validKey());

        // Unversioned data (legacy)
        $this->assertFalse(
            $service->isCurrentVersion(base64_encode('something')),
        );
    }

    public function testPreviousKeysInvalidFormatThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid previous key format');

        new EncryptionService($this->validKey(), 'badformat');
    }

    public function testPreviousKeysInvalidVersionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Key version must be a positive integer');

        new EncryptionService($this->validKey(), "0:{$this->validKey()}");
    }

    public function testMultiplePreviousKeysAllDecryptable(): void
    {
        $key1 = $this->validKey();
        $key2 = $this->validKey();
        $key3 = $this->validKey();

        // Encrypt with each key as "current"
        $service1 = new EncryptionService($key1);
        $encrypted1 = $service1->encrypt('data-v1');

        $service2 = new EncryptionService($key2, "1:{$key1}");
        $encrypted2 = $service2->encrypt('data-v2');

        // Now create service with key3 as current, key1 and key2 as previous
        $service3 = new EncryptionService($key3, "1:{$key1},2:{$key2}");

        $this->assertSame('data-v1', $service3->decrypt($encrypted1));
        $this->assertSame('data-v2', $service3->decrypt($encrypted2));

        $encrypted3 = $service3->encrypt('data-v3');
        $this->assertSame('data-v3', $service3->decrypt($encrypted3));
        $this->assertStringStartsWith('v3:', $encrypted3);
    }
}
