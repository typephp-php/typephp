<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Collections\ShopwareCollection;
use TypePHP\Tests\Fixtures\Collections\ShopwareEntityCollection;
use TypePHP\Tests\Fixtures\Collections\ShopwareEntitySearchResult;
use TypePHP\Tests\Fixtures\Collections\SpecificDogSearchResult;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * @template TEntityCollection of ShopwareCollection
 */
class ClassTemplateInlineVarRepository
{
    /**
     * Case 1: Exact Shopware Line 129 Pattern Named @var $result on a return expression constructing SearchResult
     */
    public function searchConstructsResult(): object
    {
        $result = new ShopwareEntityCollection();

        /** @var TEntityCollection $result */
        return new ShopwareEntitySearchResult($result);
    }

    /**
     * Case 2: Named @var $result where the return expression IS $result
     */
    public function searchDirectReturn(): object
    {
        /** @var TEntityCollection $result */
        $result = new ShopwareEntityCollection();

        return $result;
    }

    /**
     * Case 3: Unnamed @var on return statement
     */
    public function searchUnnamedReturn(): object
    {
        /** @var TEntityCollection */
        return new ShopwareEntityCollection();
    }

    /**
     * Case 4: Template used inside Generic Container on inline variable
     */
    public function searchWithGenericContainer(): object
    {
        $collection = new ShopwareEntityCollection();

        /** @var ShopwareEntitySearchResult<TEntityCollection> $searchResult */
        $searchResult = new ShopwareEntitySearchResult($collection);

        return $searchResult;
    }

    /**
     * Case 5: Method-level generic template in inline variable
     *
     * @template TItem of object
     *
     * @param TItem $item
     */
    public function processItem(object $item): object
    {
        /** @var TItem $localItem */
        $localItem = $item;

        return $localItem;
    }

    /**
     * Case 6: Assigning an invalid object violating the template upper bound
     */
    public function assignInvalidObject(): void
    {
        /** @var TEntityCollection $bad */
        $bad = new Car(); // Car is not a ShopwareCollection!
    }
}

/**
 * Subclass binding TEntityCollection to SpecificDogSearchResult
 *
 * @extends ClassTemplateInlineVarRepository<SpecificDogSearchResult>
 */
class ConcreteDogRepository extends ClassTemplateInlineVarRepository
{
    public function searchSpecificDog(): object
    {
        /** @var TEntityCollection $dogResult */
        $dogResult = new SpecificDogSearchResult();

        return $dogResult;
    }

    public function searchWrongCollection(): object
    {
        /** @var TEntityCollection $wrongResult */
        $wrongResult = new ShopwareEntityCollection();

        return $wrongResult;
    }
}

describe('Class-Level & Method-Level Template Resolution in Inline @var Annotations', function () {
    test('resolves named @var on return expression constructing new object (Exact Shopware Line 129)', function () {
        $repo = new ClassTemplateInlineVarRepository();
        expect($repo->searchConstructsResult())->toBeInstanceOf(ShopwareEntitySearchResult::class);
    });

    test('resolves named @var where return expression is the variable', function () {
        $repo = new ClassTemplateInlineVarRepository();
        expect($repo->searchDirectReturn())->toBeInstanceOf(ShopwareEntityCollection::class);
    });

    test('resolves unnamed @var directly on return statement', function () {
        $repo = new ClassTemplateInlineVarRepository();
        expect($repo->searchUnnamedReturn())->toBeInstanceOf(ShopwareEntityCollection::class);
    });

    test('resolves template used inside generic container on inline @var', function () {
        $repo = new ClassTemplateInlineVarRepository();
        expect($repo->searchWithGenericContainer())->toBeInstanceOf(ShopwareEntitySearchResult::class);
    });

    test('resolves method-level template in inline @var variable', function () {
        $repo = new ClassTemplateInlineVarRepository();
        $dog = new Dog();

        expect($repo->processItem($dog))->toBe($dog);
    });

    test('throws TypeError when inline variable violates template upper bound', function () {
        $repo = new ClassTemplateInlineVarRepository();

        expect(fn () => $repo->assignInvalidObject())
            ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Collections\ShopwareCollection')
        ;
    });

    test('resolves pre-bound template in subclass on inline @var variable', function () {
        $repo = new ConcreteDogRepository();

        expect($repo->searchSpecificDog())->toBeInstanceOf(SpecificDogSearchResult::class);

        expect(fn () => $repo->searchWrongCollection())
            ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Collections\SpecificDogSearchResult')
        ;
    });

    test('respects inline_vars configuration toggles when disabled', function () {
        try {
            $repo = new ClassTemplateInlineVarRepository();

            expect(fn () => $repo->assignInvalidObject())
                ->toThrow(TypeError::class)
            ;

            Config::set([
                'inline_vars' => [
                    'objects' => false,
                    'generics' => false,
                ],
            ]);

            $repo->assignInvalidObject();
            expect(true)->toBeTrue();
        } finally {
            Config::reset();
        }
    });
});
