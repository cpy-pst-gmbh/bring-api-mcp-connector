<?php

declare(strict_types=1);

namespace App\Bring;

use RuntimeException;

/**
 * Bring! could not answer, so the credentials are neither confirmed nor denied.
 *
 * Callers must not treat this as a failed login: the user gets pointed at the
 * login link instead.
 */
final class BringUnreachableException extends RuntimeException
{
}
