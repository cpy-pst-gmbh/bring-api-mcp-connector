<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Client\BringApiClient;
use App\Domain\Exception\BringUnreachableException;
use App\Entity\BringCredential;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Signs users in with their Bring! credentials, and only those.
 *
 * Bring! is the authority on whether the password is right, so there is no
 * local password to check. A first successful sign-in creates the account; a
 * later one refreshes the stored password whenever the user changed it at
 * Bring!, which keeps the copy the MCP server uses from going stale.
 *
 * When Bring! cannot be reached the login is neither accepted nor rejected —
 * the user is pointed at the login link instead.
 *
 * The Bring! call sits in a CustomCredentials badge rather than in
 * authenticate() so that login throttling actually applies: the throttling
 * listener both counts and enforces on the passport, and a passport that never
 * gets built is a rate limit that never fires.
 */
final class BringLoginAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly BringApiClient $bring,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly CredentialCipher $cipher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $email = trim($request->request->getString('_username'));
        $password = $request->request->getString('_password');
        $csrfToken = $request->request->getString('_csrf_token');

        if (false === $this->csrfTokenManager->isTokenValid(new CsrfToken('authenticate', $csrfToken))) {
            throw new CustomUserMessageAuthenticationException('login.error.invalid_csrf');
        }

        if ('' === $email || '' === $password) {
            throw new CustomUserMessageAuthenticationException('login.error.missing_credentials');
        }

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            // An address nobody has used yet gets a User that is not persisted
            // — the account only becomes real once Bring! has confirmed the
            // password, which happens in the credentials check below.
            new UserBadge(
                $email,
                fn (string $identifier): User => $this->users->findOneByEmail($identifier) ?? new User()->setEmail($identifier),
            ),
            new CustomCredentials(
                fn (string $attempt, User $user): bool => $this->verify($user, $attempt),
                $password,
            ),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Nothing was written while the password was still unproven; this is
        // where a first-time account and a refreshed password become permanent.
        $user = $token->getUser();

        if ($user instanceof User) {
            $this->em->persist($user);

            if (null !== $credential = $user->getBringCredential()) {
                $this->em->persist($credential);
            }

            $this->em->flush();
        }

        if ($target = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($target);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_account'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->getLoginUrl($request));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate('app_login');
    }

    /**
     * Asks Bring! whether this password belongs to this address, and prepares
     * what should be stored if it does.
     *
     * Preparing here rather than after the fact is deliberate: this is the one
     * moment the plaintext is both known and confirmed correct. Nothing reaches
     * the database until onAuthenticationSuccess flushes.
     */
    private function verify(User $user, #[SensitiveParameter] string $password): bool
    {
        try {
            $accepted = $this->bring->verifyCredentials($user->getEmail(), $password);
        } catch (BringUnreachableException $exception) {
            throw new CustomUserMessageAuthenticationException('login.error.bring_unreachable', previous: $exception);
        }

        if (!$accepted) {
            return false;
        }

        $this->rememberPassword($user, $password);

        return true;
    }

    /**
     * Stores the password when it is new, and re-encrypts it when the user
     * changed it at Bring! since the last sign-in.
     */
    private function rememberPassword(User $user, #[SensitiveParameter] string $password): void
    {
        $credential = $user->getBringCredential();

        if (null === $credential) {
            $credential = new BringCredential();
            $credential->setUser($user);
            $credential->setPlainPassword($password);
            $user->setBringCredential($credential);

            return;
        }

        if ($this->storedPasswordMatches($credential, $password)) {
            return;
        }

        // BringCredentialListener re-encrypts whenever a plaintext is set, and
        // touch() keeps the entity dirty so preUpdate actually fires.
        $credential->setPlainPassword($password);
        $credential->touch();

        $this->logger->info(
            'Bring! password changed for {email}, stored copy refreshed.',
            [
                'email' => $user->getEmail(),
            ],
        );
    }

    /**
     * A stored copy that cannot be decrypted — key rotated, row tampered with —
     * counts as a mismatch, so this sign-in simply replaces it.
     */
    private function storedPasswordMatches(BringCredential $credential, #[SensitiveParameter] string $password): bool
    {
        try {
            return hash_equals($this->cipher->decrypt($credential->getPasswordCipher()), $password);
        } catch (RuntimeException) {
            return false;
        }
    }
}
