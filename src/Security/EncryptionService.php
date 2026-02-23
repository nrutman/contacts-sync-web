<?php

namespace App\Security;

class EncryptionService
{
    private string $key;

    public function __construct(
        #[\SensitiveParameter] string $encryptionKey,
    ) {
        $this->key = sodium_hex2bin($encryptionKey);

        if (mb_strlen($this->key, '8bit') !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Encryption key must be exactly 32 bytes (64 hex characters).');
        }
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce.$ciphertext);
    }

    public function decrypt(#[\SensitiveParameter] string $encoded): string
    {
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            throw new \RuntimeException('Failed to base64-decode encrypted value.');
        }

        $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

        if (mb_strlen($decoded, '8bit') < $nonceLength) {
            throw new \RuntimeException('Encrypted value is too short to contain a valid nonce.');
        }

        $nonce = mb_substr($decoded, 0, $nonceLength, '8bit');
        $ciphertext = mb_substr($decoded, $nonceLength, null, '8bit');

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed. Data may be corrupted or the encryption key is wrong.');
        }

        return $plaintext;
    }
}
