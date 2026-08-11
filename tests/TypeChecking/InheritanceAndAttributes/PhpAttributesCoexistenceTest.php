<?php

declare(strict_types=1);

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class SampleAttribute
{
    public function __construct(public string $name = '')
    {
    }
}

class AttributedClassFixture
{
    // DocBlock ABOVE Attribute
    /**
     * @var positive-int
     */
    #[SampleAttribute('title_property')]
    public int $id = 10;

    // DocBlock BELOW Attribute on Method
    #[SampleAttribute('route_method')]
    /**
     * @param positive-int $id
     *
     * @return non-empty-string
     */
    public function processUser(
        #[SampleAttribute('param_attr')]
        int $id
    ): string {
        return "user_{$id}";
    }

    // DocBlock ABOVE Attribute on Method
    /**
     * @param positive-int $code
     */
    #[SampleAttribute('doc_above_attr')]
    public function executeWithDocAbove(int $code): bool
    {
        return true;
    }
}

describe('PHP 8.0+ Attributes Coexistence', function () {
    test('executes type checking seamlessly regardless of whether docblock is placed above or below attributes', function () {
        $fixture = new AttributedClassFixture();

        expect($fixture->processUser(42))->toBe('user_42');

        expect(fn () => $fixture->processUser(-50))
            ->toThrow(TypeError::class, 'positive-int')
        ;

        expect($fixture->executeWithDocAbove(100))->toBeTrue();

        expect(fn () => $fixture->executeWithDocAbove(-5))
            ->toThrow(TypeError::class, 'positive-int')
        ;

        expect(fn () => $fixture->id = -10)
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });
});
