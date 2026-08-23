<?php

declare(strict_types=1);

namespace App\Service;

use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

use function file_get_contents;
use function filemtime;
use function is_file;
use function is_readable;
use function md5;
use function realpath;
use function str_starts_with;

/**
 * Turns an operator-supplied Markdown file into HTML.
 *
 * Three places take a path from the environment — the privacy policy, the
 * imprint and the mail signature — and all three want the same thing: resolve
 * it, read it, convert it, and do not fall over when it is not there.
 *
 * Never throws. These are all configuration, and a typo in one of them must
 * not take down the page or the email that merely decorates itself with the
 * result.
 */
final readonly class MarkdownFileService
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        private LoggerInterface $logger,
        private CacheInterface $cache,
    ) {
    }

    /**
     * The readable file behind a configured path, or null.
     *
     * @param string $variable name of the environment variable, so a warning
     *                         can say which one is wrong
     */
    public function resolve(string $path, string $variable): ?string
    {
        if ('' === $path) {
            return null;
        }

        // A relative path is read from the project directory, so the same value
        // means the same thing in the container and in a checkout.
        $candidate = str_starts_with($path, '/') ? $path : $this->projectDir . '/' . $path;
        $resolved = realpath($candidate);

        if (false === $resolved || false === is_file($resolved) || false === is_readable($resolved)) {
            $this->logger->warning(
                '{variable} points at a file that cannot be read.',
                [
                    'variable' => $variable,
                    'path' => $candidate,
                ],
            );

            return null;
        }

        return $resolved;
    }

    /**
     * Rendered HTML for a resolved file, or null when it cannot be read.
     *
     * Cached against the file's mtime, so a replaced document is picked up
     * without a restart and without anything to clear.
     */
    public function render(string $file): ?string
    {
        $key = 'markdown.' . md5($file) . '.' . (filemtime($file) ?: 0);

        return $this->cache->get(
            $key,
            function () use ($file): ?string {
                $markdown = file_get_contents($file);

                if (false === $markdown) {
                    return null;
                }

                try {
                    return new GithubFlavoredMarkdownConverter()->convert($markdown)->getContent();
                } catch (CommonMarkException $exception) {
                    $this->logger->error(
                        'Markdown could not be rendered: {message}',
                        [
                            'path' => $file,
                            'message' => $exception->getMessage(),
                        ],
                    );

                    return null;
                }
            },
        );
    }

    /**
     * Resolve and render in one step.
     */
    public function html(string $path, string $variable): ?string
    {
        $file = $this->resolve($path, $variable);

        return null === $file ? null : $this->render($file);
    }
}
