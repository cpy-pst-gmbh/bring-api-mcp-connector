<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;
use function sprintf;

/**
 * Warns dormant accounts and removes the ones that stayed dormant.
 *
 * Both halves belong in one run because they are one policy with two dates:
 * nothing is removed that was not told a month earlier, and the notice is what
 * makes the removal legitimate. Splitting them across two schedules would let
 * one run without the other.
 *
 * The account holds a Bring! password, so an abandoned one is a stored secret
 * nobody is watching. That is the reason for the deadline, not tidiness.
 */
#[AsCommand(
    name: 'app:accounts:prune-inactive',
    description: 'Warn accounts dormant for 11 months and delete those dormant for 12',
)]
final class PruneInactiveAccountsCommand
{
    /**
     * Months of silence before the warning goes out, and before the account is
     * removed. The month between them is what the email promises.
     */
    private const string NOTICE_AFTER = 'P11M';
    private const string DELETE_AFTER = 'P12M';
    private const string GRACE = 'P1M';

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%app.mail_from%')] private readonly string $mailFrom,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'List what would happen without sending or deleting anything')]
        bool $dryRun = false,
    ): int {
        $now = new DateTimeImmutable();

        $deleted = $this->delete($io, $now, $dryRun);
        $notified = $this->notify($io, $now, $dryRun);

        $io->success(sprintf(
            '%d account(s) notified, %d deleted.%s',
            $notified,
            $deleted,
            $dryRun ? ' Dry run — nothing was changed.' : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * Removal runs before the warnings so that a single run cannot warn an
     * account and delete it in the same breath — anything it removes was
     * warned by an earlier run.
     */
    private function delete(SymfonyStyle $io, DateTimeImmutable $now, bool $dryRun): int
    {
        $due = $this->users->findDueForDeletion(
            $now->sub(new DateInterval(self::DELETE_AFTER)),
            $now->sub(new DateInterval(self::GRACE)),
        );

        foreach ($due as $user) {
            $io->text(sprintf('Deleting %s, last active %s.', $user->getEmail(), $user->getLastActiveAt()->format('Y-m-d')));

            if ($dryRun) {
                continue;
            }

            // The credential and the connectors cascade off this row, so the
            // stored Bring! password goes with it.
            $this->logger->info('Deleting account {email} after 12 months without use.', ['email' => $user->getEmail()]);
            $this->em->remove($user);
        }

        if (!$dryRun && [] !== $due) {
            $this->em->flush();
        }

        return count($due);
    }

    private function notify(SymfonyStyle $io, DateTimeImmutable $now, bool $dryRun): int
    {
        $due = $this->users->findDueForNotice($now->sub(new DateInterval(self::NOTICE_AFTER)));
        $sent = 0;

        foreach ($due as $user) {
            $deadline = $now->add(new DateInterval(self::GRACE));

            $io->text(sprintf('Notifying %s, deletion due %s.', $user->getEmail(), $deadline->format('Y-m-d')));

            if ($dryRun) {
                ++$sent;

                continue;
            }

            // The timestamp is only set once the mail is actually away. A
            // broken transport must not start a deletion clock for a warning
            // nobody received — the account simply comes up again next run.
            if (!$this->send($user, $deadline)) {
                continue;
            }

            $user->setInactivityNoticeSentAt($now);
            ++$sent;
        }

        if (!$dryRun && $sent > 0) {
            $this->em->flush();
        }

        return $sent;
    }

    private function send(User $user, DateTimeImmutable $deadline): bool
    {
        try {
            $this->mailer->send(
                new TemplatedEmail()
                    ->from(new Address($this->mailFrom, $this->translator->trans('app.name')))
                    ->to(new Address($user->getEmail()))
                    ->subject($this->translator->trans('email.inactivity.subject'))
                    ->htmlTemplate('emails/inactivity_notice.html.twig')
                    ->context([
                        'last_active_at' => $user->getLastActiveAt(),
                        'deadline' => $deadline,
                        // Absolute, and from DEFAULT_URI: there is no request
                        // out here to take the host from.
                        'login_url' => $this->urls->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
                    ]),
            );
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Inactivity notice to {email} could not be sent: {message}', [
                'email' => $user->getEmail(),
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
