<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;
use TypePHP\TypePHP;

/**
 * 1. Interface with self-referencing generic constraint
 *
 * @template TRouteType of SelfBoundRouteInterface
 */
interface SelfBoundRouteInterface
{
    public function getName(): string;
}

/**
 * 2. BackedEnum implementing Interface<self> (Exact User Scenario)
 *
 * @implements SelfBoundRouteInterface<self>
 */
enum SelfBoundOrderRoute: string implements SelfBoundRouteInterface
{
    case OrderList = 'order_list';
    case OrderDetail = 'order_detail';

    public function getName(): string
    {
        return $this->value;
    }
}

/**
 * 3. Standard Class implementing Interface<self>
 *
 * @implements SelfBoundRouteInterface<self>
 */
class SelfBoundUserRoute implements SelfBoundRouteInterface
{
    public function getName(): string
    {
        return 'user_route';
    }
}

/**
 * 4. Generic Tree Node class extending Parent<self>
 *
 * @template TNode of SelfBoundTreeNode
 */
abstract class SelfBoundTreeNode
{
    /**
     * @var TNode|null
     */
    public ?self $parent = null;

    /**
     * @param TNode|null $parent
     */
    public function setParent(?self $parent): void
    {
        $this->parent = $parent;
    }
}

/**
 * @extends SelfBoundTreeNode<self>
 */
class SelfBoundConcreteNode extends SelfBoundTreeNode
{
}

class SelfBoundUnrelatedRoute implements SelfBoundRouteInterface
{
    public function getName(): string
    {
        return 'unrelated';
    }
}

class SelfBoundProbeService
{
    /**
     * Accepts any route whose generic argument is a covariant RouteInterface<*>
     *
     * @param SelfBoundRouteInterface<covariant SelfBoundRouteInterface<*>> $route
     */
    public function executeRoute(SelfBoundRouteInterface $route): string
    {
        return $route->getName();
    }

    /**
     * Accepts tree nodes parameterized with covariant TreeNodes
     *
     * @param SelfBoundTreeNode<covariant SelfBoundTreeNode<*>> $node
     */
    public function processNode(SelfBoundTreeNode $node): bool
    {
        return true;
    }

    /**
     * Method demanding specific UserRoute generic argument
     *
     * @param SelfBoundRouteInterface<SelfBoundUserRoute> $route
     */
    public function requireUserRoute(SelfBoundRouteInterface $route): string
    {
        return $route->getName();
    }
}

describe('self Resolution in Inherited Generic DocBlocks (@implements and @extends)', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    describe('1. Reified Generic Type Inspection (TypePHP::getGenericType)', function () {
        test('resolves self in @implements Interface<self> to the declaring enum FQCN', function () {
            $route = SelfBoundOrderRoute::OrderList;

            expect(TypePHP::getGenericType($route))->toBe(SelfBoundOrderRoute::class);
        });

        test('resolves self in @implements Interface<self> to the declaring class FQCN', function () {
            $userRoute = new SelfBoundUserRoute();

            expect(TypePHP::getGenericType($userRoute))->toBe(SelfBoundUserRoute::class);
        });

        test('resolves self in @extends Parent<self> to the declaring subclass FQCN', function () {
            $node = new SelfBoundConcreteNode();

            expect(TypePHP::getGenericType($node))->toBe(SelfBoundConcreteNode::class);
        });
    });

    describe('2. Parameter Validation with Covariant Wildcard Bounds', function () {
        test('accepts enum instance implementing Interface<self> when parameter expects Interface<covariant Interface<*>>', function () {
            $probe = new SelfBoundProbeService();

            $result = $probe->executeRoute(SelfBoundOrderRoute::OrderList);

            expect($result)->toBe('order_list');
        });

        test('accepts class instance implementing Interface<self> when parameter expects Interface<covariant Interface<*>>', function () {
            $probe = new SelfBoundProbeService();

            $result = $probe->executeRoute(new SelfBoundUserRoute());

            expect($result)->toBe('user_route');
        });

        test('accepts subclass extending Parent<self> when parameter expects Parent<covariant Parent<*>>', function () {
            $probe = new SelfBoundProbeService();

            $result = $probe->processNode(new SelfBoundConcreteNode());

            expect($result)->toBeTrue();
        });

        test('rejects instance when generic argument does not match expected concrete class', function () {
            $probe = new SelfBoundProbeService();

            expect(fn () => $probe->requireUserRoute(SelfBoundOrderRoute::OrderList))
                ->toThrow(TypeError::class)
            ;
        });
    });
});