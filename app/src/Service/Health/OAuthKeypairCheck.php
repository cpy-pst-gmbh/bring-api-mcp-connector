<?php

declare(strict_types=1);

namespace App\Service\Health;

use App\Domain\Interface\HealthCheckInterface;
use App\Domain\Model\HealthResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The keys that sign and verify access tokens.
 *
 * A missing or unreadable private key only surfaces when someone tries to sign
 * in through Claude, which is exactly the wrong moment to find out. The public
 * key is parsed rather than merely read, since the JWKS document is built from
 * it.
 */
final readonly class OAuthKeypairCheck implements HealthCheckInterface
{
    public function __construct(
        #[Autowire('%env(resolve:OAUTH_PRIVATE_KEY)%')] private string $privateKeyPath,
        #[Autowire('%env(resolve:OAUTH_PUBLIC_KEY)%')] private string $publicKeyPath,
    ) {
    }

    public function name(): string
    {
        return 'oauth_keypair';
    }

    public function label(): string
    {
        return 'health.check.oauth_keypair';
    }

    public function run(): HealthResult
    {
        if (false === is_readable($this->privateKeyPath)) {
            return HealthResult::failed('health.detail.keypair_private_missing');
        }

        $publicKey = @file_get_contents($this->publicKeyPath);

        if (false === $publicKey) {
            return HealthResult::failed('health.detail.keypair_public_missing');
        }

        $parsed = openssl_pkey_get_public($publicKey);

        if (false === $parsed) {
            return HealthResult::failed('health.detail.keypair_public_invalid');
        }

        $details = openssl_pkey_get_details($parsed);

        if (!isset($details['rsa'])) {
            return HealthResult::failed('health.detail.keypair_not_rsa');
        }

        return HealthResult::ok();
    }
}
