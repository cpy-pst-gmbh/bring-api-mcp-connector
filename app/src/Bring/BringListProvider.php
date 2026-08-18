<?php

declare(strict_types=1);

namespace App\Bring;

use App\Entity\User;
use App\Security\CredentialCipher;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function sprintf;

/**
 * The account's list names, for offering them as a choice in the UI.
 *
 * Reading them costs a Bring! login plus a request, so the result is cached
 * briefly — a list created at Bring! shows up here with a short delay. Failure
 * is not an error worth breaking a page over: callers get null and fall back to
 * a free-text field.
 */
final class BringListProvider
{
    private const int CACHE_TTL = 300;

    public function __construct(
        private readonly BringApiClient $bring,
        private readonly CredentialCipher $cipher,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<string>|null null when Bring! could not be asked
     */
    public function listNamesFor(User $user): ?array
    {
        $credential = $user->getBringCredential();

        if (null === $credential) {
            return null;
        }

        try {
            $password = $this->cipher->decrypt($credential->getPasswordCipher());
        } catch (RuntimeException $exception) {
            $this->logger->warning(
                'Stored Bring! credential unreadable: {message}',
                [
                    'message' => $exception->getMessage(),
                ],
            );

            return null;
        }

        $key = sprintf('bring_lists_%d_%s', $user->getId() ?? 0, substr(sha1($password), 0, 12));

        try {
            return $this->cache->get(
                $key,
                function (ItemInterface $item) use ($user, $password): array {
                    $item->expiresAfter(self::CACHE_TTL);

                    return $this->bring->fetchListNames($user->getEmail(), $password);
                },
            );
        } catch (BringUnreachableException $exception) {
            $this->logger->info(
                'Bring! lists unavailable: {message}',
                [
                    'message' => $exception->getMessage(),
                ],
            );

            return null;
        }
    }
}
