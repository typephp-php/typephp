<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types\Imported;

/**
 * @phpstan-type TraitShape array{x: int, y: int}
 */
trait TraitWithAlias
{
    /** @var TraitShape */
    public array $coordinates = ['x' => 0, 'y' => 0];
}