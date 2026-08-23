<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bring\BringListProvider;
use App\Entity\OAuthClient;
use App\Entity\User;
use App\Repository\OAuthClientRepository;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;

/**
 * Lets a user mint and revoke their own connector client.
 *
 * These are public clients: the identifier is not a secret, PKCE and the login
 * at /authorize are what actually protect the flow. One client per user exists
 * so that revoking one only cuts off that user's connector.
 */
#[IsGranted('ROLE_USER')]
final class ConnectorController extends AbstractController
{
    private const int MAX_PER_USER = 5;

    public function __construct(
        private readonly ClientManagerInterface $clients,
        private readonly OAuthClientRepository $repository,
        private readonly BringListProvider $lists,
        private readonly TranslatorInterface $translator,
        #[Autowire('%app.connector_redirect_uri%')] private readonly string $redirectUri,
    ) {
    }

    #[Route('/account/connectors', name: 'app_connector_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('create_connector', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($this->repository->countForUser($user) >= self::MAX_PER_USER) {
            $this->addFlash(
                'error',
                $this->translator->trans(
                    'connector.flash.limit_reached',
                    ['%limit%' => self::MAX_PER_USER],
                ),
            );

            return $this->redirectToRoute('app_account');
        }

        $label = trim((string) $request->request->get('label', ''));
        $label = '' === $label ? 'Claude' : mb_substr($label, 0, 60);

        $client = new OAuthClient($label, self::generateIdentifier(), null);
        $client->user = $user;
        $client->defaultListName = $this->resolveDefaultList($request, $user);
        $client->setActive(true);
        $client->setRedirectUris(new RedirectUri($this->redirectUri));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope('bring'));

        $this->clients->save($client);

        $this->addFlash(
            'success',
            $this->translator->trans(
                'connector.flash.created',
                ['%identifier%' => $client->getIdentifier()],
            ),
        );

        return $this->redirectToRoute('app_account');
    }

    #[Route('/account/connectors/{identifier}/revoke', name: 'app_connector_revoke', methods: ['POST'])]
    public function revoke(string $identifier, Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('revoke_connector_' . $identifier, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $client = $this->clients->find($identifier);

        // Clients without an owner belong to the operator, not to anyone here.
        if (!$client instanceof OAuthClient || !$client->belongsTo($user)) {
            throw $this->createNotFoundException('No such connector.');
        }

        // Tokens reference the client with ON DELETE CASCADE, so removing it
        // invalidates everything Claude still holds.
        $this->clients->remove($client);

        $this->addFlash('success', 'connector.flash.revoked');

        return $this->redirectToRoute('app_account');
    }

    /**
     * Takes the submitted list only if it is one the account actually has.
     * When Bring! cannot be asked the field was a free-text input, so the value
     * is kept as typed — a name that turns out not to exist surfaces as a clear
     * error on the first tool call.
     */
    private function resolveDefaultList(Request $request, User $user): ?string
    {
        $submitted = trim((string) $request->request->get('default_list', ''));

        if ('' === $submitted) {
            return null;
        }

        $available = $this->lists->listNamesFor($user);

        if (null !== $available && !in_array($submitted, $available, true)) {
            $this->addFlash(
                'error',
                $this->translator->trans(
                    'connector.flash.unknown_list',
                    ['%list%' => $submitted],
                ),
            );

            return null;
        }

        return mb_substr($submitted, 0, 120);
    }

    /**
     * Readable prefix plus enough randomness that identifiers cannot be
     * guessed. Stays well inside the 32 character column.
     */
    private static function generateIdentifier(): string
    {
        return 'claude-' . bin2hex(random_bytes(8));
    }
}
