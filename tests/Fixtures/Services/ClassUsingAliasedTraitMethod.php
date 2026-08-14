<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

class ClassUsingAliasedTraitMethod
{
    use ShiftedLoggerTrait {
        logEvent as recordAuditLog; // Aliases logEvent -> recordAuditLog
    }
}
