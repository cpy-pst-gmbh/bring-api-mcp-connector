<?php

declare(strict_types=1);

namespace App\Legal;

use App\Markdown\MarkdownFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function str_starts_with;

/**
 * Resolves the privacy policy and the imprint, either of which may be hosted
 * somewhere else or kept here as a Markdown file.
 *
 * One value decides both: anything starting with a scheme is linked as it is,
 * everything else is read from disk and served from our own route. Operators
 * who already have these pages elsewhere keep pointing at them; operators who
 * do not can drop two files into a mounted directory.
 *
 * A configured file that cannot be read is not an exception. It would take
 * every page of the app down over a footer link, so the link is left out and
 * the misconfiguration is reported by /health instead.
 */
final class LegalDocuments
{
    /**
     * @var array<string, string|null> resolved paths, keyed by page
     */
    private array $paths = [];

    public function __construct(
        #[Autowire('%app.privacy_policy_url%')] private readonly string $privacyPolicy,
        #[Autowire('%app.imprint_url%')] private readonly string $imprint,
        private readonly MarkdownFile $markdown,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Where the footer link should point, or null when there is nothing to
     * link to.
     */
    public function href(LegalPage $page): ?string
    {
        $configured = $this->configured($page);

        if ('' === $configured) {
            return null;
        }

        if ($this->isExternal($configured)) {
            return $configured;
        }

        return null !== $this->file($page) ? $this->urls->generate($page->route()) : null;
    }

    // Twig reads globals as properties, and an enum cannot be spelled in a
    // template without constant() — so the two cases get a name each.
    public function privacyPolicyHref(): ?string
    {
        return $this->href(LegalPage::PrivacyPolicy);
    }

    public function imprintHref(): ?string
    {
        return $this->href(LegalPage::Imprint);
    }

    /**
     * The document as HTML, or null when this page is not kept locally.
     */
    public function html(LegalPage $page): ?string
    {
        $file = $this->file($page);

        return null === $file ? null : $this->markdown->render($file);
    }

    /**
     * True when the value names a file rather than an address elsewhere —
     * which is what makes a missing file worth reporting.
     */
    public function isLocal(LegalPage $page): bool
    {
        $configured = $this->configured($page);

        return '' !== $configured && !$this->isExternal($configured);
    }

    /**
     * The readable file behind a page, or null when there is none.
     */
    public function file(LegalPage $page): ?string
    {
        if (!$this->isLocal($page)) {
            return null;
        }

        return $this->paths[$page->value] ??= $this->markdown->resolve(
            $this->configured($page),
            $page->variable(),
        );
    }

    private function configured(LegalPage $page): string
    {
        return match ($page) {
            LegalPage::PrivacyPolicy => $this->privacyPolicy,
            LegalPage::Imprint => $this->imprint,
        };
    }

    private function isExternal(string $value): bool
    {
        return str_starts_with($value, 'https://') || str_starts_with($value, 'http://');
    }
}
