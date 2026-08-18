<?php

declare(strict_types=1);

namespace App\Bring;

use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function is_string;
use function sprintf;

/**
 * The slice of Bring!'s API this app needs: checking that an email and password
 * belong to a Bring! account, and reading that account's list names.
 *
 * There is no official API and no PHP client for it. The endpoints and the API
 * key mirror what bring-api does on the Python side; if Bring changes either,
 * signing in here breaks the same way the MCP server does.
 */
final readonly class BringApiClient
{
    /**
     * @var array<string, string>
     */
    private array $headers;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire('%app.bring_login_timeout%')] private float $timeout,
        #[Autowire('%app.bring.api_key%')] string $apiKey,
        #[Autowire('%app.bring.client%')] string $client,
        #[Autowire('%app.bring.application%')] string $application,
        #[Autowire('%app.bring.country%')] string $country,
        #[Autowire('%app.bring.base_url%')] private string $baseUrl,
    ) {
        $this->headers = [
            'X-BRING-API-KEY' => $apiKey,
            'X-BRING-CLIENT' => $client,
            'X-BRING-APPLICATION' => $application,
            'X-BRING-COUNTRY' => $country,
        ];
    }

    /**
     * @throws BringUnreachableException when Bring cannot be asked, which is
     *                                   not the same as the password being wrong
     */
    public function verifyCredentials(string $email, #[SensitiveParameter] string $password): bool
    {
        return null !== $this->login($email, $password);
    }

    /**
     * Names of the account's shopping lists, in the order Bring! returns them —
     * the first one is what the MCP server falls back to.
     *
     * @return list<string>
     *
     * @throws BringUnreachableException
     */
    public function fetchListNames(string $email, #[SensitiveParameter] string $password): array
    {
        $session = $this->login($email, $password);

        if (null === $session) {
            throw new BringUnreachableException('Bring! rejected the stored credentials.');
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                $this->baseUrl . 'bringusers/' . rawurlencode($session['uuid']) . '/lists',
                [
                    'headers' => $this->headers + ['Authorization' => 'Bearer ' . $session['token']],
                    'timeout' => $this->timeout,
                ],
            );

            $payload = $response->toArray();
        } catch (HttpExceptionInterface $exception) {
            $this->logger->warning(
                'Could not read Bring! lists: {message}',
                [
                    'message' => $exception->getMessage(),
                ],
            );

            throw new BringUnreachableException('Bring! lists could not be read.', previous: $exception);
        }

        $names = [];

        foreach ($payload['lists'] ?? [] as $list) {
            if (isset($list['name']) && is_string($list['name']) && '' !== $list['name']) {
                $names[] = $list['name'];
            }
        }

        return $names;
    }

    /**
     * @return array{uuid: string, token: string}|null null when Bring! says the
     *                                                 credentials are wrong
     *
     * @throws BringUnreachableException
     */
    private function login(string $email, #[SensitiveParameter] string $password): ?array
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                $this->baseUrl . 'v2/bringauth',
                [
                    'headers' => $this->headers,
                    'body' => ['email' => $email, 'password' => $password],
                    'timeout' => $this->timeout,
                ],
            );

            $status = $response->getStatusCode();

            // 401 is a known address with the wrong password; 400 with the
            // body "Invalid Email." is an address Bring! does not have. Both
            // are verdicts on the credentials, and reporting the second as an
            // outage would tell everyone who mistypes their address that the
            // service is down.
            if (Response::HTTP_UNAUTHORIZED === $status || Response::HTTP_BAD_REQUEST === $status) {
                return null;
            }

            if (Response::HTTP_OK !== $status) {
                // Anything else — 5xx, a redirect to a login page, a changed
                // API — says nothing about the password, so it must not read
                // as a rejection.
                throw new BringUnreachableException(sprintf('Bring! answered with an unexpected status %d.', $status));
            }

            $payload = $response->toArray();
        } catch (HttpExceptionInterface $exception) {
            $this->logger->warning(
                'Bring login could not be performed: {message}',
                [
                    'message' => $exception->getMessage(),
                ],
            );

            throw new BringUnreachableException('Bring! could not be reached.', previous: $exception);
        }

        if (!isset($payload['uuid'], $payload['access_token'])) {
            throw new BringUnreachableException('Bring! login answered without a session.');
        }

        return ['uuid' => (string) $payload['uuid'], 'token' => (string) $payload['access_token']];
    }
}
