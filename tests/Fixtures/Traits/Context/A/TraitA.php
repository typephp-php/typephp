<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Traits\Context\A;

use TypePHP\Tests\Fixtures\Enums\Suit as ConflictingAlias;

/**
 * @method ConflictingAlias getMagicSuit()
 */
trait TraitA
{
    /**
     * @var ConflictingAlias
     */
    public mixed $traitAProp;

    /**
     * @var ConflictingAlias
     */
    public static mixed $traitAStaticProp;

    /**
     * @param InterfaceA $obj
     */
    public function processA(mixed $obj): string
    {
        return $obj::class;
    }
}
