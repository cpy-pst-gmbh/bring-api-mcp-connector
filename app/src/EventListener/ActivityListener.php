<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\AccountActivityService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Records activity wherever an account proves itself.
 *
 * One listener covers all three ways in, because each ends in an
 * authentication: the sign-in form, the login link, and the access token the
 * MCP server presents at /internal on every connector call. Reading the
 * timestamp off authentication rather than off individual controllers means a
 * new entry point cannot forget to keep an account alive.
 */
final readonly class ActivityListener
{
    public function __construct(private AccountActivityService $recorder)
    {
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        // A brand new account is written by the authenticator itself; touching
        // it here as well would be a second flush for the same instant.
        if (null === $user->getId()) {
            return;
        }

        $this->recorder->record($user);
    }
}
