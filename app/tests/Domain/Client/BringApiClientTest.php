<?php

declare(strict_types=1);

namespace App\Tests\Domain\Client;

use App\Domain\Client\BringApiClient;
use App\Domain\Exception\BringUnreachableException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function explode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The client is where a status code turns into a verdict, and the difference
 * between "wrong password" and "Bring! is down" is the whole magic-link
 * fallback: a rejection sends the user back to the form, an outage offers them
 * a sign-in link instead. Getting that mapping wrong is silent — the login
 * still works for everyone whose password is right.
 *
 * Bring! has no official API and none of this can be asserted against the real
 * one from a unit test, so what is pinned here is the app's side of the
 * contract. Whether Bring! still keeps its half is what the end-to-end run with
 * real credentials answers.
 */
#[CoversClass(BringApiClient::class)]
final class BringApiClientTest extends TestCase
{
    private const string BASE_URL = 'https://bring.test/rest/';

    public function testConfirmsCredentialsWhenBringAnswersWithASession(): void
    {
        $client = self::client(new MockHttpClient(self::session()));

        self::assertTrue($client->verifyCredentials('user@example.test', 'hunter2'));
    }

    public function testPostsTheCredentialsAsAFormWithTheBringHeaders(): void
    {
        $seen = [];

        $client = self::client(new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$seen): ResponseInterface {
                $seen = ['method' => $method, 'url' => $url, 'options' => $options];

                return self::session();
            },
        ));

        $client->verifyCredentials('user@example.test', 'hunter2');

        self::assertSame('POST', $seen['method']);
        self::assertSame(self::BASE_URL . 'v2/bringauth', $seen['url']);

        $headers = self::headers($seen['options']);
        self::assertSame('api-key', $headers['x-bring-api-key']);
        self::assertSame('android', $headers['x-bring-client']);
        self::assertSame('bring', $headers['x-bring-application']);
        self::assertSame('DE', $headers['x-bring-country']);

        self::assertSame(['email' => 'user@example.test', 'password' => 'hunter2'], self::form($seen['options']));
    }

    /**
     * 401 is a known address with the wrong password. A verdict, not an outage.
     */
    public function testRejectsAWrongPassword(): void
    {
        $client = self::client(new MockHttpClient(new MockResponse('', ['http_code' => 401])));

        self::assertFalse($client->verifyCredentials('user@example.test', 'wrong'));
    }

    /**
     * 400 "Invalid Email." is an address Bring! does not have. Reading it as an
     * outage would tell everyone who mistypes their address that the service is
     * broken, and would offer them a sign-in link for an account that does not
     * exist.
     */
    public function testRejectsAnUnknownAddress(): void
    {
        $client = self::client(new MockHttpClient(
            new MockResponse('Invalid Email.', ['http_code' => 400]),
        ));

        self::assertFalse($client->verifyCredentials('nobody@example.test', 'hunter2'));
    }

    /**
     * Anything outside 200/400/401 says nothing about the password — a gateway
     * error, a rate limit, a login page where JSON was expected. None of those
     * may read as "your password is wrong".
     */
    #[TestWith([500])]
    #[TestWith([502])]
    #[TestWith([429])]
    #[TestWith([418])]
    public function testTreatsAnUnexpectedStatusAsUnreachable(int $status): void
    {
        $client = self::client(new MockHttpClient(new MockResponse('', ['http_code' => $status])));

        $this->expectException(BringUnreachableException::class);

        $client->verifyCredentials('user@example.test', 'hunter2');
    }

    public function testTreatsATransportFailureAsUnreachable(): void
    {
        $client = self::client(new MockHttpClient(
            static fn (): ResponseInterface => throw new TransportException('Connection timed out.'),
        ));

        $this->expectException(BringUnreachableException::class);

        $client->verifyCredentials('user@example.test', 'hunter2');
    }

    /**
     * A 200 that carries no session is a changed API, not a rejection.
     */
    #[TestWith(['{}'])]
    #[TestWith(['{"uuid":"user-uuid"}'])]
    #[TestWith(['{"access_token":"token"}'])]
    public function testTreatsAnAnswerWithoutASessionAsUnreachable(string $body): void
    {
        $client = self::client(new MockHttpClient(self::json($body)));

        $this->expectException(BringUnreachableException::class);

        $client->verifyCredentials('user@example.test', 'hunter2');
    }

    public function testTreatsAnUndecodableAnswerAsUnreachable(): void
    {
        $client = self::client(new MockHttpClient(self::json('<html>not json</html>')));

        $this->expectException(BringUnreachableException::class);

        $client->verifyCredentials('user@example.test', 'hunter2');
    }

    public function testReadsTheListNamesInOrder(): void
    {
        $client = self::client(new MockHttpClient([
            self::session(),
            self::json('{"lists":[{"name":"Shopping"},{"name":"Hardware store"}]}'),
        ]));

        self::assertSame(
            ['Shopping', 'Hardware store'],
            $client->fetchListNames('user@example.test', 'hunter2'),
        );
    }

    /**
     * The names end up in a select and in the connector's stored default, so an
     * entry without a usable one has to drop out rather than become "".
     */
    public function testSkipsListsWithoutAUsableName(): void
    {
        $client = self::client(new MockHttpClient([
            self::session(),
            self::json('{"lists":[{"name":"Shopping"},{"name":""},{"listUuid":"no-name"},{"name":42},{"name":"Garden"}]}'),
        ]));

        self::assertSame(['Shopping', 'Garden'], $client->fetchListNames('user@example.test', 'hunter2'));
    }

    public function testReturnsAnEmptyListWhenTheAccountHasNone(): void
    {
        $client = self::client(new MockHttpClient([
            self::session(),
            self::json('{"lists":[]}'),
        ]));

        self::assertSame([], $client->fetchListNames('user@example.test', 'hunter2'));
    }

    /**
     * The uuid comes back from Bring! and goes straight into a URL path, so it
     * has to be escaped there — otherwise a value with a slash in it addresses
     * a different endpoint than the one meant.
     */
    public function testAsksForTheListsWithTheSessionToken(): void
    {
        $seen = [];

        $client = self::client(new MockHttpClient([
            self::json('{"uuid":"a/b","access_token":"token"}'),
            static function (string $method, string $url, array $options) use (&$seen): ResponseInterface {
                $seen = ['method' => $method, 'url' => $url, 'options' => $options];

                return self::json('{"lists":[]}');
            },
        ]));

        $client->fetchListNames('user@example.test', 'hunter2');

        self::assertSame('GET', $seen['method']);
        self::assertSame(self::BASE_URL . 'bringusers/a%2Fb/lists', $seen['url']);

        $headers = self::headers($seen['options']);
        self::assertSame('Bearer token', $headers['authorization']);
        self::assertSame('api-key', $headers['x-bring-api-key']);
    }

    /**
     * `fetchListNames` has no way to say "wrong password" — its caller only
     * wants names or nothing. A rejection therefore surfaces as unreachable,
     * which the list service turns into "no dropdown" rather than an error.
     */
    public function testRefusesToReadListsWhenTheCredentialsAreRejected(): void
    {
        $client = self::client(new MockHttpClient(new MockResponse('', ['http_code' => 401])));

        $this->expectException(BringUnreachableException::class);

        $client->fetchListNames('user@example.test', 'wrong');
    }

    public function testTreatsAFailedListReadAsUnreachable(): void
    {
        $client = self::client(new MockHttpClient([
            self::session(),
            new MockResponse('', ['http_code' => 500]),
        ]));

        $this->expectException(BringUnreachableException::class);

        $client->fetchListNames('user@example.test', 'hunter2');
    }

    private static function client(MockHttpClient $http): BringApiClient
    {
        return new BringApiClient(
            $http,
            new NullLogger(),
            1.0,
            'api-key',
            'android',
            'bring',
            'DE',
            self::BASE_URL,
        );
    }

    private static function session(): MockResponse
    {
        return self::json(json_encode(
            ['uuid' => 'user-uuid', 'access_token' => 'token'],
            JSON_THROW_ON_ERROR,
        ));
    }

    private static function json(string $body): MockResponse
    {
        return new MockResponse($body, [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, string>
     */
    private static function headers(array $options): array
    {
        $headers = [];

        /** @var array<string, list<string>> $normalized */
        $normalized = $options['normalized_headers'] ?? [];

        foreach ($normalized as $name => $lines) {
            $headers[$name] = explode(': ', $lines[0], 2)[1] ?? '';
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, string>
     */
    private static function form(array $options): array
    {
        $form = [];
        parse_str((string) $options['body'], $form);

        /** @var array<string, string> $form */
        return $form;
    }
}
