<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\InheritanceAndAttributes;

enum TestOptionEnum
{
    case OPT_1;
    case OPT_2;
}

class OptionFixture
{
    public function __construct(
        public string|int $key,
        public mixed $value
    ) {
    }
}

/**
 * Exact replica of Tempest OptionCollection:
 *
 * - Property $options has `/** @var array<OptionFixture> * /`
 * - Constructor parameter $options is UN-PROMOTED and takes raw iterable inputs (strings, enums, etc.)
 * - Internal code transforms raw values into OptionFixture objects
 */
class OptionCollectionFixture
{
    /**
     * @var array<OptionFixture>
     */
    private array $options;

    public function __construct(iterable $options)
    {
        $this->options = [];
        foreach ($options as $key => $value) {
            $this->options[] = new OptionFixture($key, $value);
        }
    }

    /**
     * @return array<OptionFixture>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}

describe('Non-Promoted Constructor Parameters (Tempest OptionCollection Reproduction)', function () {
    test('does not copy internal property @var type onto un-promoted constructor parameter with string array', function () {
        $collection = new OptionCollectionFixture(['foo', 'bar', 'baz']);

        expect($collection->getOptions())->toHaveCount(3)
            ->and($collection->getOptions()[0])->toBeInstanceOf(OptionFixture::class)
            ->and($collection->getOptions()[0]->value)->toBe('foo')
        ;
    });

    test('does not copy internal property @var type onto un-promoted constructor parameter with enum cases', function () {
        $collection = new OptionCollectionFixture(TestOptionEnum::cases());

        expect($collection->getOptions())->toHaveCount(2)
            ->and($collection->getOptions()[0])->toBeInstanceOf(OptionFixture::class)
            ->and($collection->getOptions()[0]->value)->toBe(TestOptionEnum::OPT_1)
        ;
    });
});
