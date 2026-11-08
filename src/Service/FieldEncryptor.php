<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encryption at rest for sensitive fields such as the seller IBAN (rule 11).
 *
 * libsodium secretbox = XSalsa20 stream cipher + Poly1305 MAC (authenticated encryption):
 *  - confidentiality: a DB dump/backup leak doesn't reveal IBANs without the key;
 *  - integrity: a modified ciphertext fails to decrypt instead of returning garbage;
 *  - a fresh random 24-byte nonce per encryption, so the same IBAN never gives the same ciphertext.
 *
 * The 32-byte key is NOT in the database and NOT in git:
 *  - prod: php bin/console secrets:set APP_ENCRYPTION_KEY --env=prod  (Symfony secrets vault)
 *  - dev:  APP_ENCRYPTION_KEY=<64 hex chars> in .env.local (git-ignored)
 * Generate a key with: php -r "echo bin2hex(sodium_crypto_secretbox_keygen()), PHP_EOL;"
 *
 * Stored format: "v1:" . base64(nonce . ciphertext). The version prefix allows key rotation later.
 */
final class FieldEncryptor
{
    private const PREFIX = 'v1:';

    public function __construct(
        #[Autowire(env: 'APP_ENCRYPTION_KEY')]
        #[\SensitiveParameter]
        private readonly string $hexKey,
    ) {
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->key();
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        sodium_memzero($key);

        return self::PREFIX.base64_encode($nonce.$cipher);
    }

    public function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new \RuntimeException('Unknown ciphertext format.');
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if (false === $raw || strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Corrupted ciphertext.');
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->key();
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        sodium_memzero($key);

        // SECURITY: false = the MAC didn't verify (tampered data or wrong key). Never returned as data.
        if (false === $plain) {
            throw new \RuntimeException('Decryption failed (wrong key or tampered data).');
        }

        return $plain;
    }

    private function key(): string
    {
        // SECURITY: fail closed. Without a proper 256-bit key nothing is encrypted with a weak/empty key.
        if (1 !== preg_match('/^[0-9a-f]{64}$/i', $this->hexKey)) {
            throw new \LogicException('APP_ENCRYPTION_KEY is missing or invalid (expected 64 hex characters). See FieldEncryptor docblock.');
        }

        return sodium_hex2bin($this->hexKey);
    }
}
