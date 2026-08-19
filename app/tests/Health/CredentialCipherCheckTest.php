<?php

declare(strict_types=1);

namespace App\Tests\Health;

use App\Health\CredentialCipherCheck;
use App\Health\HealthResult;
use App\Security\CredentialCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

/**
 * /health is public and has to answer even when the thing it reports on is
 * broken. A misconfigured key must come back as one failed check, not as a
 * 500 that says nothing.
 */
#[CoversClass(CredentialCipherCheck::class)]
final class CredentialCipherCheckTest extends TestCase
{
    public function testAWorkingCipherPasses(): void
    {
        $check = $this->check(new CredentialCipher(str_repeat("\x00", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

        self::assertSame(HealthResult::OK, $check->run()->status);
    }

    /**
     * The cipher's constructor rejects a key of the wrong length, which is why
     * the check pulls it from a locator instead of taking it as a dependency.
     */
    public function testAnUnusableKeyIsReportedRatherThanThrown(): void
    {
        $locator = $this->createStub(ContainerInterface::class);
        $locator->method('get')->willThrowException(
            new class('BRING_CREDENTIALS_KEY must decode to 32 bytes, got 5') extends RuntimeException implements ContainerExceptionInterface {},
        );

        $result = new CredentialCipherCheck($locator, new NullLogger())->run();

        self::assertTrue($result->isFailure());
        self::assertSame('health.detail.cipher_unusable', $result->detail);
    }

    private function check(CredentialCipher $cipher): CredentialCipherCheck
    {
        $locator = $this->createStub(ContainerInterface::class);
        $locator->method('get')->willReturn($cipher);

        return new CredentialCipherCheck($locator, new NullLogger());
    }
}
