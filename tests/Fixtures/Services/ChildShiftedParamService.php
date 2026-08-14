<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

class ChildShiftedParamService extends BaseShiftedParentService
{
    /**
     * Child constructor inserts $prefix at Index 0, shifting $helper to Index 1,
     * and renames $definitions -> $definitionMap at Index 2!
     *
     * @param array<string, string> $definitionMap
     * @param array<string, string> $repositoryMap
     */
    public function __construct(
        string $prefix,
        HelperService $helper,
        array $definitionMap,
        array $repositoryMap
    ) {
        parent::__construct($helper, $definitionMap, $repositoryMap);
    }
}