<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Priority3;

/**
 * @phpstan-type NumberPredicate callable(positive-int): bool
 * @phpstan-type StringIterable iterable<int, string>
 */
class Priority3CallableAliasHolder
{
    /**
     * @param NumberPredicate $predicate
     */
    public function filterNumber(mixed $predicate, int $val): bool
    {
        return $predicate($val);
    }

    /**
     * @param StringIterable $items
     *
     * @return list<string>
     */
    public function consumeStrings(mixed $items): array
    {
        $out = [];
        foreach ($items as $v) {
            $out[] = $v;
        }

        return $out;
    }
}
