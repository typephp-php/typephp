<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

class BaseShiftedParentService
{
    /**
     * Parent constructor has 3 params:
     * Index 0: $helper
     * Index 1: $definitions
     * Index 2: $repositoryMap
     *
     * @param array<string, string> $definitions
     * @param array<string, string> $repositoryMap
     */
    public function __construct(
        HelperService $helper,
        array $definitions,
        array $repositoryMap
    ) {
    }
}
