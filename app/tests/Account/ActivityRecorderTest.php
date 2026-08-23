<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Entity\User;
use App\Service\AccountActivityService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Every authenticated connector call lands here, so the cheap path has to stay
 * cheap — and the one case that has to write regardless is the account with an
 * outstanding deletion notice.
 */
#[CoversClass(AccountActivityService::class)]
final class ActivityRecorderTest extends TestCase
{
    public function testAFreshTimestampIsLeftAlone(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $user = self::user(lastActiveAt: new DateTimeImmutable('-5 minutes'));
        $before = $user->getLastActiveAt();

        new AccountActivityService($em)->record($user);

        self::assertSame($before, $user->getLastActiveAt());
    }

    public function testAStaleTimestampIsWritten(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $user = self::user(lastActiveAt: new DateTimeImmutable('-2 hours'));

        new AccountActivityService($em)->record($user);

        self::assertGreaterThan(new DateTimeImmutable('-1 minute'), $user->getLastActiveAt());
    }

    /**
     * The notice says the account is about to go. Using it has to withdraw
     * that immediately, however recently the timestamp was written — otherwise
     * a user who signs in twice within the hour keeps a pending deletion.
     */
    public function testAnOutstandingNoticeIsWithdrawnEvenWhenTheTimestampIsFresh(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $user = self::user(
            lastActiveAt: new DateTimeImmutable('-5 minutes'),
            noticeSentAt: new DateTimeImmutable('-3 days'),
        );

        new AccountActivityService($em)->record($user);

        self::assertNull($user->getInactivityNoticeSentAt());
    }

    private static function user(DateTimeImmutable $lastActiveAt, ?DateTimeImmutable $noticeSentAt = null): User
    {
        return new User()
            ->setEmail('dormant@example.com')
            ->setLastActiveAt($lastActiveAt)
            ->setInactivityNoticeSentAt($noticeSentAt);
    }
}
