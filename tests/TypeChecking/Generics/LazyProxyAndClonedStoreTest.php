<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80400) {
    return;
}

require_once __DIR__ . '/../../Fixtures/Generics/NoctudReproFixture.php';

describe('Map $entries and $keys Views (Noctud MapLogic)', function () {
    test('accessing $entries view on eager ImmutableMap matches Set<MapEntry<string, int>>', function () {
        $map = reproMakeEagerImmutableMap(['a' => 1, 'b' => 2]);

        $entries = $map->entries;
        $items = iterator_to_array($entries);

        expect(\count($items))->toBe(2);
        expect($items[0]->key)->toBe('a');
        expect($items[0]->value)->toBe(1);
    });

    test('accessing $entries view on eager MutableMap matches Set<MapEntry<string, int>>', function () {
        $map = reproMakeEagerMutableMap(['a' => 1, 'b' => 2]);

        $entries = $map->entries;
        $items = iterator_to_array($entries);

        expect(\count($items))->toBe(2);
        expect($items[0]->key)->toBe('a');
        expect($items[0]->value)->toBe(1);
    });

    test('accessing $entries view on lazy ImmutableMap preserves K = string and V = int without upper-bound fallback', function () {
        $map = reproMakeLazyImmutableMap(['a' => 1, 'b' => 2]);

        $entries = $map->entries;
        $items = iterator_to_array($entries);

        expect(\count($items))->toBe(2);
        expect($items[0]->key)->toBe('a');
        expect($items[0]->value)->toBe(1);
    });

    test('accessing $entries view on lazy MutableMap preserves K = string and V = int without upper-bound fallback', function () {
        $map = reproMakeLazyMutableMap(['a' => 1, 'b' => 2]);

        $entries = $map->entries;
        $items = iterator_to_array($entries);

        expect(\count($items))->toBe(2);
        expect($items[0]->key)->toBe('a');
        expect($items[0]->value)->toBe(1);
    });

    test('accessing $entries view after materializing lazy map preserves K = string and V = int', function () {
        $map = reproMakeLazyImmutableMap(['a' => 1, 'b' => 2]);

        expect($map->count())->toBe(2);

        $entries = $map->entries;
        $items = iterator_to_array($entries);

        expect(\count($items))->toBe(2);
        expect($items[0]->key)->toBe('a');
        expect($items[0]->value)->toBe(1);
    });

    test('accessing $keys view on eager map returns correct keys', function () {
        $map = reproMakeEagerImmutableMap(['a' => 1, 'b' => 2]);

        expect(iterator_to_array($map->keys))->toBe(['a', 'b']);
    });

    test('accessing $keys view on lazy map preserves K = string', function () {
        $map = reproMakeLazyImmutableMap(['a' => 1, 'b' => 2]);

        expect(iterator_to_array($map->keys))->toBe(['a', 'b']);
    });

    test('accessing $keys view after materializing lazy map preserves K = string', function () {
        $map = reproMakeLazyImmutableMap(['a' => 1, 'b' => 2]);

        expect($map->count())->toBe(2);
        expect(iterator_to_array($map->keys))->toBe(['a', 'b']);
    });
});

describe('Cloned Internal Store in Widening Methods (Noctud ImmutableListLogic)', function () {
    test('add() on lazily initialized immutable list does not leak unsubstituted template E', function () {
        $list = reproMakeLazyList([1, 2]);
        $result = $list->add(3);

        expect($result->toArray())->toBe([1, 2, 3]);
    });

    test('addAll() on lazily initialized immutable list does not leak unsubstituted template E', function () {
        $list = reproMakeLazyList([1]);
        $result = $list->addAll([2, 3]);

        expect($result->toArray())->toBe([1, 2, 3]);
    });

    test('add() with type widening on eager immutable list succeeds without mutating source bindings', function () {
        $list = reproListOf([1, 2]);
        $widened = $list->add('x');

        expect($widened->toArray())->toBe([1, 2, 'x']);
        expect($list->toArray())->toBe([1, 2]);
    });

    test('cloning an unrelated generic object inside a widening method does not corrupt its template bindings', function () {
        $wrapper = reproMakeWrapper(10);
        $widened = $wrapper->widenWithClonedHelper(20);

        expect($widened->getHelper()->value)->toBe('ok');
    });
});
