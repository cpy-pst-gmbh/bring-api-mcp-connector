<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Config\LegalPage;
use App\Service\LegalDocumentService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

use function sprintf;

/**
 * Serves the privacy policy and the imprint when they are kept here as
 * Markdown. Both routes exist unconditionally and answer 404 while the
 * matching variable is unset or points somewhere else — the alternative,
 * registering routes from configuration, hides them from debug:router and
 * makes a typo look like a missing feature.
 */
final readonly class LegalController
{
    public function __construct(private LegalDocumentService $documents)
    {
    }

    #[Route('/privacy', name: 'app_privacy_policy', defaults: ['type' => 'privacy_policy'], methods: ['GET'])]
    #[Route('/imprint', name: 'app_imprint', defaults: ['type' => 'imprint'], methods: ['GET'])]
    #[Template('legal/document.html.twig')]
    public function privacyPolicy(string $type): array|RedirectResponse
    {
        $page = LegalPage::from($type);

        if (false === $this->documents->isLocal($page)) {
            return new RedirectResponse($this->documents->href($page));
        }

        $html = $this->documents->html($page);

        if (null === $html) {
            throw new NotFoundHttpException(sprintf('No local document configured for %s.', $page->value));
        }

        return [
            'title' => $page->titleKey(),
            'content' => $html,
        ];
    }
}
