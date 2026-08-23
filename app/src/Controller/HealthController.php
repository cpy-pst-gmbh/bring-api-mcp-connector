<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Interface\HealthCheckInterface;
use App\Domain\Model\HealthResult;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

use const DATE_ATOM;

/**
 * State of the app and the things it depends on.
 *
 * `/health` renders a page a person can read; `/health.json` returns the same
 * data for a monitor. Both answer 200 or 503, so a check can be wired up on the
 * status code alone without parsing anything.
 *
 * Reachable without authentication. Failure reasons are deliberately terse —
 * the full message goes to the log.
 */
final class HealthController extends AbstractController
{
    /**
     * @param iterable<HealthCheckInterface> $checks
     */
    public function __construct(
        #[AutowireIterator('app.health_check')] private readonly iterable $checks,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        '/health.{_format}',
        name: 'app_health',
        requirements: ['_format' => 'html|json'],
        defaults: ['_format' => 'html'],
        methods: ['GET']
    )]
    public function index(string $_format): Response
    {
        $results = [];
        $healthy = true;

        foreach ($this->checks as $check) {
            $result = $check->run();
            $results[$check->name()] = ['label' => $check->label(), 'result' => $result];

            $healthy = $healthy && !$result->isFailure();
        }

        ksort($results);

        $status = $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        $response = 'json' === $_format
            ? $this->asJson($results, $status, $healthy)
            : $this->asPage($results, $status, $healthy);

        // A cached health check is worse than none.
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * @param array<string, array{label: string, result: HealthResult}> $results
     */
    private function asJson(array $results, int $status, bool $healthy): JsonResponse
    {
        $checks = [];

        foreach ($results as $name => $check) {
            $result = $check['result'];
            $checks[$name] = ['status' => $result->status];

            if (null !== $result->detail) {
                // Rendered rather than passed through as a key: a monitor
                // reading this wants a sentence, not something to look up.
                $checks[$name]['detail'] = $this->translator->trans($result->detail, $result->detailParameters);
            }
        }

        return new JsonResponse(
            [
                'status' => $healthy ? HealthResult::OK : HealthResult::FAILED,
                'checked_at' => new DateTimeImmutable()->format(DATE_ATOM),
                'checks' => $checks,
            ], $status,
        );
    }

    /**
     * @param array<string, array{label: string, result: HealthResult}> $results
     */
    private function asPage(array $results, int $status, bool $healthy): Response
    {
        return $this->render(
            'health/index.html.twig',
            [
                'healthy' => $healthy,
                'checks' => $results,
                'checked_at' => new DateTimeImmutable(),
            ],
            new Response('', $status),
        );
    }
}
