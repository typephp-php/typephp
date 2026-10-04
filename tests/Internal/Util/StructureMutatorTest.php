<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Util;

use stdClass;
use TypePHP\Internal\Util\StructureMutator;

describe('StructureMutator Unit Tests', function () {
    test('mutates 1D array on copy without altering original', function () {
        $original = ['count' => 5];
        $mutated = StructureMutator::mutateCopy($original, [['dim', 'count']], 10);

        expect($mutated)->toBe(['count' => 10])
            ->and($original)->toBe(['count' => 5])
        ;
    });

    test('appends to list on copy without altering original', function () {
        $original = [1, 2];
        $mutated = StructureMutator::mutateCopy($original, [['dim', null]], 3);

        expect($mutated)->toBe([1, 2, 3])
            ->and($original)->toBe([1, 2])
        ;
    });

    test('mutates multi-dimensional array path on copy', function () {
        $original = ['user' => ['id' => 1]];
        $mutated = StructureMutator::mutateCopy($original, [['dim', 'user'], ['dim', 'id']], 42);

        expect($mutated)->toBe(['user' => ['id' => 42]])
            ->and($original['user']['id'])->toBe(1)
        ;
    });

    test('auto-vivifies missing intermediate array dimensions on copy', function () {
        $original = [];
        $mutated = StructureMutator::mutateCopy($original, [['dim', 'level1'], ['dim', 'level2']], 'created');

        expect($mutated)->toBe(['level1' => ['level2' => 'created']])
            ->and($original)->toBe([])
        ;
    });

    test('mutates stdClass property on copy without altering original', function () {
        $original = (object) ['name' => 'Alice'];
        $mutated = StructureMutator::mutateCopy($original, [['prop', 'name']], 'Bob');

        expect($mutated->name)->toBe('Bob')
            ->and($original->name)->toBe('Alice')
        ;
    });

    test('mutates nested object property path on copy and isolates intermediate objects', function () {
        $geo = (object) ['city' => 'Tokyo'];
        $original = (object) ['geo' => $geo];

        $mutated = StructureMutator::mutateCopy($original, [['prop', 'geo'], ['prop', 'city']], 'Osaka');

        expect($mutated->geo->city)->toBe('Osaka')
            ->and($original->geo->city)->toBe('Tokyo')
            ->and($mutated->geo)->not()->toBe($geo)
        ;
    });

    test('auto-vivifies missing intermediate object properties on copy', function () {
        $original = new stdClass();
        $mutated = StructureMutator::mutateCopy($original, [['prop', 'nested'], ['prop', 'title']], 'Generated');

        expect($mutated->nested->title)->toBe('Generated')
            ->and(property_exists($original, 'nested'))->toBeFalse()
        ;
    });

    test('mutates hybrid array-of-objects structure on copy', function () {
        $user = (object) ['id' => 1];
        $original = [
            'users' => [$user],
        ];

        $mutated = StructureMutator::mutateCopy($original, [['dim', 'users'], ['dim', 0], ['prop', 'id']], 999);

        expect($mutated['users'][0]->id)->toBe(999)
            ->and($original['users'][0]->id)->toBe(1)
            ->and($mutated['users'][0])->not()->toBe($user)
        ;
    });

    test('mutates hybrid object-of-arrays structure on copy', function () {
        $original = (object) [
            'meta' => ['tag' => 'old'],
        ];

        $mutated = StructureMutator::mutateCopy($original, [['prop', 'meta'], ['dim', 'tag']], 'new');

        expect($mutated->meta['tag'])->toBe('new')
            ->and($original->meta['tag'])->toBe('old')
        ;
    });
});
