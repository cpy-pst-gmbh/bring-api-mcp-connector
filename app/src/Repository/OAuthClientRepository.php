<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthClient;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthClient>
 */
class OAuthClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthClient::class);
    }

    /**
     * @return OAuthClient[]
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countForUser(User $user): int
    {
        return $this->createQueryBuilder('c')
            ->select('COUNT(c.identifier)')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }
}
