<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Sends a user who arrived through a login link back to wherever they were
 * headed, or to their account.
 *
 * Someone using the link is usually here because their Bring! password stopped
 * working, so the account page is the useful landing spot.
 */
final class LoginLinkSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if (null !== $session && $target = $this->getTargetPath($session, 'main')) {
            return new RedirectResponse($target);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_account'));
    }
}
