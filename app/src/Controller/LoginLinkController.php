<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The way back in when Bring! says no.
 *
 * A wrong password, a changed password, Bring! being down — in all three cases
 * the account itself is fine, so a link mailed to the registered address gets
 * the user to their account page, where they can fix the stored credentials.
 */
final class LoginLinkController extends AbstractController
{
    #[Route('/login/link', name: 'app_login_link', methods: ['POST'])]
    public function request(
        Request $request,
        UserRepository $users,
        LoginLinkHandlerInterface $loginLinkHandler,
        MailerInterface $mailer,
        LoggerInterface $logger,
        TranslatorInterface $translator,
        #[Autowire('%app.mail_from%')]
        string $mailFrom,
    ): Response {
        if (false === $this->isCsrfTokenValid('login_link', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $email = trim($request->request->getString('email'));
        $user = '' === $email ? null : $users->findOneByEmail($email);

        if (null !== $user) {
            $details = $loginLinkHandler->createLoginLink($user);

            $mailer->send(
                new TemplatedEmail()
                    ->from(new Address($mailFrom, $translator->trans('app.name')))
                    ->to(new Address($user->getEmail()))
                    ->subject($translator->trans('email.login_link.subject'))
                    ->htmlTemplate('emails/login_link.html.twig')
                    ->context(
                        [
                            'link' => $details->getUrl(),
                            'expires_at' => $details->getExpiresAt(),
                        ],
                    ),
            );

            $logger->info('Login link sent to {email}.', ['email' => $user->getEmail()]);
        }

        // Same answer either way — whether an address has an account here is
        // not something a stranger gets to probe for.
        $this->addFlash('success', 'login.link.sent');

        return $this->redirectToRoute('app_login');
    }

    /**
     * Consumed by the login_link authenticator on the main firewall; the
     * controller body only runs if the firewall ever stops matching.
     */
    #[Route('/login/link/check', name: 'app_login_link_check', methods: ['GET'])]
    public function check(): Response
    {
        throw new LogicException('Intercepted by the login_link key in security.yaml.');
    }
}
