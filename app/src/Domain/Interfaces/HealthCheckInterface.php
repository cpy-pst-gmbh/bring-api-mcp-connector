<?php

declare(strict_types=1);

namespace App\Domain\Interfaces;

use App\Health\HealthResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One thing the app needs in order to work.
 *
 * Implementations are collected automatically; adding a check means adding a
 * class, nothing else. A check must never throw — an unreachable dependency is
 * a result, not an exception.
 */
#[AutoconfigureTag('app.health_check')]
interface HealthCheckInterface
{
    /**
     * Key this check appears under in the JSON response, snake_case.
     */
    public function name(): string;

    /**
     * How the check is named on the human-readable page.
     */
    public function label(): string;

    public function run(): HealthResult;
}
