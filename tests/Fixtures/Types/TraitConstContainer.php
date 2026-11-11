<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

class TraitConstContainer
{
    /**
     * @param array{TraitWithConstant::TRAIT_KEY: positive-int} $payload
     */
    public function process(array $payload): bool
    {
        return true;
    }
}
