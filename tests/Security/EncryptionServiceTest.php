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
        $decoded = base64_decode($encrypted, true);
        // Flip a byte in the ciphertext portion (after the nonce)
        $tampered = $decoded;
        $tampered[SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1] = chr(
            ord($tampered[SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1]) ^ 0xFF,
        );
        $reEncoded = base64_encode($tampered);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');

        $service->decrypt($reEncoded);
    }

    public function testDecryptWithTooShortDataThrows(): void
    {
        $service = new EncryptionService($this->validKey());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too short');

        $service->decrypt(base64_encode('short'));
    }

    public function testConstructorRejectsInvalidKeyLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Encryption key must be exactly 32 bytes');

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
}
