<?php

declare(strict_types=1);

namespace App\EventSubscriber\OAuth;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function is_array;

/**
 * Puts a consent screen in front of the bundle's /authorize endpoint.
 *
 * The bundle would otherwise approve every request outright. On the first pass
 * this stashes the authorization request and redirects to /consent; the consent
 * controller writes the user's decision to the session and sends them back here,
 * where the decision is consumed and handed to the authorization server.
 *
 * The decision is deliberately one-shot: reconnecting the connector asks again.
 */
final class ConsentSubscriber implements EventSubscriberInterface
{
    public const string PENDING_URI = 'oauth_consent.pending_uri';
    public const string PENDING_REQUEST = 'oauth_consent.pending_request';
    public const string DECISION = 'oauth_consent.decision';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE => 'onAuthorizationRequest'];
    }

    public function onAuthorizationRequest(AuthorizationRequestResolveEvent $event): void
    {
        $session = $this->requestStack->getSession();
        $client = $event->getClient();
        $clientId = $client->getIdentifier();
        $scopes = self::normalizeScopes($event->getScopes());

        $decision = $session->get(self::DECISION);

        if (is_array($decision)
            && ($decision['client'] ?? null) === $clientId
            && ($decision['scopes'] ?? null) === $scopes
        ) {
            $session->remove(self::DECISION);
            $session->remove(self::PENDING_URI);
            $session->remove(self::PENDING_REQUEST);

            $event->resolveAuthorization(
                true === ($decision['approved'] ?? false)
                    ? AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED
                    : AuthorizationRequestResolveEvent::AUTHORIZATION_DENIED
            );

            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        $session->set(self::PENDING_URI, null !== $request ? $request->getRequestUri() : '/');
        $session->set(self::PENDING_REQUEST, [
            'client' => $clientId,
            'client_name' => $client instanceof AbstractClient ? $client->getName() : $clientId,
            'scopes' => $scopes,
        ]);

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_consent')));
    }

    /**
     * @param object[] $scopes
     *
     * @return list<string>
     */
    private static function normalizeScopes(array $scopes): array
    {
        $names = array_map(static fn (object $scope): string => (string) $scope, $scopes);
        sort($names);

        return array_values($names);
    }
}
