<?php

declare(strict_types=1);

namespace App\Service\Health;

use App\Domain\Interface\HealthCheckInterface;
use App\Domain\Model\HealthResult;
use App\Security\CredentialCipher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Throwable;

/**
 * Round-trips a value through the credential cipher.
 *
 * A wrong or missing BRING_CREDENTIALS_KEY makes every stored Bring! password
 * unreadable, so the connector fails for everyone at once. The cipher is pulled
 * from a locator because its constructor rejects a bad key — resolving it
 * eagerly would break the whole endpoint instead of reporting one failed check.
 */
final readonly class CredentialCipherCheck implements HealthCheckInterface
{
    public function __construct(
        #[AutowireLocator([CredentialCipher::class])] private ContainerInterface $locator,
        private LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return 'credential_cipher';
    }

    public function label(): string
    {
        return 'health.check.credential_cipher';
    }

    public function run(): HealthResult
    {
        try {
            /** @var CredentialCipher $cipher */
            $cipher = $this->locator->get(CredentialCipher::class);

            $probe = 'health-check';

            if ($cipher->decrypt($cipher->encrypt($probe)) !== $probe) {
                return HealthResult::failed('health.detail.cipher_roundtrip');
            }
        } catch (Throwable $exception) {
            $this->logger->error(
                'Credential cipher health check failed: {message}',
                [
                    'message' => $exception->getMessage(),
                ],
            );

            return HealthResult::failed('health.detail.cipher_unusable');
        }

        return HealthResult::ok();
    }
}
