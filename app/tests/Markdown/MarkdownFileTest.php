<?php

declare(strict_types=1);

namespace App\Tests\Markdown;

use App\Service\MarkdownFileService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Three environment variables point at Markdown files, all of them optional
 * and all of them typed by hand. Every path through here has to end in null
 * rather than an exception: a footer link is not worth an error page, and a
 * signature is not worth an undelivered email.
 */
#[CoversClass(MarkdownFileService::class)]
final class MarkdownFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/markdown-file-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/legal', 0o777, true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir . '/legal/*') ?: []);
        @rmdir($this->dir . '/legal');
        @rmdir($this->dir);
    }

    public function testAnUnsetVariableResolvesToNothing(): void
    {
        self::assertNull($this->markdownFile()->resolve('', 'PRIVACY_POLICY_URL'));
    }

    public function testAMissingFileResolvesToNothingInsteadOfThrowing(): void
    {
        self::assertNull($this->markdownFile()->resolve('legal/nowhere.md', 'PRIVACY_POLICY_URL'));
    }

    /**
     * The same value has to mean the same file in a container and in a
     * checkout, so a relative path is read from the project directory rather
     * than the working directory.
     */
    public function testARelativePathIsReadFromTheProjectDirectory(): void
    {
        $this->write('legal/privacy.md', '# Privacy');

        self::assertSame(
            realpath($this->dir . '/legal/privacy.md'),
            $this->markdownFile()->resolve('legal/privacy.md', 'PRIVACY_POLICY_URL'),
        );
    }

    public function testAnAbsolutePathIsTakenAsItIs(): void
    {
        $file = $this->write('legal/imprint.md', '# Imprint');

        self::assertSame($file, $this->markdownFile()->resolve($file, 'IMPRINT_URL'));
    }

    public function testADirectoryIsNotAFile(): void
    {
        self::assertNull($this->markdownFile()->resolve('legal', 'PRIVACY_POLICY_URL'));
    }

    public function testItRendersGithubFlavouredMarkdown(): void
    {
        $this->write('legal/privacy.md', "# Privacy\n\n| a | b |\n| - | - |\n| 1 | 2 |\n");

        $html = $this->markdownFile()->html('legal/privacy.md', 'PRIVACY_POLICY_URL');

        self::assertNotNull($html);
        self::assertStringContainsString('<h1>Privacy</h1>', $html);
        // Tables are the GFM extension, not plain CommonMark.
        self::assertStringContainsString('<table>', $html);
    }

    /**
     * The cache key carries the mtime so a replaced document is picked up
     * without a restart and without anything to clear.
     */
    public function testAReplacedFileIsRenderedAgain(): void
    {
        $file = $this->write('legal/privacy.md', '# First');
        $markdown = $this->markdownFile();

        self::assertStringContainsString('First', (string) $markdown->render($file));

        $this->write('legal/privacy.md', '# Second');
        touch($file, time() + 10);
        clearstatcache(true, $file);

        self::assertStringContainsString('Second', (string) $markdown->render($file));
    }

    private function markdownFile(): MarkdownFileService
    {
        return new MarkdownFileService($this->dir, new NullLogger(), new ArrayAdapter());
    }

    private function write(string $path, string $contents): string
    {
        file_put_contents($this->dir . '/' . $path, $contents);

        return realpath($this->dir . '/' . $path);
    }
}
