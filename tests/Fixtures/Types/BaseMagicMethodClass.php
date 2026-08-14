<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

/**
 * Parent class declaring dynamic @method
 *
 * @method positive-int parentMethod(positive-int $id)
 * @method positive-int calculateScore(positive-int $baseScore, non-empty-string $category)
 */
abstract class BaseMagicMethodClass
{
}
