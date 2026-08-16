<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\ByRef;

interface ByRefServiceInterface
{
    /**
     * @param non-empty-string &$status
     */
    public function updateStatus(string &$status): void;

    /**
     * @param positive-int &$code
     */
    public function incrementCode(int &$code): void;
}
