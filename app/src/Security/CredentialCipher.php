<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function sprintf;
use function strlen;

use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * Symmetric encryption for stored Bring! passwords.
 *
 * Bring has no token-based login, so the password must be recoverable in
 * plaintext to log in on the user's behalf — hashing is not an option.
 * libsodium's secretbox (XSalsa20-Poly1305) gives authenticated encryption;
 * a fresh random nonce is prepended to each ciphertext.
 */
final readonly class CredentialCipher
{
    private string $key;

    public function __construct(#[Autowire('%env(base64:BRING_CREDENTIALS_KEY)%')] string $key)
    {
        if (SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen($key)) {
            throw new RuntimeException(sprintf('BRING_CREDENTIALS_KEY must decode to %d bytes, got %d. Generate one with: php bin/console app:credentials:generate-key', SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($key)));
        }

        $this->key = $key;
    }

    /**
     * @return string base64 of nonce || ciphertext
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);

        if (false === $raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Stored credential is malformed.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if (false === $plaintext) {
            throw new RuntimeException('Stored credential could not be decrypted — wrong key or tampered data.');
        }

        return $plaintext;
    }
}
