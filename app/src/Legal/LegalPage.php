<?php

declare(strict_types=1);

namespace App\Legal;

/**
 * The two documents an operator may have to publish.
 *
 * Each one is configured with a single value that is either an external URL or
 * a path to a Markdown file, so the pair is described once here rather than
 * twice everywhere it is used.
 */
enum LegalPage: string
{
    case PrivacyPolicy = 'privacy_policy';
    case Imprint = 'imprint';

    public function route(): string
    {
        return match ($this) {
            self::PrivacyPolicy => 'app_privacy_policy',
            self::Imprint => 'app_imprint',
        };
    }

    /**
     * Translation key for the link and the browser title.
     */
    public function titleKey(): string
    {
        return match ($this) {
            self::PrivacyPolicy => 'app.privacy_policy',
            self::Imprint => 'app.imprint',
        };
    }

    /**
     * Environment variable behind it, for messages that have to name it.
     */
    public function variable(): string
    {
        return match ($this) {
            self::PrivacyPolicy => 'PRIVACY_POLICY_URL',
            self::Imprint => 'IMPRINT_URL',
        };
    }
}
