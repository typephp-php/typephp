<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\ArraysAndShapes;

use TypePHP\Exception\TypeError;

/**
 * @phpstan-type Cell array{state: self::STATE_*, value: float}
 * @phpstan-type SpecificCell array{state: self::STATE_FOUND, value: float}
 * @phpstan-type ConfigMap key-of<self::MAP>
 */
final class RankingFixture
{
    public const STATE_FOUND = 'found';
    public const STATE_UNKNOWN = 'unknown';

    public const MAP = [
        'rank_a' => 1,
        'rank_b' => 2,
    ];
}

/**
 * Intermediary class for testing chained imports:
 * RankingFixture -> ChainedImportMidFixture -> ChainedImportConsumerFixture
 *
 * @phpstan-import-type Cell from RankingFixture as MidCell
 *
 * @phpstan-type ExportedChainedCell MidCell
 */
final class ChainedImportMidFixture
{
}

/**
 * @phpstan-import-type Cell from RankingFixture
 * @phpstan-import-type SpecificCell from RankingFixture
 * @phpstan-import-type ConfigMap from RankingFixture
 */
final class SearchFixture
{
    /**
     * Exact scenario from the bug report: @return Cell with self::STATE_*
     *
     * @return Cell
     */
    public function cell(string $state = RankingFixture::STATE_FOUND, float $value = 1.0): array
    {
        return ['state' => $state, 'value' => $value];
    }

    /**
     * Parameter contract using the imported alias
     *
     * @param Cell $cell
     */
    public function acceptCell(array $cell): bool
    {
        return true;
    }

    /**
     * Specific constant alias: self::STATE_FOUND
     *
     * @return SpecificCell
     */
    public function specificCell(): array
    {
        return ['state' => RankingFixture::STATE_FOUND, 'value' => 2.5];
    }

    /**
     * key-of with self::
     *
     * @param ConfigMap $key
     */
    public function acceptConfigKey(string $key): bool
    {
        return true;
    }
}

/**
 * Consumer testing chained alias imports
 *
 * @phpstan-import-type ExportedChainedCell from ChainedImportMidFixture
 */
final class ChainedImportConsumerFixture
{
    /**
     * @return ExportedChainedCell
     */
    public function getCell(): array
    {
        return ['state' => RankingFixture::STATE_UNKNOWN, 'value' => 0.5];
    }
}

describe('Imported Type Alias Self Resolution (@phpstan-import-type with self::)', function () {
    test('resolves self::PREFIX_* in imported alias to the declaring class for return types (Bug Report Reproduction)', function () {
        $search = new SearchFixture();

        $result = $search->cell();

        expect($result)->toBe([
            'state' => 'found',
            'value' => 1.0,
        ]);
    });

    test('resolves self::PREFIX_* in imported alias to the declaring class for parameter types', function () {
        $search = new SearchFixture();

        expect($search->acceptCell(['state' => RankingFixture::STATE_UNKNOWN, 'value' => 3.14]))->toBeTrue();

        expect(fn () => $search->acceptCell(['state' => 'invalid_state', 'value' => 1.0]))
            ->toThrow(TypeError::class, 'must be a valid constant matching')
        ;
    });

    test('resolves specific self::CONSTANT in imported alias to the declaring class', function () {
        $search = new SearchFixture();

        $result = $search->specificCell();

        expect($result)->toBe([
            'state' => 'found',
            'value' => 2.5,
        ]);
    });

    test('resolves key-of<self::MAP> in imported alias to the declaring class', function () {
        $search = new SearchFixture();

        expect($search->acceptConfigKey('rank_a'))->toBeTrue();

        expect(fn () => $search->acceptConfigKey('invalid_rank'))
            ->toThrow(TypeError::class, 'must be a key of')
        ;
    });

    test('resolves self:: in multi-tier chained imported aliases (A -> B -> C)', function () {
        $consumer = new ChainedImportConsumerFixture();

        $result = $consumer->getCell();

        expect($result)->toBe([
            'state' => 'unknown',
            'value' => 0.5,
        ]);
    });
});
