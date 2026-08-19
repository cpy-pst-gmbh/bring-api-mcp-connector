<?php

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Legal\LegalDocuments;
use App\Legal\LegalPage;
use App\Markdown\MarkdownFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * One environment variable per document, and it means two different things:
 * an address elsewhere is linked as it stands, anything else is a file we
 * serve ourselves. A file that is configured but unreadable has to leave the
 * link out rather than take the footer — and with it every page — down.
 */
#[CoversClass(LegalDocuments::class)]
final class LegalDocumentsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/legal-documents-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/legal', 0o777, true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir . '/legal/*') ?: []);
        @rmdir($this->dir . '/legal');
        @rmdir($this->dir);
    }

    public function testAnUnsetVariableLeavesTheLinkOut(): void
    {
        $documents = $this->documents(privacyPolicy: '');

        self::assertNull($documents->privacyPolicyHref());
        self::assertFalse($documents->isLocal(LegalPage::PrivacyPolicy));
    }

    public function testAnAddressElsewhereIsLinkedAsItStands(): void
    {
        $documents = $this->documents(privacyPolicy: 'https://example.com/privacy');

        self::assertSame('https://example.com/privacy', $documents->privacyPolicyHref());
        self::assertFalse($documents->isLocal(LegalPage::PrivacyPolicy));
        // Nothing to serve ourselves, so /privacy has nothing to render.
        self::assertNull($documents->html(LegalPage::PrivacyPolicy));
    }

    public function testALocalFileIsServedFromOurOwnRoute(): void
    {
        file_put_contents($this->dir . '/legal/privacy.md', '# Privacy');

        $documents = $this->documents(privacyPolicy: 'legal/privacy.md');

        self::assertSame('/privacy', $documents->privacyPolicyHref());
        self::assertTrue($documents->isLocal(LegalPage::PrivacyPolicy));
        self::assertStringContainsString('<h1>Privacy</h1>', (string) $documents->html(LegalPage::PrivacyPolicy));
    }

    /**
     * The case /health exists to report: configured, so the operator meant to
     * publish something, but unreadable. No link, no exception.
     */
    public function testAConfiguredButUnreadableFileLeavesTheLinkOutWithoutThrowing(): void
    {
        $documents = $this->documents(privacyPolicy: 'legal/missing.md');

        self::assertNull($documents->privacyPolicyHref());
        self::assertNull($documents->html(LegalPage::PrivacyPolicy));
        // Still local: that is what makes it worth reporting rather than
        // silently ignoring.
        self::assertTrue($documents->isLocal(LegalPage::PrivacyPolicy));
    }

    public function testTheTwoDocumentsAreConfiguredIndependently(): void
    {
        file_put_contents($this->dir . '/legal/imprint.md', '# Imprint');

        $documents = $this->documents(privacyPolicy: '', imprint: 'legal/imprint.md');

        self::assertNull($documents->privacyPolicyHref());
        self::assertSame('/imprint', $documents->imprintHref());
    }

    private function documents(string $privacyPolicy = '', string $imprint = ''): LegalDocuments
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $route): string => 'app_privacy_policy' === $route ? '/privacy' : '/imprint',
        );

        return new LegalDocuments(
            $privacyPolicy,
            $imprint,
            new MarkdownFile($this->dir, new NullLogger(), new ArrayAdapter()),
            $urls,
        );
    }
}
