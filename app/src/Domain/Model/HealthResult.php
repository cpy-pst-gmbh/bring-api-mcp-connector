<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Outcome of a single check.
 *
 * `skipped` exists so an optional dependency that was never configured does not
 * read as broken — the endpoint says what it did and did not look at.
 *
 * `detail` is a translation key, not a sentence: the page renders it in the
 * visitor's locale, and the JSON endpoint renders it in the default one so a
 * monitor still gets something readable rather than a key.
 */
final readonly class HealthResult
{
    public const string OK = 'ok';
    public const string FAILED = 'failed';
    public const string SKIPPED = 'skipped';

    /**
     * @param array<string, string|int> $detailParameters
     */
    private function __construct(
        public string $status,
        public ?string $detail = null,
        public array $detailParameters = [],
    ) {
    }

    /**
     * @param array<string, string|int> $parameters
     */
    public static function ok(?string $detail = null, array $parameters = []): self
    {
        return new self(self::OK, $detail, $parameters);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    public static function failed(string $detail, array $parameters = []): self
    {
        return new self(self::FAILED, $detail, $parameters);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    public static function skipped(string $detail, array $parameters = []): self
    {
        return new self(self::SKIPPED, $detail, $parameters);
    }

    public function isFailure(): bool
    {
        return self::FAILED === $this->status;
    }
}
