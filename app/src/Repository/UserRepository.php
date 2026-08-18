<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * Accounts that have been dormant long enough to be warned and have not
     * been warned yet.
     *
     * @return list<User>
     */
    public function findDueForNotice(DateTimeImmutable $inactiveSince): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.lastActiveAt <= :since')
            ->andWhere('u.inactivityNoticeSentAt IS NULL')
            ->setParameter('since', $inactiveSince)
            ->orderBy('u.lastActiveAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Accounts due for removal.
     *
     * Being dormant long enough is not sufficient on its own: a notice must
     * have gone out and had time to be acted on. Without that condition a cron
     * that had never run — or a mail transport that was broken all year —
     * would delete accounts whose owners were never told.
     *
     * @return list<User>
     */
    public function findDueForDeletion(DateTimeImmutable $inactiveSince, DateTimeImmutable $noticedBefore): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.lastActiveAt <= :since')
            ->andWhere('u.inactivityNoticeSentAt IS NOT NULL')
            ->andWhere('u.inactivityNoticeSentAt <= :noticed')
            ->setParameter('since', $inactiveSince)
            ->setParameter('noticed', $noticedBefore)
            ->orderBy('u.lastActiveAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
