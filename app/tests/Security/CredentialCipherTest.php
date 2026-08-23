<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\CredentialCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

/**
 * The cipher is the only thing between the database and every stored Bring!
 * password, and nothing else in the suite would notice if it stopped
 * authenticating its ciphertexts.
 */
#[CoversClass(CredentialCipher::class)]
final class CredentialCipherTest extends TestCase
{
    public function testRoundTripsAPassword(): void
    {
        $cipher = new CredentialCipher(self::key());

        self::assertSame('hunter2', $cipher->decrypt($cipher->encrypt('hunter2')));
    }

    /**
     * Passwords are short and users reuse them; equal plaintexts must not
     * produce equal rows, or the database leaks who shares a password.
     */
    public function testEncryptingTwiceGivesDifferentCiphertexts(): void
    {
        $cipher = new CredentialCipher(self::key());

        self::assertNotSame($cipher->encrypt('hunter2'), $cipher->encrypt('hunter2'));
    }

    public function testDecryptingWithAnotherKeyFails(): void
    {
        $written = new CredentialCipher(self::key())->encrypt('hunter2');

        $this->expectException(RuntimeException::class);

        new CredentialCipher(self::key("\x01"))->decrypt($written);
    }

    /**
     * secretbox authenticates, so a flipped byte has to be refused rather than
     * decrypted into garbage that is then sent to Bring!.
     */
    public function testTamperedCiphertextIsRejected(): void
    {
        $cipher = new CredentialCipher(self::key());
        $raw = base64_decode($cipher->encrypt('hunter2'), true);
        self::assertIsString($raw);

        $raw[strlen($raw) - 1] = "\x00" === $raw[strlen($raw) - 1] ? "\x01" : "\x00";

        $this->expectException(RuntimeException::class);

        $cipher->decrypt(base64_encode($raw));
    }

    public function testTooShortAPayloadIsRejectedBeforeSodiumSeesIt(): void
    {
        $this->expectException(RuntimeException::class);

        new CredentialCipher(self::key())->decrypt(base64_encode('short'));
    }

    public function testNonBase64PayloadIsRejected(): void
    {
        $this->expectException(RuntimeException::class);

        new CredentialCipher(self::key())->decrypt('not base64 !!');
    }

    /**
     * A key of the wrong length would otherwise surface as a sodium error on
     * the first sign-in rather than at startup.
     */
    public function testKeyOfTheWrongLengthIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must decode to 32 bytes, got 5/');

        new CredentialCipher('short');
    }

    private static function key(string $fill = "\x00"): string
    {
        return str_repeat($fill, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
