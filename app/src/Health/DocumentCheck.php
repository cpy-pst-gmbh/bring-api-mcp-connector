<?php

declare(strict_types=1);

namespace App\Health;

use App\Domain\Interfaces\HealthCheckInterface;
use App\Legal\LegalDocuments;
use App\Legal\LegalPage;
use App\Markdown\MarkdownFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function implode;

/**
 * Confirms that every Markdown file the configuration points at is there.
 *
 * The privacy policy, the imprint and the mail signature all fail the same
 * quiet way: the link is left out, or the email goes out unsigned, and nothing
 * in the app ever says so. All three tend to carry the details an operator is
 * obliged to publish, which makes a silent absence worth a red light.
 */
final readonly class DocumentCheck implements HealthCheckInterface
{
    public function __construct(
        private LegalDocuments $legal,
        private MarkdownFile $markdown,
        #[Autowire('%app.mail_signature%')] private string $signature,
    ) {
    }

    public function name(): string
    {
        return 'documents';
    }

    public function label(): string
    {
        return 'health.check.documents';
    }

    public function run(): HealthResult
    {
        $configured = [];
        $missing = [];

        foreach (LegalPage::cases() as $page) {
            if (!$this->legal->isLocal($page)) {
                continue;
            }

            $configured[] = $page->variable();

            if (null === $this->legal->file($page)) {
                $missing[] = $page->variable();
            }
        }

        if ('' !== $this->signature) {
            $configured[] = 'MAIL_SIGNATURE';

            if (null === $this->markdown->resolve($this->signature, 'MAIL_SIGNATURE')) {
                $missing[] = 'MAIL_SIGNATURE';
            }
        }

        if ([] !== $missing) {
            return HealthResult::failed('health.detail.documents_missing', ['%variables%' => implode(', ', $missing)]);
        }

        if ([] === $configured) {
            return HealthResult::skipped('health.detail.documents_none');
        }

        return HealthResult::ok();
    }
}
