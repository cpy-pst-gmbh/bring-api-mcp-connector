<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BringCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BringCredential>
 */
class BringCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BringCredential::class);
    }

    public function findOneByUserIdentifier(string $identifier): ?BringCredential
    {
        return $this->createQueryBuilder('c')
            ->join('c.user', 'u')
            ->andWhere('u.email = :identifier')
            ->setParameter('identifier', $identifier)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
