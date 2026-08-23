<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\BringCredential;
use App\Entity\User;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Runs the two queries against a real SQLite database rather than a mock: what
 * is worth checking here is the DQL itself, and a mocked query builder would
 * only confirm that the strings are the strings.
 *
 * The database is in memory and holds two tables, so it costs about as much as
 * a unit test.
 */
#[CoversClass(UserRepository::class)]
final class UserRepositoryTest extends TestCase
{
    private EntityManagerInterface $em;
    private UserRepository $users;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../../src/Entity'], true);
        // PHP 8.4 lazy objects, the same setting the bundle applies. Without
        // it the ORM looks for a proxy generator it no longer ships.
        $config->enableNativeLazyObjects(true);
        $this->em = new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config),
            $config,
        );

        // Only the two entities this repository touches. The OAuth client
        // extends a mapped superclass from the bundle and would drag the whole
        // container in for nothing.
        new SchemaTool($this->em)->createSchema([
            $this->em->getClassMetadata(User::class),
            $this->em->getClassMetadata(BringCredential::class),
        ]);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);

        $this->users = new UserRepository($registry);
    }

    public function testFindOneByEmailMissesWhatIsNotThere(): void
    {
        $this->persist(self::user('someone@example.com'));

        self::assertNotNull($this->users->findOneByEmail('someone@example.com'));
        self::assertNull($this->users->findOneByEmail('nobody@example.com'));
    }

    public function testNoticeGoesToDormantAccountsThatHaveNotHadOne(): void
    {
        $this->persist(
            self::user('dormant@example.com', lastActiveAt: '-12 months'),
            self::user('active@example.com', lastActiveAt: '-2 months'),
            self::user('already-warned@example.com', lastActiveAt: '-12 months', noticeSentAt: '-1 month'),
        );

        $due = $this->users->findDueForNotice(new DateTimeImmutable('-11 months'));

        self::assertSame(['dormant@example.com'], self::emails($due));
    }

    public function testTheLongestDormantAccountComesFirst(): void
    {
        $this->persist(
            self::user('recent@example.com', lastActiveAt: '-11 months -1 day'),
            self::user('ancient@example.com', lastActiveAt: '-3 years'),
        );

        $due = $this->users->findDueForNotice(new DateTimeImmutable('-11 months'));

        self::assertSame(['ancient@example.com', 'recent@example.com'], self::emails($due));
    }

    /**
     * The condition that keeps a cron which never ran from deleting accounts
     * whose owners were never told: no notice, no deletion.
     */
    public function testAnAccountThatWasNeverWarnedIsNeverDeleted(): void
    {
        $this->persist(self::user('unwarned@example.com', lastActiveAt: '-5 years'));

        $due = $this->users->findDueForDeletion(
            new DateTimeImmutable('-12 months'),
            new DateTimeImmutable('-1 month'),
        );

        self::assertSame([], self::emails($due));
    }

    /**
     * The month between warning and deletion is what the email promises, so a
     * notice sent yesterday holds the account back even after twelve months of
     * silence.
     */
    public function testAFreshNoticeHoldsTheAccountBack(): void
    {
        $this->persist(
            self::user('warned-yesterday@example.com', lastActiveAt: '-13 months', noticeSentAt: '-1 day'),
            self::user('warned-long-ago@example.com', lastActiveAt: '-13 months', noticeSentAt: '-2 months'),
        );

        $due = $this->users->findDueForDeletion(
            new DateTimeImmutable('-12 months'),
            new DateTimeImmutable('-1 month'),
        );

        self::assertSame(['warned-long-ago@example.com'], self::emails($due));
    }

    /**
     * A user who came back has a cleared notice and a fresh timestamp; both
     * conditions have to miss.
     */
    public function testAnAccountUsedAgainAfterTheWarningIsSafe(): void
    {
        $this->persist(self::user('returned@example.com', lastActiveAt: '-1 hour'));

        self::assertSame([], self::emails($this->users->findDueForNotice(new DateTimeImmutable('-11 months'))));
        self::assertSame([], self::emails($this->users->findDueForDeletion(
            new DateTimeImmutable('-12 months'),
            new DateTimeImmutable('-1 month'),
        )));
    }

    private function persist(User ...$users): void
    {
        foreach ($users as $user) {
            $this->em->persist($user);
        }

        $this->em->flush();
        $this->em->clear();
    }

    private static function user(string $email, string $lastActiveAt = '-1 hour', ?string $noticeSentAt = null): User
    {
        return new User()
            ->setEmail($email)
            ->setLastActiveAt(new DateTimeImmutable($lastActiveAt))
            ->setInactivityNoticeSentAt(null === $noticeSentAt ? null : new DateTimeImmutable($noticeSentAt));
    }

    /**
     * @param list<User> $users
     *
     * @return list<string>
     */
    private static function emails(array $users): array
    {
        return array_map(static fn (User $user): string => $user->getEmail(), $users);
    }
}
