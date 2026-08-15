<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Shapes\UnsealedPayloadService;

/**
 * Standalone function with complex unsealed shape
 *
 * @param array{id: positive-int, ...<string, list<non-empty-string>>} $config
 */
function testComplexUnsealedFunction(array $config): bool
{
    return true;
}

describe('Complex Unsealed Array Shapes (...<string, ComplexType>)', function () {
    describe('Unsealed Shapes with Nested Lists (...<string, list<positive-int>>)', function () {
        test('accepts required keys and valid extra list<positive-int> items', function () {
            $service = new UnsealedPayloadService();
            $payload = [
                'id' => 10,
                'even_scores' => [2, 4, 6],
                'odd_scores' => [1, 3, 5],
            ];

            expect($service->processBatchOptions($payload))->toBe(3);
        });

        test('accepts required keys with empty extra keys', function () {
            $service = new UnsealedPayloadService();

            expect($service->processBatchOptions(['id' => 42]))->toBe(1);
        });

        test('throws TypeError when an extra key contains an invalid integer in the nested list', function () {
            $service = new UnsealedPayloadService();
            $badPayload = [
                'id' => 10,
                'even_scores' => [2, 4, 6],
                'odd_scores' => [1, -3, 5], // -3 violates positive-int inside the list!
            ];

            expect(fn () => $service->processBatchOptions($badPayload))
                ->toThrow(TypeError::class, "['odd_scores'][1] must be of type positive-int");
        });

        test('throws TypeError when an extra key is not a list', function () {
            $service = new UnsealedPayloadService();
            $badPayload = [
                'id' => 10,
                'extra_info' => 'not_a_list', // String instead of list<positive-int>
            ];

            expect(fn () => $service->processBatchOptions($badPayload))
                ->toThrow(TypeError::class, "['extra_info'] must be a list");
        });
    });

    describe('Unsealed Shapes with Nested Sub-Shapes (...<string, array{...}>)', function () {
        test('accepts required keys and valid extra nested sub-shapes', function () {
            $service = new UnsealedPayloadService();
            $data = [
                'version' => '2.1.0',
                'player_one' => ['score' => 1500, 'active' => true],
                'player_two' => ['score' => 2400, 'active' => false],
            ];

            expect($service->processPlayerStats($data))->toBe(3);
        });

        test('throws TypeError when an extra sub-shape item violates inner scalar constraint', function () {
            $service = new UnsealedPayloadService();
            $badData = [
                'version' => '2.1.0',
                'player_one' => ['score' => 1500, 'active' => true],
                'player_two' => ['score' => -50, 'active' => false], // -50 violates positive-int in sub-shape!
            ];

            expect(fn () => $service->processPlayerStats($badData))
                ->toThrow(TypeError::class, "['player_two']['score'] must be of type positive-int");
        });

        test('throws TypeError when an extra sub-shape is missing a required inner property', function () {
            $service = new UnsealedPayloadService();
            $badData = [
                'version' => '2.1.0',
                'player_one' => ['score' => 1500], // Missing required 'active' boolean!
            ];

            expect(fn () => $service->processPlayerStats($badData))
                ->toThrow(TypeError::class, "['player_one'] is missing required key 'active'");
        });
    });

    describe('Standalone Functions with Unsealed list<non-empty-string>', function () {
        test('accepts valid nested non-empty-string lists under arbitrary extra keys', function () {
            $config = [
                'id' => 100,
                'tags' => ['php', 'typephp'],
                'flags' => ['strict', 'runtime'],
            ];

            expect(testComplexUnsealedFunction($config))->toBeTrue();
        });

        test('throws TypeError when extra list contains empty string', function () {
            $config = [
                'id' => 100,
                'tags' => ['php', ''], // Empty string violates non-empty-string
            ];

            expect(fn () => testComplexUnsealedFunction($config))
                ->toThrow(TypeError::class, "['tags'][1] must be of type non-empty-string");
        });
    });
});