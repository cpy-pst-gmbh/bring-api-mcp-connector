<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Client\BringApiClient;
use App\Domain\Exception\BringUnreachableException;
use App\Entity\OAuthClient;
use App\Entity\User;
use App\EventSubscriber\OAuth\ConsentSubscriber;
use App\Form\BringCredentialType;
use App\Repository\OAuthClientRepository;
use App\Service\BringListService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Signing in and setting up are one flow; this is everything after step 1.
 *
 * Which step shows follows from what the account holds: no connector yet means
 * step 2, otherwise step 3 with one connector's credentials on screen. Someone
 * who set everything up long ago lands straight on step 3.
 */
#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    private const int STEP_ADD_CONNECTOR = 2;
    private const int STEP_CONNECT_CLAUDE = 3;

    #[Route('/account', name: 'app_account', methods: ['GET', 'POST'])]
    #[Template('account/index.html.twig')]
    public function index(
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        OAuthClientRepository $clients,
        BringApiClient $bring,
        BringListService $lists,
        TranslatorInterface $translator,
        #[Autowire('%app.mcp_endpoint%')] string $mcpEndpoint,
    ): array|Response {
        $credential = $user->getBringCredential();

        if (null === $credential) {
            // Only reachable if the row was deleted behind the app's back:
            // signing in always creates one.
            throw $this->createNotFoundException('This account has no stored Bring! credentials.');
        }

        $form = $this->createForm(BringCredentialType::class, $credential);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $password = (string) $credential->getPlainPassword();

            try {
                if (false === $bring->verifyCredentials($user->getEmail(), $password)) {
                    $form->get('plainPassword')->addError(
                        new FormError($translator->trans('account.password.flash.rejected')),
                    );

                    return $this->renderAccount($request, $form, $user, $clients, $lists, $mcpEndpoint);
                }
            } catch (BringUnreachableException) {
                // Someone changing their password here often got in through a
                // login link precisely because Bring! is unavailable. Refusing
                // the update would leave them stuck with a password known to
                // be wrong, so it is stored unverified instead.
                $this->addFlash('error', 'account.password.flash.unverified');
            }

            // Keeps the entity dirty so BringCredentialListener::preUpdate runs.
            $credential->touch();
            $em->flush();

            $this->addFlash('success', 'account.password.flash.updated');

            // Someone who got here from a connector flow should land back on
            // the consent screen instead of having to start over in Claude.
            if (true === $request->getSession()->has(ConsentSubscriber::PENDING_URI)) {
                return $this->redirectToRoute('app_consent');
            }

            return $this->redirectToRoute('app_account');
        }

        return $this->renderAccount($request, $form, $user, $clients, $lists, $mcpEndpoint);
    }

    #[Route('/account/delete', name: 'app_account_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        Security $security,
    ): Response {
        if (!$this->isCsrfTokenValid('delete_account', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // The credential and the connectors hang off the user with ON DELETE
        // CASCADE, and the connectors take their tokens with them — so removing
        // this one row really is everything the app knows about the person.
        $em->remove($user);
        $em->flush();

        // Logged out after the flush: the session still points at a row that is
        // gone, and the next request would fail to load the user from it.
        $response = $security->logout(validateCsrfToken: false);

        // And the message only after that — logging out invalidates the session
        // the flash would otherwise be written into, so it would never arrive.
        $this->addFlash('success', 'account.delete.flash.deleted');

        return $response ?? $this->redirectToRoute('app_home');
    }

    /**
     * @return array<string, mixed>
     */
    private function renderAccount(
        Request $request,
        FormInterface $form,
        User $user,
        OAuthClientRepository $clients,
        BringListService $lists,
        string $mcpEndpoint,
    ): array {
        $connectors = $clients->findForUser($user);

        return [
            'form' => $form,
            'credential' => $user->getBringCredential(),
            'consent_pending' => $request->getSession()->has(ConsentSubscriber::PENDING_URI),
            'current_step' => [] === $connectors ? self::STEP_ADD_CONNECTOR : self::STEP_CONNECT_CLAUDE,
            'connectors' => $connectors,
            'selected' => self::select($connectors, $request->query->get('connector')),
            // null means Bring! could not be asked; the form then offers a
            // plain text field instead of a dropdown.
            'list_names' => $lists->listNamesFor($user),
            'mcp_endpoint' => $mcpEndpoint,
        ];
    }

    /**
     * @param OAuthClient[] $connectors
     */
    private static function select(array $connectors, ?string $identifier): ?OAuthClient
    {
        foreach ($connectors as $connector) {
            if ($connector->getIdentifier() === $identifier) {
                return $connector;
            }
        }

        // An unknown or absent identifier falls back to the newest rather than
        // erroring — the query parameter is a convenience, not a contract.
        return $connectors[0] ?? null;
    }
}
