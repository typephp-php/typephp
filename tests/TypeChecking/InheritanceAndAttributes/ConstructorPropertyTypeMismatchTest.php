<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Doctrine\Column;

/**
 * @phpstan-type CustomUserId positive-int
 */
class ConstructorPromotionWithAlias
{
    /**
     * @var CustomUserId
     */
    public int $userId;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }
}

describe('Constructor Parameter vs Property Type Conflict (Doctrine DBAL AbstractNamedObject)', function () {
    test('does not override native scalar constructor parameter with conflicting object property @var docblock', function () {
        $column = new Column('"id"');

        expect($column)->toBeInstanceOf(Column::class);
    });

    test('resolves class-level @phpstan-type aliases for constructor property promotion fallback', function () {
        $instance = new ConstructorPromotionWithAlias(42);
        expect($instance->userId)->toBe(42);

        expect(fn () => new ConstructorPromotionWithAlias(-5))
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });

    test('ContractParser::parse resolves property aliases for constructor parameters', function () {
        $contract = TypePHP\Internal\Docblock\ContractParser::parse(ConstructorPromotionWithAlias::class . '::__construct');

        expect((string) $contract['types']['userId'])->toBe('positive-int');
    });
});
