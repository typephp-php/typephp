<?php

declare(strict_types=1);

namespace TypePHP\Internal\Diagnostic;

use TypePHP\Internal\Util\Config;

/**
 * Opt-in visibility for the exceptions TypePHP swallows in order to stay fail-open.
 *
 * @internal
 */
final class DebugLog
{
    /**
     * Writes a swallowed exception to the PHP error log when TYPEPHP_DEBUG is set; a no-op otherwise.
     */
    public static function swallowed(\Throwable $e): void
    {
        if (! Config::isDebugEnabled()) {
            return;
        }

        $catchSite = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];

        error_log(\sprintf(
            '[TypePHP Debug] Swallowed %s: %s (thrown at %s:%d, swallowed at %s:%d)',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $catchSite['file'] ?? 'unknown',
            $catchSite['line'] ?? 0
        ));
    }
}
