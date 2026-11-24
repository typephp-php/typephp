<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Priority3;

/**
 * @phpstan-type NumberPredicate callable(positive-int): bool
 * @phpstan-type StringSequence iterable<int, string>
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
     * @param ?NumberPredicate $predicate
     */
    public function filterOptionalNumber(mixed $predicate, int $val): bool
    {
        if ($predicate === null) {
            return true;
        }

        return $predicate($val);
    }

    /**
     * @param StringSequence $items
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

    /**
     * @param StringSequence $items
     */
    public function countStrings(mixed $items): int
    {
        $count = 0;
        foreach ($items as $_) {
            $count++;
        }

        return $count;
    }
}
