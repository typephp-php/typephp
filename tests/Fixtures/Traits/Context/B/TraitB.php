<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Traits\Context\B;

use TypePHP\Tests\Fixtures\Types\StatusEnum as ConflictingAlias;

trait TraitB
{
    /**
     * @var ConflictingAlias
     */
    public mixed $traitBProp;

    /**
     * @param InterfaceB $obj
     */
    public function processB(mixed $obj): string
    {
        return $obj::class;
    }
}
