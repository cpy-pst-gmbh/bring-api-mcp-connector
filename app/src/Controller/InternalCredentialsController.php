<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\OAuthClient;
use App\Entity\User;
use App\Security\CredentialCipher;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Hands the MCP server the Bring! credentials of the token's subject.
 *
 * Authenticated by the same access token the MCP server received from Claude,
 * so the caller can only ever reach the credentials of that one user. Keeping
 * the Bring! password out of the token claims means it never travels through
 * Anthropic's infrastructure.
 *
 * This route belongs on the Docker network only — do not give it a ProxyPass
 * rule.
 */
#[IsGranted('ROLE_OAUTH2_BRING')]
final class InternalCredentialsController extends AbstractController
{
    #[Route('/internal/bring-credentials', name: 'app_internal_bring_credentials', methods: ['GET'])]
    public function show(
        #[CurrentUser] User $user,
        CredentialCipher $cipher,
        Security $security,
        ClientManagerInterface $clients,
    ): JsonResponse {
        $credential = $user->getBringCredential();

        if (null === $credential) {
            return new JsonResponse([
                'error' => 'no_credentials',
                'error_description' => 'This account has no Bring! credentials stored yet.',
            ], Response::HTTP_NOT_FOUND);
        }

        $response = new JsonResponse([
            'username' => $credential->getUsername(),
            'password' => $cipher->decrypt($credential->getPasswordCipher()),
            // Resolved here rather than in the MCP server: the token names the
            // client in `aud`, and which list that connector defaults to is
            // this side's business.
            'list_name' => $this->defaultListFor($security, $clients, $user),
        ]);

        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    private function defaultListFor(Security $security, ClientManagerInterface $clients, User $user): ?string
    {
        $token = $security->getToken();

        if (!$token instanceof OAuth2Token) {
            return null;
        }

        $client = $clients->find($token->getOAuthClientId());

        // A client the user does not own — an operator-made one — carries no
        // preference of theirs, so the account's first list applies.
        if (!$client instanceof OAuthClient || !$client->belongsTo($user)) {
            return null;
        }

        return $client->getDefaultListName();
    }
}
