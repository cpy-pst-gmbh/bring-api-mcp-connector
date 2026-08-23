<?php

declare(strict_types=1);

namespace App\Mailer;

use App\Markdown\MarkdownFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

use function is_string;

/**
 * Appends the operator's signature to every outgoing message.
 *
 * Hooked into the mailer rather than into a shared email template on purpose:
 * a template has to be extended, and the one email somebody adds later without
 * extending it is exactly the one that would go out unsigned. Everything the
 * app sends passes through here.
 *
 * Priority is negative so this runs after Symfony's own MessageListener, which
 * is what turns a TemplatedEmail into a body in the first place — at the
 * default priority there would be nothing to append to.
 */
#[AsEventListener(event: MessageEvent::class, priority: -100)]
final readonly class SignatureListener
{
    public function __construct(
        #[Autowire('%app.mail_signature%')] private string $configuredPath,
        private MarkdownFile $markdown,
    ) {
    }

    public function __invoke(MessageEvent $event): void
    {
        if ('' === $this->configuredPath) {
            return;
        }

        $message = $event->getMessage();

        if (!$message instanceof Email) {
            return;
        }

        $signature = $this->markdown->html($this->configuredPath, 'MAIL_SIGNATURE');

        if (null === $signature || '' === $signature) {
            return;
        }

        $html = $message->getHtmlBody();

        if (true === is_string($html) && '' !== $html) {
            // A horizontal rule rather than a wrapper with styling: these are
            // fragments, not full documents, and the mail clients that would
            // honour a class are not the ones this has to survive.
            $message->html($html . "\n<hr>\n" . $signature);
        }
    }
}
