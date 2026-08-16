<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\ByRef;

class ByRefService implements ByRefServiceInterface
{
    // Inherits @param non-empty-string &$status from interface
    public function updateStatus(string &$status): void
    {
        $status = strtoupper($status);
    }

    // Renames $code -> $statusCode and inherits @param positive-int &$code
    public function incrementCode(int &$statusCode): void
    {
        $statusCode += 100;
    }
}
