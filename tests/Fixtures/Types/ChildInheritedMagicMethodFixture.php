<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

class ChildInheritedMagicMethodFixture extends BaseMagicMethodClass implements MagicMethodInterface
{
    use MagicMethodTrait;

    public function __call(string $name, array $arguments): mixed
    {
        if ($name === 'parentMethod') {
            return $arguments[0] ?? null;
        }

        if ($name === 'interfaceMethod') {
            return $arguments[0] ?? null;
        }

        if ($name === 'traitMethod') {
            return true;
        }

        return null;
    }
}
