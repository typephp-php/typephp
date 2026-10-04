<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Checker\ParamChecker;
use TypePHP\Internal\Checker\SelfOutChecker;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\RuntimeTypeChecker;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\Container;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\Tests\Fixtures\Types\ConfiguredProperty;

class StaticPropFixture
{
    /**
     * @var positive-int
     */
    public static int $count = 10;

    /**
     * @var positive-int
     */
    public static int $badCount = -5;

    /**
     * @var positive-int
     */
    public static int $disabledCount = -50;
}

class ScopeTestService
{
    /**
     * @param positive-int $id
     */
    public function execute(int $id): int
    {
        return $id;
    }
}

class RuntimeCheckerIgnoredCaller
{
    /**
     * @typephp-ignore
     */
    public static function run(callable $fn): mixed
    {
        return $fn();
    }
}

/**
 * @param mixed $a
 */
function runtimeUnconstrainedParams(mixed $a): void
{
}

/**
 * @return mixed
 */
function runtimeUnconstrainedReturn(): mixed
{
    return 100;
}

describe('RuntimeTypeChecker Unit Tests', function () {
    beforeEach(function () {
        Config::reset();
        RuntimeTypeChecker::reset();
        Config::set([
            'inline_vars' => [
                'properties' => true,
                'generics' => true,
                'callables' => true,
                'scalars' => true,
                'arrays' => true,
                'objects' => true,
            ],
        ]);
    });

    afterEach(function () {
        Config::reset();
        RuntimeTypeChecker::reset();
    });

    test('isEnabled reflects global configuration', function () {
        expect(RuntimeTypeChecker::isEnabled())->toBeTrue();

        Config::set(['enabled' => false]);
        expect(RuntimeTypeChecker::isEnabled())->toBeFalse();
    });

    test('withPendingGeneric pushes and pops instantiation context', function () {
        $instantiated = RuntimeTypeChecker::withPendingGeneric(
            Container::class . '<' . Dog::class . '>',
            fn () => new Container(new Dog()),
            __FILE__
        );

        expect($instantiated)->toBeInstanceOf(Container::class);

        Config::set(['enabled' => false]);
        $bypass = RuntimeTypeChecker::withPendingGeneric('any', fn () => 123);
        expect($bypass)->toBe(123);
    });

    test('checkStaticProperty validates static property and memoizes check', function () {
        StaticPropFixture::$count = 10;
        $val = RuntimeTypeChecker::checkStaticProperty(StaticPropFixture::class, 'count', 10, __FILE__, 1);
        expect($val)->toBe(10);

        $cached = RuntimeTypeChecker::checkStaticProperty(StaticPropFixture::class, 'count', 10, __FILE__, 1);
        expect($cached)->toBe(10);

        expect(fn () => RuntimeTypeChecker::checkStaticProperty(StaticPropFixture::class, 'badCount', -5, __FILE__, 1))
            ->toThrow(TypeError::class, 'positive-int')
        ;

        Config::set(['enabled' => false]);
        expect(RuntimeTypeChecker::checkStaticProperty(StaticPropFixture::class, 'disabledCount', -50))->toBe(-50);
    });

    test('checkVariable validates scalar types and handles disabled switch', function () {
        $valid = RuntimeTypeChecker::checkVariable(10, 'positive-int', 'age', __FILE__);
        expect($valid)->toBe(10);

        $invalid = RuntimeTypeChecker::checkVariable(-5, 'positive-int', 'age', __FILE__);
        expect($invalid)->toBeInstanceOf(ErrorMessage::class);

        Config::set(['enabled' => false]);
        expect(RuntimeTypeChecker::checkVariable(-5, 'positive-int', 'age', __FILE__))->toBe(-5);
    });

    test('checkProperty validates property assignments and handles disabled switch', function () {
        $fixture = new ConfiguredProperty();

        $valid = RuntimeTypeChecker::checkProperty([1, 2, 3], $fixture, 'numbers', __FILE__);
        expect($valid)->toBe([1, 2, 3]);

        $invalid = RuntimeTypeChecker::checkProperty(['a'], $fixture, 'numbers', __FILE__);
        expect($invalid)->toBeInstanceOf(ErrorMessage::class);

        Config::set(['enabled' => false]);
        expect(RuntimeTypeChecker::checkProperty(['a'], $fixture, 'numbers', __FILE__))->toBe(['a']);
    });

    test('checkMemberAssign validates array dimension assignment and handles disabled switch', function () {
        $root = ['count' => 5];
        $chain = [['dim', 'count']];

        $valid = RuntimeTypeChecker::checkMemberAssign($root, $chain, 10, 'array{count: int}', 'stats', __FILE__);
        expect($valid)->toBe(10);

        $invalid = RuntimeTypeChecker::checkMemberAssign($root, $chain, 'not_an_int', 'array{count: int}', 'stats', __FILE__);
        expect($invalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalid->getMessage())->toContain("['count'] must be of type int")
        ;

        expect($root['count'])->toBe(5);

        Config::set(['enabled' => false]);
        expect(RuntimeTypeChecker::checkMemberAssign($root, $chain, 'not_an_int', 'array{count: int}', 'stats', __FILE__))->toBe('not_an_int');
    });

    test('checkMemberAssign validates list append and multi-dimensional nested chains', function () {
        $list = [1, 2];
        $chainAppend = [['dim', null]];

        $validAppend = RuntimeTypeChecker::checkMemberAssign($list, $chainAppend, 3, 'list<positive-int>', 'list', __FILE__);
        expect($validAppend)->toBe(3);

        $invalidAppend = RuntimeTypeChecker::checkMemberAssign($list, $chainAppend, -1, 'list<positive-int>', 'list', __FILE__);
        expect($invalidAppend)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalidAppend->getMessage())->toContain('positive-int')
        ;

        $nested = ['user' => ['id' => 1]];
        $chainNested = [['dim', 'user'], ['dim', 'id']];
        $invalidNested = RuntimeTypeChecker::checkMemberAssign($nested, $chainNested, -99, 'array{user: array{id: positive-int}}', 'nested', __FILE__);
        expect($invalidNested)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalidNested->getMessage())->toContain("['user']['id']")
        ;
    });

    test('checkMemberAssign validates object shape properties and preserves state atomically', function () {
        $obj = (object) ['count' => 5];
        $chain = [['prop', 'count']];

        $valid = RuntimeTypeChecker::checkMemberAssign($obj, $chain, 10, 'object{count: int}', 'obj', __FILE__);
        expect($valid)->toBe(10);

        $invalid = RuntimeTypeChecker::checkMemberAssign($obj, $chain, 'not_an_int', 'object{count: int}', 'obj', __FILE__);
        expect($invalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalid->getMessage())->toContain('->count must be of type int')
        ;

        // Atomic state preservation: original stdClass must remain untouched
        expect($obj->count)->toBe(5);

        // Nested object shape
        $nestedObj = (object) ['user' => (object) ['id' => 1]];
        $nestedChain = [['prop', 'user'], ['prop', 'id']];
        $validNested = RuntimeTypeChecker::checkMemberAssign($nestedObj, $nestedChain, 100, 'object{user: object{id: positive-int}}', 'nestedObj', __FILE__);
        expect($validNested)->toBe(100);

        $invalidNested = RuntimeTypeChecker::checkMemberAssign($nestedObj, $nestedChain, -50, 'object{user: object{id: positive-int}}', 'nestedObj', __FILE__);
        expect($invalidNested)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalidNested->getMessage())->toContain('->user->id')
        ;
        expect($nestedObj->user->id)->toBe(1);
    });

    test('bindInstanceFromNode delegates to TemplateManager and respects disabled switch', function () {
        $dog = new Container(new Dog());
        $node = new GenericTypeNode(new IdentifierTypeNode(Container::class), [new IdentifierTypeNode(Dog::class)]);

        expect(RuntimeTypeChecker::bindInstanceFromNode($dog, $node))->toBeNull();

        $badNode = new GenericTypeNode(new IdentifierTypeNode(Container::class), [new IdentifierTypeNode(Cat::class)]);
        expect(RuntimeTypeChecker::bindInstanceFromNode($dog, $badNode))->toBeInstanceOf(ErrorMessage::class);

        Config::set(['enabled' => false]);
        expect(RuntimeTypeChecker::bindInstanceFromNode($dog, $badNode))->toBeNull();
    });

    test('setupScope validates parameters and handles disabled switch', function () {
        $service = new ScopeTestService();
        $target = ScopeTestService::class . '::execute';

        expect(RuntimeTypeChecker::setupScope($target, ['id' => 10], $service))->toBeNull();

        $err = RuntimeTypeChecker::setupScope($target, ['id' => -10], $service);
        expect($err)->toBeInstanceOf(ErrorMessage::class);

        Config::set(['enabled' => false]);
        expect(RuntimeTypeChecker::setupScope($target, ['id' => -10], $service))->toBeNull();
    });

    test('checkParams validates method contracts directly and handles disabled switch', function () {
        $target = UserService::class . '::find';
        $user = new UserService();

        expect(RuntimeTypeChecker::checkParams($target, ['id' => 10], $user))->toBeNull();

        $err = RuntimeTypeChecker::checkParams($target, ['id' => -1], $user);
        expect($err)->toBeInstanceOf(ErrorMessage::class);

        Config::set(['enabled' => false]);
        expect(RuntimeTypeChecker::checkParams($target, ['id' => -1], $user))->toBeNull();
    });

    test('checkParamOut validates post-conditions and handles disabled switch', function () {
        $target = 'TypePHP\Tests\Internal\Checker\internalParamOutScalarFixture';

        expect(RuntimeTypeChecker::checkParamOut($target, 'id', 42))->toBeNull();

        $err = RuntimeTypeChecker::checkParamOut($target, 'id', -50);
        expect($err)->toBeInstanceOf(ErrorMessage::class);

        Config::set(['enabled' => false]);
        expect(RuntimeTypeChecker::checkParamOut($target, 'id', -50))->toBeNull();
    });

    test('checkSelfOut executes state transition and handles disabled switch', function () {
        $session = new TypePHP\Tests\TypeChecking\Generics\FixtureSession();
        $target = TypePHP\Tests\TypeChecking\Generics\FixtureSession::class . '::login';

        RuntimeTypeChecker::checkSelfOut($target, $session);
        expect(true)->toBeTrue();

        Config::set(['enabled' => false]);
        RuntimeTypeChecker::checkSelfOut($target, $session);
        expect(true)->toBeTrue();
    });

    test('checkReturn validates return types and handles disabled switch', function () {
        $target = UserService::class . '::find';
        $user = new UserService();

        $valid = ['id' => 10, 'name' => 'Alice'];
        expect(RuntimeTypeChecker::checkReturn($target, $valid, $user))->toBe($valid);

        $invalid = ['id' => -10, 'name' => 'Alice'];
        expect(RuntimeTypeChecker::checkReturn($target, $invalid, $user))->toBeInstanceOf(ErrorMessage::class);

        Config::set(['enabled' => false]);
        expect(RuntimeTypeChecker::checkReturn($target, $invalid, $user))->toBe($invalid);
    });

    test('checkSend returns send value or validates generator and handles disabled switch', function () {
        expect(RuntimeTypeChecker::checkSend('nonExistentFunc', null))->toBeNull();

        Config::set(['enabled' => false]);
        expect(RuntimeTypeChecker::checkSend('nonExistentFunc', 100))->toBe(100);
    });

    test('checkYield returns yielded value and handles disabled switch', function () {
        expect(RuntimeTypeChecker::checkYield('nonExistentFunc', 'key', 'value'))->toBe('value');

        Config::set(['enabled' => false]);
        expect(RuntimeTypeChecker::checkYield('nonExistentFunc', 'key', 'value'))->toBe('value');
    });

    test('wrapCallable and wrapIterable respect disabled switch', function () {
        $cb = fn () => 1;
        expect(RuntimeTypeChecker::wrapCallable('nonExistent', 'arg', $cb))->toBe($cb);

        $iter = [1, 2, 3];
        expect(RuntimeTypeChecker::wrapIterable('nonExistent', 'arg', $iter))->toBe($iter);

        Config::set(['enabled' => false]);
        expect(RuntimeTypeChecker::wrapCallable('nonExistent', 'arg', $cb))->toBe($cb);
        expect(RuntimeTypeChecker::wrapIterable('nonExistent', 'arg', $iter))->toBe($iter);
    });

    test('prepareClone and cloneInstance clone generic bindings', function () {
        $dog = new Dog();
        expect(RuntimeTypeChecker::prepareClone($dog))->toBe($dog);
        expect(RuntimeTypeChecker::prepareClone(123))->toBe(123);

        $cloned = new Dog();
        expect(RuntimeTypeChecker::cloneInstance($cloned, $dog))->toBe($cloned);
        expect(RuntimeTypeChecker::cloneInstance(123, 456))->toBe(123);
    });

    test('inferTypeFromValue and getRegistry helpers', function () {
        $node = RuntimeTypeChecker::inferTypeFromValue(10);
        expect((string) $node)->toBe('int');

        expect(RuntimeTypeChecker::getRegistry())->toBeInstanceOf(TypeValidatorRegistry::class);
    });

    test('handles ignored callers across checkers and return methods', function () {
        $dog = new Container(new Dog());
        $badNode = new GenericTypeNode(new IdentifierTypeNode(Container::class), [new IdentifierTypeNode(Cat::class)]);

        $resNode = RuntimeCheckerIgnoredCaller::run(
            fn () => RuntimeTypeChecker::bindInstanceFromNode($dog, $badNode)
        );
        expect($resNode)->toBeNull();

        $resVar = RuntimeCheckerIgnoredCaller::run(
            fn () => RuntimeTypeChecker::checkVariable(-5, 'positive-int', 'val', __FILE__)
        );
        expect($resVar)->toBe(-5);

        $fixture = new ConfiguredProperty();
        $resProp = RuntimeCheckerIgnoredCaller::run(
            fn () => RuntimeTypeChecker::checkProperty(['bad'], $fixture, 'numbers', __FILE__)
        );
        expect($resProp)->toBe(['bad']);

        $resMember = RuntimeCheckerIgnoredCaller::run(
            fn () => RuntimeTypeChecker::checkMemberAssign(['count' => 5], [['dim', 'count']], 'not_an_int', 'array{count: int}', 'stats', __FILE__)
        );
        expect($resMember)->toBe('not_an_int');

        $resParams = RuntimeCheckerIgnoredCaller::run(
            fn () => RuntimeTypeChecker::checkParams(UserService::class . '::find', ['id' => -1], new UserService())
        );
        expect($resParams)->toBeNull();

        $resOut = RuntimeCheckerIgnoredCaller::run(
            fn () => RuntimeTypeChecker::checkParamOut('TypePHP\Tests\Internal\Checker\internalParamOutScalarFixture', 'id', -50)
        );
        expect($resOut)->toBeNull();

        RuntimeCheckerIgnoredCaller::run(
            fn () => RuntimeTypeChecker::checkSelfOut(TypePHP\Tests\TypeChecking\Generics\FixtureSession::class . '::login', new TypePHP\Tests\TypeChecking\Generics\FixtureSession())
        );

        $resRet = RuntimeCheckerIgnoredCaller::run(
            fn () => RuntimeTypeChecker::checkReturn(UserService::class . '::find', ['id' => -1, 'name' => 'Alice'], new UserService())
        );
        expect($resRet)->toBe(['id' => -1, 'name' => 'Alice']);

        $resSend = RuntimeCheckerIgnoredCaller::run(
            fn () => RuntimeTypeChecker::checkSend('sampleGeneratorFixture', -50)
        );
        expect($resSend)->toBe(-50);

        $resYield = RuntimeCheckerIgnoredCaller::run(
            fn () => RuntimeTypeChecker::checkYield('sampleGeneratorFixture', 123, -50)
        );
        expect($resYield)->toBe(-50);
    });

    test('covers checkSelfOut effective function cache branch', function () {
        SelfOutChecker::$noSelfOutContractCache['stdClass::runMethod'] = true;
        unset(SelfOutChecker::$noSelfOutContractCache['FakeParent::runMethod']);

        RuntimeTypeChecker::checkSelfOut('FakeParent::runMethod', new stdClass());

        expect(SelfOutChecker::$noSelfOutContractCache['FakeParent::runMethod'])->toBeTrue();
    });

    test('handles vendor boundary bypasses across methods', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_rt_vendor_' . uniqid();
        $vendorDir = $tempDir . '/vendor/acme/caller';
        mkdir($vendorDir, 0777, true);
        $vendorFile = $vendorDir . '/VendorCaller.php';

        file_put_contents(
            $vendorFile,
            <<<'PHP'
<?php
namespace Acme\VendorTest;

use TypePHP\Internal\RuntimeTypeChecker;

class VendorCaller
{
    public static function call(callable $fn): mixed
    {
        return $fn();
    }
}

function vendorAction(): void
{
}
PHP
        );

        require_once $vendorFile;

        try {
            $fnName = 'Acme\VendorTest\vendorAction';

            $dog = new Container(new Dog());
            $badNode = new GenericTypeNode(new IdentifierTypeNode(Container::class), [new IdentifierTypeNode(Cat::class)]);

            $resNode = Acme\VendorTest\VendorCaller::call(
                fn () => RuntimeTypeChecker::bindInstanceFromNode($dog, $badNode, $fnName)
            );
            expect($resNode)->toBeNull();

            $resVar = Acme\VendorTest\VendorCaller::call(
                fn () => RuntimeTypeChecker::checkVariable(-5, 'positive-int', 'v', __FILE__, $fnName)
            );
            expect($resVar)->toBe(-5);

            $resProp = Acme\VendorTest\VendorCaller::call(
                fn () => RuntimeTypeChecker::checkProperty(['bad'], 'Acme\VendorTest\VendorCaller', 'prop', __FILE__)
            );
            expect($resProp)->toBe(['bad']);

            $resMember = Acme\VendorTest\VendorCaller::call(
                fn () => RuntimeTypeChecker::checkMemberAssign(['count' => 5], [['dim', 'count']], 'not_an_int', 'array{count: int}', 'stats', __FILE__, $fnName)
            );
            expect($resMember)->toBe('not_an_int');

            $resParams = Acme\VendorTest\VendorCaller::call(
                fn () => RuntimeTypeChecker::checkParams($fnName, ['a' => 1])
            );
            expect($resParams)->toBeNull();

            $resOut = Acme\VendorTest\VendorCaller::call(
                fn () => RuntimeTypeChecker::checkParamOut($fnName, 'param', 1)
            );
            expect($resOut)->toBeNull();

            Acme\VendorTest\VendorCaller::call(
                fn () => RuntimeTypeChecker::checkSelfOut($fnName, new stdClass())
            );

            $resRet = Acme\VendorTest\VendorCaller::call(
                fn () => RuntimeTypeChecker::checkReturn($fnName, 'any')
            );
            expect($resRet)->toBe('any');

            $resSend = Acme\VendorTest\VendorCaller::call(
                fn () => RuntimeTypeChecker::checkSend($fnName, 'val')
            );
            expect($resSend)->toBe('val');

            $resYield = Acme\VendorTest\VendorCaller::call(
                fn () => RuntimeTypeChecker::checkYield($fnName, 'k', 'v')
            );
            expect($resYield)->toBe('v');

            $cb = fn () => 1;
            $resCb = Acme\VendorTest\VendorCaller::call(
                fn () => RuntimeTypeChecker::wrapCallable($fnName, 'cb', $cb)
            );
            expect($resCb)->toBe($cb);

            $iter = [1];
            $resIter = Acme\VendorTest\VendorCaller::call(
                fn () => RuntimeTypeChecker::wrapIterable($fnName, 'iter', $iter)
            );
            expect($resIter)->toBe($iter);
        } finally {
            @unlink($vendorFile);
            @rmdir($vendorDir);
            @rmdir($tempDir . '/vendor/acme');
            @rmdir($tempDir . '/vendor');
            @rmdir($tempDir);
        }
    });

    test('covers setupScope and checkReturn unconstrained contracts and caching branches', function () {
        ParamChecker::$noParamContractCache['testNoParamCached'] = true;
        RuntimeTypeChecker::$hasMethodTemplatesCache['testNoParamCached'] = false;
        expect(RuntimeTypeChecker::setupScope('testNoParamCached', []))->toBeNull();

        expect(RuntimeTypeChecker::setupScope('runtimeUnconstrainedParams', ['a' => 1]))->toBeNull();

        SelfOutChecker::$noSelfOutContractCache['stdClass::none'] = true;
        RuntimeTypeChecker::checkSelfOut('stdClass::none', new stdClass());

        expect(RuntimeTypeChecker::checkReturn('runtimeUnconstrainedReturn', 100))->toBe(100);
    });
});
