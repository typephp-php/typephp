<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use Generator;
use stdClass;

class GenericStreamPipeline
{
    /**
     * @template TIn
     * @template TOut
     *
     * @param iterable<TIn> $data
     * @param callable(TIn): TOut $transform
     *
     * @return Generator<TOut>
     */
    public function transform(iterable $data, callable $transform): Generator
    {
        foreach ($data as $item) {
            yield $transform($item);
        }
    }
}

describe('Generic Generator Pipeline Interleaving and Template Isolation', function () {
    test('does not leak method templates across interleaved pipeline generators of the same method', function () {
        $p1 = new GenericStreamPipeline();
        $p2 = new GenericStreamPipeline();

        $gen1 = $p1->transform([10, 20, 30], fn (int $i): stdClass => (object) ['val' => $i]);

        $gen2 = $p2->transform($gen1, fn (stdClass $obj): int => $obj->val);

        $results = [];
        foreach ($gen2 as $item) {
            $results[] = $item;
        }

        expect($results)->toBe([10, 20, 30]);
    });
});
