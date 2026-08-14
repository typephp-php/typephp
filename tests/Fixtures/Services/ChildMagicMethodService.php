<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

use TypePHP\Tests\Fixtures\Types\BaseMagicMethodClass;
use TypePHP\Tests\Fixtures\Types\MagicMethodInterface;
use TypePHP\Tests\Fixtures\Types\MagicMethodTrait;

class ChildMagicMethodService extends BaseMagicMethodClass implements MagicMethodInterface
{
    use MagicMethodTrait;

    public function __call(string $name, array $arguments): mixed
    {
        if ($name === 'parentMethod' || $name === 'interfaceMethod') {
            return $arguments[0] ?? $arguments['id'] ?? $arguments['title'] ?? null;
        }

        if ($name === 'calculateScore') {
            return $arguments[0] ?? $arguments['baseScore'] ?? null;
        }

        if ($name === 'traitMethod') {
            return true;
        }

        return null;
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        if ($name === 'fetchList') {
            return array_values($arguments);
        }

        return null;
    }
}
