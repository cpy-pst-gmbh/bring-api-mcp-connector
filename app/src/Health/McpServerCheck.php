<?php

declare(strict_types=1);

namespace App\Health;

use App\Domain\Interfaces\HealthCheckInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function strlen;

use const PHP_URL_PATH;

/**
 * Asks the Python MCP server for its protected-resource metadata.
 *
 * That document is public and cheap, and a 200 proves more than a TCP connect:
 * the process is up, routing works, and it knows which authorization server it
 * belongs to — which is checked here, because a mismatch means Claude would be
 * sent somewhere else to sign in.
 */
final readonly class McpServerCheck implements HealthCheckInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire('%app.mcp_health_url%')]
        private string $healthUrl,
        #[Autowire('%app.mcp_endpoint%')]
        private string $mcpEndpoint,
    ) {
    }

    public function name(): string
    {
        return 'mcp_server';
    }

    public function label(): string
    {
        return 'health.check.mcp_server';
    }

    public function run(): HealthResult
    {
        $url = $this->resolveUrl();

        if (null === $url) {
            return HealthResult::skipped('health.detail.mcp_not_configured');
        }

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 5.0]);
            $status = $response->getStatusCode();

            if (200 !== $status) {
                return HealthResult::failed('health.detail.mcp_status', ['%status%' => $status]);
            }

            $payload = $response->toArray();
        } catch (HttpExceptionInterface $exception) {
            // The message names the internal URL, which this public endpoint
            // has no business publishing.
            $this->logger->error('MCP health check failed: {message}', [
                'message' => $exception->getMessage(),
            ]);

            return HealthResult::failed('health.detail.mcp_unreachable');
        }

        if (!isset($payload['resource'])) {
            return HealthResult::failed('health.detail.mcp_malformed');
        }

        return HealthResult::ok();
    }

    /**
     * Prefers an explicit internal URL; otherwise derives the metadata document
     * from the public MCP endpoint, which is where FastMCP serves it.
     */
    private function resolveUrl(): ?string
    {
        if ('' !== $this->healthUrl) {
            return $this->healthUrl;
        }

        if ('' === $this->mcpEndpoint) {
            return null;
        }

        $endpoint = rtrim($this->mcpEndpoint, '/');
        $path = parse_url($endpoint, PHP_URL_PATH) ?: '/mcp';

        return substr($endpoint, 0, -strlen($path)) . '/.well-known/oauth-protected-resource' . $path;
    }
}
