<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

interface MethodTemplateBase
{
}

final class MethodTemplateA implements MethodTemplateBase
{
    public function __construct(public string $name = 'A')
    {
    }
}

final class MethodTemplateB implements MethodTemplateBase
{
    public function __construct(public string $name = 'B')
    {
    }
}

final class MethodTemplateUnrelated
{
}

/**
 * @template T of MethodTemplateBase
 */
final class MethodTemplateBox
{
    /**
     * @param T $value
     */
    public function __construct(public MethodTemplateBase $value)
    {
    }
}

final class MethodTemplateConsumer
{
    /**
     * @template T of MethodTemplateBase
     *
     * @param MethodTemplateBox<T> $box
     *
     * @return T
     */
    public function unwrap(MethodTemplateBox $box): MethodTemplateBase
    {
        return $box->value;
    }

    /**
     * @template T of MethodTemplateBase
     *
     * @param array<array-key, MethodTemplateBox<T>> $items
     *
     * @return list<T>
     */
    public function unwrapAll(array $items): array
    {
        $result = [];
        foreach ($items as $box) {
            $result[] = $box->value;
        }

        return $result;
    }
}

describe('Method-Level Template Inference from Generic Parameter Objects', function () {
    test('infers method template T from generic argument PBox<T> instead of falling back to upper bound PBase', function () {
        $consumer = new MethodTemplateConsumer();
        $boxA = new MethodTemplateBox(new MethodTemplateA('Alpha'));
        
        $result = $consumer->unwrap($boxA);

        expect($result)->toBeInstanceOf(MethodTemplateA::class)
            ->and($result->name)->toBe('Alpha')
        ;
    });

    test('allows multiple calls to unwrap() with different generic arguments without cross-call template locking', function () {
        $consumer = new MethodTemplateConsumer();

        $boxA = new MethodTemplateBox(new MethodTemplateA('Alpha'));
        $resultA = $consumer->unwrap($boxA);
        expect($resultA)->toBeInstanceOf(MethodTemplateA::class);

        $boxB = new MethodTemplateBox(new MethodTemplateB('Beta'));
        $resultB = $consumer->unwrap($boxB);
        expect($resultB)->toBeInstanceOf(MethodTemplateB::class);
    });

    test('infers template T from array of generic objects array<array-key, PBox<T>>', function () {
        $consumer = new MethodTemplateConsumer();

        $items = [
            'first' => new MethodTemplateBox(new MethodTemplateA('A1')),
            'second' => new MethodTemplateBox(new MethodTemplateA('A2')),
        ];

        $result = $consumer->unwrapAll($items);

        expect($result)->toHaveCount(2)
            ->and($result[0])->toBeInstanceOf(MethodTemplateA::class)
            ->and($result[1])->toBeInstanceOf(MethodTemplateA::class)
        ;
    });
});