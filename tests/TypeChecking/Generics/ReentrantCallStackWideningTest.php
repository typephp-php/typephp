<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use ReflectionClass;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Util\Config;
use TypePHP\TypePHP;

/**
 * An invariant element store whose add() method strictly enforces E.
 *
 * @template E
 */
class ReentrantStore
{
    /**
     * @var list<E>
     */
    public array $elements = [];

    /**
     * @param iterable<E> $items
     */
    public function __construct(iterable $items = [])
    {
        foreach ($items as $item) {
            $this->elements[] = $item;
        }
    }

    /**
     * @param E $element
     */
    public function add(mixed $element): void
    {
        $this->elements[] = $element;
    }
}

/**
 * An immutable collection with a type-widening method: addWidened().
 *
 * @template E
 */
class ReentrantCollection
{
    /**
     * @param ReentrantStore<E> $store
     */
    public function __construct(public ReentrantStore $store)
    {
    }

    /**
     * Widens E to E|NE on the cloned internal store.
     *
     * @template NE
     *
     * @param NE $element
     * @param (callable(): void)|null $interveningAction
     *
     * @return self<E|NE>
     */
    public function addWidened(mixed $element, ?callable $interveningAction = null): self
    {
        if ($interveningAction !== null) {
            $interveningAction();
        }

        $clonedStore = clone $this->store;
        $clonedStore->add($element);

        return new self($clonedStore);
    }
}

class InterveningService
{
    /**
     * An intermediate call that pushes its own frame onto the call stack.
     *
     * @template TUnrelated of string
     *
     * @param TUnrelated $tag
     * @param callable(): void $callback
     */
    public static function intermediateDispatch(string $tag, callable $callback): void
    {
        $callback();
    }
}

describe('Re-entrant Call Stack Order in TemplateManager (Issue 4 Red Phase)', function () {
    beforeEach(function () {
        Config::reset();
        TemplateManager::reset();
    });

    afterEach(function () {
        Config::reset();
        TemplateManager::reset();
    });

    test('reproduces array_key_last key ordering bug when a widening method is invoked re-entrantly (A -> B -> A)', function () {
        $outerCol = new ReentrantCollection(new ReentrantStore([1, 2]));
        $innerCol = new ReentrantCollection(new ReentrantStore([10, 20]));

        $innerResult = null;

        $outerResult = $outerCol->addWidened('outer_string', function () use ($innerCol, &$innerResult) {
            InterveningService::intermediateDispatch('test_tag', function () use ($innerCol, &$innerResult) {
                $innerResult = $innerCol->addWidened('inner_string');
            });
        });

        expect($innerResult)->toBeInstanceOf(ReentrantCollection::class)
            ->and($innerResult->store->elements)->toBe([10, 20, 'inner_string'])
            ->and(TypePHP::getGenericType($innerResult->store))->toBe('(int | string)')
        ;

        expect($outerResult)->toBeInstanceOf(ReentrantCollection::class)
            ->and($outerResult->store->elements)->toBe([1, 2, 'outer_string'])
            ->and(TypePHP::getGenericType($outerResult->store))->toBe('(int | string)')
        ;
    });

    test('verifies call stack order reflects true chronological top during re-entrancy', function () {
        $ref = new ReflectionClass(TemplateManager::class);
        $activeCallProp = $ref->getProperty('activeCallOrder');

        TemplateManager::pushCallFrame('FuncA');
        TemplateManager::pushCallFrame('FuncB');
        TemplateManager::pushCallFrame('FuncA');

        expect($activeCallProp->getValue())->toBe(['FuncA', 'FuncB', 'FuncA']);

        TemplateManager::popCallFrame('FuncA');
        expect($activeCallProp->getValue())->toBe(['FuncA', 'FuncB']);

        TemplateManager::popCallFrame('FuncB');
        expect($activeCallProp->getValue())->toBe(['FuncA']);

        TemplateManager::popCallFrame('FuncA');
        expect($activeCallProp->getValue())->toBe([]);
    });
});
