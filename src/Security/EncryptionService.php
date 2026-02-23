<?php

namespace App\Security;

class EncryptionService
{
    private const VERSION_PREFIX_PATTERN = '/^v(\d+):(.+)$/s';

    private string $currentKey;
    private int $currentVersion;

    /**
     * @var array<int, string> Map of version number => raw binary key
     */
    private array $keys = [];

    /**
     * @param string $encryptionKey The current (latest) encryption key as a hex string.
     *                              This is used for all new encryptions.
     * @param string $previousEncryptionKeys Comma-separated list of "version:hexkey" pairs for older keys.
     *                                       Example: "1:aabbcc...,2:ddeeff..."
     *                                       If empty, only the current key (version 1) is available.
     */
    public function __construct(
        #[\SensitiveParameter] string $encryptionKey,
        #[\SensitiveParameter] string $previousEncryptionKeys = '',
    ) {
        // Parse previous keys first so current key version can be determined
        $this->parsePreviousKeys($previousEncryptionKeys);

        $this->currentKey = $this->parseHexKey($encryptionKey);

        // Current key version is one higher than the highest previous key version,
        // or 1 if there are no previous keys
        $this->currentVersion =
            $this->keys === [] ? 1 : max(array_keys($this->keys)) + 1;
        $this->keys[$this->currentVersion] = $this->currentKey;
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox(
            $plaintext,
            $nonce,
            $this->currentKey,
        );

        return sprintf(
            'v%d:%s',
            $this->currentVersion,
            base64_encode($nonce.$ciphertext),
        );
    }

    public function decrypt(#[\SensitiveParameter] string $encoded): string
    {
        $version = null;
        $payload = $encoded;

        // Try to extract version prefix
        if (preg_match(self::VERSION_PREFIX_PATTERN, $encoded, $matches)) {
            $version = (int) $matches[1];
            $payload = $matches[2];
        }

        // Determine which key to use
        if ($version !== null) {
            if (!isset($this->keys[$version])) {
                throw new \RuntimeException(sprintf('No encryption key available for version %d.', $version));
            }
            $key = $this->keys[$version];
        } else {
            // Legacy data without version prefix — try current key (backwards compatible)
            $key = $this->currentKey;
        }

        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            throw new \RuntimeException('Failed to base64-decode encrypted value.');
        }

        $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

        if (mb_strlen($decoded, '8bit') < $nonceLength) {
            throw new \RuntimeException('Encrypted value is too short to contain a valid nonce.');
        }

        $nonce = mb_substr($decoded, 0, $nonceLength, '8bit');
        $ciphertext = mb_substr($decoded, $nonceLength, null, '8bit');

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed. Data may be corrupted or the encryption key is wrong.');
        }

        return $plaintext;
    }

    /**
     * Returns the current key version used for encryption.
     */
    public function getCurrentVersion(): int
    {
        return $this->currentVersion;
    }

    /**
     * Returns whether the given encrypted value uses the current key version.
     */
    public function isCurrentVersion(string $encoded): bool
    {
        if (preg_match(self::VERSION_PREFIX_PATTERN, $encoded, $matches)) {
            return (int) $matches[1] === $this->currentVersion;
        }

        // Unversioned data is never "current" when we have versioning enabled
        return false;
    }

    private function parseHexKey(#[\SensitiveParameter] string $hexKey): string
    {
        if ($hexKey === '') {
            throw new \InvalidArgumentException('Encryption key must not be empty.');
        }

        $key = sodium_hex2bin($hexKey);

        if (mb_strlen($key, '8bit') !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Encryption key must be exactly 32 bytes (64 hex characters).');
        }

        return $key;
    }

    private function parsePreviousKeys(
        #[\SensitiveParameter] string $previousKeys,
    ): void {
        if ($previousKeys === '') {
            return;
        }

        $pairs = explode(',', $previousKeys);

        foreach ($pairs as $pair) {
            $pair = trim($pair);

            if ($pair === '') {
                continue;
            }

            $parts = explode(':', $pair, 2);

            if (count($parts) !== 2) {
                throw new \InvalidArgumentException(sprintf('Invalid previous key format: "%s". Expected "version:hexkey".', $pair));
            }

            $version = (int) $parts[0];
            $hexKey = trim($parts[1]);

            if ($version < 1) {
                throw new \InvalidArgumentException(sprintf('Key version must be a positive integer, got %d.', $version));
            }

            $this->keys[$version] = $this->parseHexKey($hexKey);
        }
    }
}
