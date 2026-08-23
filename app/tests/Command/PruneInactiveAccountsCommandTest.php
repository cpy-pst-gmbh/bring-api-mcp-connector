<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PruneInactiveAccountsCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The only unattended path in the application that deletes data. What is worth
 * pinning down is not that it deletes, but the conditions it refuses to: the
 * deadlines it asks the repository for, and a warning that never left the
 * building.
 */
#[CoversClass(PruneInactiveAccountsCommand::class)]
final class PruneInactiveAccountsCommandTest extends TestCase
{
    /**
     * The two dates the policy consists of. Read from the repository calls,
     * because that is where they take effect — the constants themselves say
     * nothing about which query they end up in.
     */
    public function testItAsksForTwelveMonthsDormantWithAMonthOldNoticeAndElevenMonthsUnwarned(): void
    {
        $now = new DateTimeImmutable();
        $users = $this->createMock(UserRepository::class);

        $users->expects(self::once())
            ->method('findDueForDeletion')
            ->with(
                self::callback(self::around($now->modify('-12 months'))),
                self::callback(self::around($now->modify('-1 month'))),
            )
            ->willReturn([]);

        $users->expects(self::once())
            ->method('findDueForNotice')
            ->with(self::callback(self::around($now->modify('-11 months'))))
            ->willReturn([]);

        $this->prune($users, $this->createStub(EntityManagerInterface::class), $this->silentMailer());
    }

    public function testItRemovesEveryAccountTheRepositoryReturnsAndFlushesOnce(): void
    {
        $dormant = [self::user('one@example.com'), self::user('two@example.com')];

        $em = $this->createMock(EntityManagerInterface::class);
        $removed = [];
        $em->expects(self::exactly(2))
            ->method('remove')
            ->willReturnCallback(static function (User $user) use (&$removed): void {
                $removed[] = $user->getEmail();
            });
        $em->expects(self::once())->method('flush');

        $output = $this->prune($this->repository(deletion: $dormant), $em, $this->silentMailer());

        self::assertSame(['one@example.com', 'two@example.com'], $removed);
        self::assertStringContainsString('0 account(s) notified, 2 deleted.', $output);
    }

    /**
     * Removal runs first so that a single run cannot warn an account and
     * delete it in the same breath.
     */
    public function testItDeletesBeforeItWarns(): void
    {
        $calls = [];
        $users = $this->createStub(UserRepository::class);
        $users->method('findDueForDeletion')->willReturnCallback(static function () use (&$calls): array {
            $calls[] = 'delete';

            return [];
        });
        $users->method('findDueForNotice')->willReturnCallback(static function () use (&$calls): array {
            $calls[] = 'notify';

            return [];
        });

        $this->prune($users, $this->createStub(EntityManagerInterface::class), $this->silentMailer());

        self::assertSame(['delete', 'notify'], $calls);
    }

    public function testItMailsTheDormantAccountAndRecordsTheNotice(): void
    {
        $user = self::user('dormant@example.com');

        $sent = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->willReturnCallback(static function (RawMessage $message) use (&$sent): void {
                self::assertInstanceOf(TemplatedEmail::class, $message);
                $sent[] = $message->getTo()[0]->getAddress();
            });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $output = $this->prune($this->repository(notice: [$user]), $em, $mailer);

        self::assertSame(['dormant@example.com'], $sent);
        self::assertNotNull($user->getInactivityNoticeSentAt());
        self::assertStringContainsString('1 account(s) notified, 0 deleted.', $output);
    }

    /**
     * A warning nobody received must not start a deletion clock. The account
     * simply comes up again on the next run.
     */
    public function testAFailedDeliveryLeavesTheNoticeUnrecorded(): void
    {
        $user = self::user('dormant@example.com');

        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('smtp is down'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $output = $this->prune($this->repository(notice: [$user]), $em, $mailer);

        self::assertNull($user->getInactivityNoticeSentAt());
        self::assertStringContainsString('0 account(s) notified, 0 deleted.', $output);
    }

    public function testADryRunNeitherMailsNorRemovesAnything(): void
    {
        $doomed = self::user('doomed@example.com');
        $warned = self::user('warned@example.com');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('remove');
        $em->expects(self::never())->method('flush');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $output = $this->prune(
            $this->repository(deletion: [$doomed], notice: [$warned]),
            $em,
            $mailer,
            dryRun: true,
        );

        self::assertNull($warned->getInactivityNoticeSentAt());
        self::assertStringContainsString('1 account(s) notified, 1 deleted.', $output);
        self::assertStringContainsString('Dry run', $output);
    }

    /**
     * @param list<User> $deletion
     * @param list<User> $notice
     */
    private function repository(array $deletion = [], array $notice = []): UserRepository
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findDueForDeletion')->willReturn($deletion);
        $users->method('findDueForNotice')->willReturn($notice);

        return $users;
    }

    private function silentMailer(): MailerInterface
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        return $mailer;
    }

    private function prune(
        UserRepository $users,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        bool $dryRun = false,
    ): string {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://bring.example.com/login');

        $command = new PruneInactiveAccountsCommand(
            $users,
            $em,
            $mailer,
            $translator,
            new NullLogger(),
            $urls,
            'no-reply@bring.example.com',
        );

        $output = new BufferedOutput();
        $command(new SymfonyStyle(new ArrayInput([]), $output), $dryRun);

        return $output->fetch();
    }

    private static function user(string $email): User
    {
        return new User()
            ->setEmail($email)
            ->setLastActiveAt(new DateTimeImmutable('-13 months'));
    }

    /**
     * Wall clock moves between the test's `now` and the command's, so the
     * expected cutoffs are matched within a window rather than exactly.
     */
    private static function around(DateTimeImmutable $expected): callable
    {
        return static fn (DateTimeImmutable $actual): bool => abs($actual->getTimestamp() - $expected->getTimestamp()) <= 5;
    }
}
