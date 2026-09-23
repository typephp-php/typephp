<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Wrapper;

use ArrayIterator;
use Generator;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use ReflectionClass;
use TypeError;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Resolver\CallerBoundaryResolver;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Internal\Wrapper\IterableWrapper;

/**
 * @param iterable<string, positive-int> $items
 */
function fixtureValidIterableFunction(iterable $items): void {}

/**
 * @param iterable $unparameterized
 */
function fixtureBareIterableFunction(iterable $unparameterized): void {}

/**
 * @return iterable<string, positive-int>
 */
function fixtureReturnIterableFunction(): iterable
{
    return ['item1' => 10, 'item2' => 20];
}

describe('IterableWrapper Unit Tests', function () {
    afterEach(function () {
        CallerBoundaryResolver::reset();
        Config::reset();
        DocblockParser::reset();
    });

    describe('wrap() Pipeline & Direct Fallbacks', function () {
        test('returns raw value if input is not iterable', function () {
            $registry = new TypeValidatorRegistry();
            $notIterable = 12345;

            $result = IterableWrapper::wrap(
                __NAMESPACE__ . '\fixtureValidIterableFunction',
                'items',
                $notIterable,
                $registry
            );

            expect($result)->toBe(12345);
        });

        test('returns array unchanged when passed as parameter', function () {
            $registry = new TypeValidatorRegistry();
            $arrayData = ['a' => 1, 'b' => 2];

            $result = IterableWrapper::wrap(
                __NAMESPACE__ . '\fixtureValidIterableFunction',
                'items',
                $arrayData,
                $registry
            );

            expect($result)->toBe($arrayData);
        });

        test('returns iterable untouched when function has no contract', function () {
            $registry = new TypeValidatorRegistry();
            $iterator = new ArrayIterator([1, 2, 3]);

            $result = IterableWrapper::wrap(
                'nonExistentFunction123',
                'missingParam',
                $iterator,
                $registry
            );

            expect($result)->toBe($iterator);
        });

        test('returns iterable untouched when type is not an iterable type keyword', function () {
            $registry = new TypeValidatorRegistry();
            $iterator = new ArrayIterator([1, 2, 3]);

            $ref = new ReflectionClass(DocblockParser::class);
            $prop = $ref->getProperty('cache');
            $cache = $prop->getValue();
            $cache['testNonIterableFunc'] = [
                'types' => ['arg' => new IdentifierTypeNode('int')],
                'return' => null,
                'aliases' => [],
                'templates' => [],
                'classTemplates' => [],
            ];
            $prop->setValue(null, $cache);

            try {
                $result = IterableWrapper::wrap('testNonIterableFunc', 'arg', $iterator, $registry);
                expect($result)->toBe($iterator);
            } finally {
                DocblockParser::reset();
            }
        });

        test('returns iterable untouched when type has neither key nor item types', function () {
            $registry = new TypeValidatorRegistry();
            $iterator = new ArrayIterator([1, 2, 3]);

            $result = IterableWrapper::wrap(
                __NAMESPACE__ . '\fixtureBareIterableFunction',
                'unparameterized',
                $iterator,
                $registry
            );

            expect($result)->toBe($iterator);
        });

        test('wraps array returned from function into generator proxy', function () {
            $registry = new TypeValidatorRegistry();

            $wrapped = IterableWrapper::wrap(
                __NAMESPACE__ . '\fixtureReturnIterableFunction',
                'return',
                ['alpha' => 10, 'beta' => 20],
                $registry
            );

            expect($wrapped)->toBeInstanceOf(Generator::class);

            $out = [];
            foreach ($wrapped as $k => $v) {
                $out[$k] = $v;
            }
            expect($out)->toBe(['alpha' => 10, 'beta' => 20]);
        });

        test('wraps native generator parameter directly', function () {
            $registry = new TypeValidatorRegistry();
            $gen = (function () {
                yield 'key1' => 10;
                yield 'key2' => 20;
            })();

            $wrapped = IterableWrapper::wrap(
                __NAMESPACE__ . '\fixtureValidIterableFunction',
                'items',
                $gen,
                $registry
            );

            expect($wrapped)->toBeInstanceOf(Generator::class);

            $collected = [];
            foreach ($wrapped as $k => $v) {
                $collected[$k] = $v;
            }
            expect($collected)->toBe(['key1' => 10, 'key2' => 20]);
        });
    });

    describe('extractKeyAndItemTypeNodes() Helper', function () {
        test('extracts key and item types from type alias', function () {
            $ref = new ReflectionClass(IterableWrapper::class);
            $method = $ref->getMethod('extractKeyAndItemTypeNodes');

            $aliasNode = new IdentifierTypeNode('MyIterableAlias');
            $aliasedType = new GenericTypeNode(
                new IdentifierTypeNode('iterable'),
                [new IdentifierTypeNode('string'), new IdentifierTypeNode('positive-int')]
            );

            $res = $method->invoke(null, $aliasNode, ['MyIterableAlias' => $aliasedType]);

            expect($res[0])->toBeInstanceOf(IdentifierTypeNode::class)
                ->and((string) $res[0])->toBe('string')
                ->and($res[1])->toBeInstanceOf(IdentifierTypeNode::class)
                ->and((string) $res[1])->toBe('positive-int')
            ;
        });

        test('extracts item type from array type node', function () {
            $ref = new ReflectionClass(IterableWrapper::class);
            $method = $ref->getMethod('extractKeyAndItemTypeNodes');

            $arrayType = new ArrayTypeNode(new IdentifierTypeNode('positive-int'));

            $res = $method->invoke(null, $arrayType, []);

            expect($res[0])->toBeNull()
                ->and($res[1])->toBeInstanceOf(IdentifierTypeNode::class)
                ->and((string) $res[1])->toBe('positive-int')
            ;
        });

        test('extracts item type from single argument generic node', function () {
            $ref = new ReflectionClass(IterableWrapper::class);
            $method = $ref->getMethod('extractKeyAndItemTypeNodes');

            $singleGeneric = new GenericTypeNode(
                new IdentifierTypeNode('iterable'),
                [new IdentifierTypeNode('string')]
            );

            $res = $method->invoke(null, $singleGeneric, []);

            expect($res[0])->toBeNull()
                ->and($res[1])->toBeInstanceOf(IdentifierTypeNode::class)
                ->and((string) $res[1])->toBe('string')
            ;
        });
    });

    describe('createValidationCallback() Validation & Vendor Boundary Bypasses', function () {
        test('throws TypeError when yielded key violates contract', function () {
            $registry = new TypeValidatorRegistry();
            $badKeyIterator = new ArrayIterator([123 => 10]);

            $wrapped = IterableWrapper::wrap(
                __NAMESPACE__ . '\fixtureValidIterableFunction',
                'items',
                $badKeyIterator,
                $registry
            );

            expect(function () use ($wrapped) {
                foreach ($wrapped as $k => $v) {
                }
            })->toThrow(TypeError::class, 'key must be of type string');
        });

        test('throws TypeError when yielded value violates contract', function () {
            $registry = new TypeValidatorRegistry();
            $badValueIterator = new ArrayIterator(['valid_key' => -5]);

            $wrapped = IterableWrapper::wrap(
                __NAMESPACE__ . '\fixtureValidIterableFunction',
                'items',
                $badValueIterator,
                $registry
            );

            expect(function () use ($wrapped) {
                foreach ($wrapped as $k => $v) {
                }
            })->toThrow(TypeError::class, 'value must be of type positive-int');
        });

        test('bypasses key and value validation errors when caller boundary resolver should bypass function', function () {
            $registry = new TypeValidatorRegistry();

            $tempBase = sys_get_temp_dir() . '/typephp_vendor_iter_' . uniqid();
            $vendorDir = $tempBase . '/vendor/acme/runner';
            mkdir($vendorDir, 0777, true);
            $runnerFile = $vendorDir . '/VendorIterableCaller.php';

            $runnerCode = <<<'PHP'
<?php
namespace Acme\VendorIterableRunner;

class VendorIterableCaller
{
    public static function execute(callable $action): mixed
    {
        return $action();
    }
}
PHP;
            file_put_contents($runnerFile, $runnerCode);

            try {
                require_once $runnerFile;

                $refWrapper = new ReflectionClass(IterableWrapper::class);
                $callbackMethod = $refWrapper->getMethod('createValidationCallback');

                $callback = $callbackMethod->invoke(
                    null,
                    $registry,
                    new IdentifierTypeNode('string'),
                    new IdentifierTypeNode('positive-int'),
                    'Acme\\VendorIterableRunner\\VendorIterableCaller::execute(): Iterator $feed',
                    'Acme\\VendorIterableRunner\\VendorIterableCaller::execute'
                );

                \Acme\VendorIterableRunner\VendorIterableCaller::execute(function () use ($callback) {
                    $callback(12345, 10);
                    $callback('valid_key', -50);
                });

                $callbackNoFunc = $callbackMethod->invoke(
                    null,
                    $registry,
                    new IdentifierTypeNode('string'),
                    new IdentifierTypeNode('positive-int'),
                    'Iterator $feed',
                    ''
                );

                \Acme\VendorIterableRunner\VendorIterableCaller::execute(function () use ($callbackNoFunc) {
                    $callbackNoFunc(12345, 10);
                    $callbackNoFunc('valid_key', -50);
                });

                expect(true)->toBeTrue();
            } finally {
                @unlink($runnerFile);
                @rmdir($vendorDir);
                @rmdir($tempBase . '/vendor/acme');
                @rmdir($tempBase . '/vendor');
                @rmdir($tempBase);
                CallerBoundaryResolver::reset();
            }
        });
    });
});
