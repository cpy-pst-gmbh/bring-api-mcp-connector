<?php

declare(strict_types=1);

namespace App\Controller;

use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Discovery documents the OAuth2 bundle does not ship itself.
 *
 * The MCP server needs both: the metadata to learn where /authorize and /token
 * live, and the JWKS to verify the access tokens Symfony signs.
 */
final class WellKnownController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(resolve:OAUTH_PUBLIC_KEY)%')]
        private readonly string $publicKeyPath,
    ) {
    }

    /**
     * RFC 8414. The issuer is derived from the incoming request, so it stays
     * correct in dev and behind the reverse proxy — provided framework.yaml
     * lists the proxy under trusted_proxies.
     */
    #[Route('/.well-known/oauth-authorization-server', name: 'app_oauth_metadata', methods: ['GET'])]
    public function authorizationServerMetadata(Request $request): JsonResponse
    {
        $issuer = $request->getSchemeAndHttpHost();

        return $this->cacheable([
            'issuer' => $issuer,
            'authorization_endpoint' => $this->absolute('oauth2_authorize'),
            'token_endpoint' => $this->absolute('oauth2_token'),
            'jwks_uri' => $this->absolute('app_oauth_jwks'),
            'scopes_supported' => ['bring'],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]);
    }

    #[Route('/.well-known/jwks.json', name: 'app_oauth_jwks', methods: ['GET'])]
    public function jwks(): JsonResponse
    {
        $pem = @file_get_contents($this->publicKeyPath);

        if (false === $pem) {
            throw new RuntimeException(sprintf('OAuth public key not readable at "%s". Run league:oauth2-server:generate-keypair.', $this->publicKeyPath));
        }

        $key = openssl_pkey_get_public($pem);

        if (false === $key) {
            throw new RuntimeException('OAuth public key is not a valid PEM.');
        }

        $details = openssl_pkey_get_details($key);

        if (!isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('OAuth public key is not RSA.');
        }

        $n = self::base64Url($details['rsa']['n']);
        $e = self::base64Url($details['rsa']['e']);

        return $this->cacheable([
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => self::thumbprint($n, $e),
                'n' => $n,
                'e' => $e,
            ]],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function cacheable(array $payload): JsonResponse
    {
        $response = new JsonResponse($payload);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    private function absolute(string $route): string
    {
        return $this->generateUrl($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * RFC 7638 thumbprint. league/oauth2-server does not put a kid in the token
     * header, so nothing depends on this value — it is here so that adding a
     * second key later does not change the shape of the document.
     */
    private static function thumbprint(string $n, string $e): string
    {
        return self::base64Url(hash('sha256', json_encode([
            'e' => $e,
            'kty' => 'RSA',
            'n' => $n,
        ], JSON_THROW_ON_ERROR), true));
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
