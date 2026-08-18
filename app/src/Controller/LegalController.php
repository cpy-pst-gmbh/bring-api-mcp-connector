<?php

declare(strict_types=1);

namespace App\Controller;

use App\Legal\LegalDocuments;
use App\Legal\LegalPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function sprintf;

/**
 * Serves the privacy policy and the imprint when they are kept here as
 * Markdown. Both routes exist unconditionally and answer 404 while the
 * matching variable is unset or points somewhere else — the alternative,
 * registering routes from configuration, hides them from debug:router and
 * makes a typo look like a missing feature.
 */
final class LegalController extends AbstractController
{
    public function __construct(private readonly LegalDocuments $documents)
    {
    }

    #[Route('/privacy', name: 'app_privacy_policy', methods: ['GET'])]
    public function privacyPolicy(): Response
    {
        return $this->document(LegalPage::PrivacyPolicy);
    }

    #[Route('/imprint', name: 'app_imprint', methods: ['GET'])]
    public function imprint(): Response
    {
        return $this->document(LegalPage::Imprint);
    }

    private function document(LegalPage $page): Response
    {
        $html = $this->documents->html($page);

        if (null === $html) {
            throw $this->createNotFoundException(sprintf('No local document configured for %s.', $page->value));
        }

        return $this->render('legal/document.html.twig', [
            'title' => $page->titleKey(),
            'content' => $html,
        ]);
    }
}
