<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Traits\Context;

use TypePHP\Tests\Fixtures\Enums\Suit;
use TypePHP\Tests\Fixtures\Traits\Context\A\TraitA;
use TypePHP\Tests\Fixtures\Traits\Context\B\TraitB;

class MultiConsumer
{
    use TraitA;
    use TraitB;

    public function __call(string $name, array $args): mixed
    {
        if ($name === 'getMagicSuit') {
            return Suit::Spades;
        }

        return null;
    }
}
