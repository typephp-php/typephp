<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\TypePHP;

abstract class BaseTagFixture
{
}

class TextTagFixture extends BaseTagFixture
{
}

class ShippingMethodTagFixture extends BaseTagFixture
{
}

class UnrelatedTagFixture extends BaseTagFixture
{
}

/**
 * @template TTag of BaseTagFixture
 */
trait HasManyTagsFixture
{
    /**
     * @var array<int, TTag>
     */
    public array $tags = [];

    /**
     * @param TTag $record
     */
    public function addTag(BaseTagFixture $record): static
    {
        $this->tags[] = $record;

        return $this;
    }
}

class EnclosingNamedClassFixture
{
    public function createAnonymous(): object
    {
        return new class () {
            /**
             * @use HasManyTagsFixture<TextTagFixture|ShippingMethodTagFixture>
             */
            use HasManyTagsFixture;
        };
    }
}

describe('Anonymous Class with Generic Trait @use Annotation', function () {
    test('binds union template to anonymous class from @use docblock and accepts multiple union members', function () {
        $subject = new class () {
            /**
             * @use HasManyTagsFixture<TextTagFixture|ShippingMethodTagFixture>
             */
            use HasManyTagsFixture;
        };
        $boundType = TypePHP::getGenericType($subject);
        expect($boundType)->not()->toBeNull()
            ->and($boundType)->toContain(TextTagFixture::class)
            ->and($boundType)->toContain(ShippingMethodTagFixture::class)
        ;

        $subject->addTag(new TextTagFixture());
        expect($subject->tags)->toHaveCount(1);

        $subject->addTag(new ShippingMethodTagFixture());
        expect($subject->tags)->toHaveCount(2);
    });

    test('anonymous class created inside a named class method resolves @use and accepts union members', function () {
        $factory = new EnclosingNamedClassFixture();
        $subject = $factory->createAnonymous();

        $subject->addTag(new TextTagFixture());
        $subject->addTag(new ShippingMethodTagFixture());

        expect($subject->tags)->toHaveCount(2);
    });

    test('still rejects tag types outside the declared union on the anonymous class', function () {
        $subject = new class () {
            /**
             * @use HasManyTagsFixture<TextTagFixture|ShippingMethodTagFixture>
             */
            use HasManyTagsFixture;
        };

        expect(fn () => $subject->addTag(new UnrelatedTagFixture()))
            ->toThrow(TypeError::class)
        ;
    });

    test('does not leak anonymous class trait-use docblock into enclosing named class', function () {
        $factory = new EnclosingNamedClassFixture();
        $anon = $factory->createAnonymous();
        expect($anon)->toBeObject();

        $enclosingDocs = SpecialTypeResolver::getClassTraitUseDocs(EnclosingNamedClassFixture::class);
        expect($enclosingDocs)->toBeEmpty();
    });
});
