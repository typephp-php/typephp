<?php

declare(strict_types=1);

class FixtureFluentBuilder
{
    public function where(string $key, mixed $value): self
    {
        return $this;
    }
}

/**
 * Fixture reproducing Tempest\Support\tap()
 *
 * @template TValue
 *
 * @param TValue $value
 * @param callable(TValue): void $callback
 *
 * @return TValue
 */
function testTapHelper(mixed $value, callable $callback): mixed
{
    $callback($value);

    return $value;
}

describe('Higher-Order Discarded Return Callbacks (callable(): void in tap/each)', function () {
    test('allows short arrow functions returning fluent objects inside tap() with callable(): void docblock', function () {
        $builder = new FixtureFluentBuilder();

        $result = testTapHelper($builder, fn ($b) => $b->where('name', 'Frieren'));

        expect($result)->toBe($builder);
    });
});
