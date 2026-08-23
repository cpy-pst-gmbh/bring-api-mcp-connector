<?php

declare(strict_types=1);

namespace App\EventSubscriber\OAuth;

use League\Bundle\OAuth2ServerBundle\Event\AccessTokenExtraClaimsResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the standard `scope` claim to issued access tokens.
 *
 * league/oauth2-server writes its own non-standard `scopes` array claim, which
 * generic JWT verifiers — FastMCP's among them — do not look at; they read
 * `scope` (RFC 9068) or `scp`. Emitting both keeps the token readable on the
 * resource server side without breaking anything that relies on `scopes`.
 *
 * The registered claims are off limits here: lcobucci/jwt rejects them as
 * extra claims, which is why the tokens still carry no `iss`.
 */
final class AccessTokenClaimsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [OAuth2Events::ACCESS_TOKEN_EXTRA_CLAIMS_RESOLVE => 'onExtraClaimsResolve'];
    }

    public function onExtraClaimsResolve(AccessTokenExtraClaimsResolveEvent $event): void
    {
        $scopes = array_map(
            static fn (object $scope): string => $scope->getIdentifier(),
            $event->getScopes(),
        );

        if ([] === $scopes) {
            return;
        }

        $event->setExtraClaims($event->getExtraClaims() + ['scope' => implode(' ', $scopes)]);
    }
}
