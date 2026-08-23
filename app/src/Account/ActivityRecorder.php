<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Marks an account as used.
 *
 * Every authenticated connector call passes through here, which for a busy user
 * is a few hundred a day. Writing the timestamp each time would be a database
 * write per request to move a value that decides something eleven months out,
 * so it is only written once the stored one is an hour old.
 *
 * An outstanding deletion notice is the exception: that has to be withdrawn the
 * moment the account is used, whatever the clock says.
 */
final readonly class ActivityRecorder
{
    /**
     * How stale the stored timestamp may get before it is worth a write.
     */
    private const string RESOLUTION = 'PT1H';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function record(User $user): void
    {
        $now = new DateTimeImmutable();
        $hasNotice = null !== $user->getInactivityNoticeSentAt();
        $stale = $user->getLastActiveAt() < $now->sub(new DateInterval(self::RESOLUTION));

        if (false === $hasNotice && false === $stale) {
            return;
        }

        $user->setLastActiveAt($now);
        $user->setInactivityNoticeSentAt(null);

        $this->em->flush();
    }
}
