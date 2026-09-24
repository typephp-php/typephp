<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Validator\GenericValidator;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Enums\Suit;
use TypePHP\Tests\Fixtures\Enums\TransactionStatus;
use TypePHP\Tests\Fixtures\Generics\Producer;
use TypePHP\Tests\Fixtures\Types\BitmaskFlags;
use TypePHP\Tests\Fixtures\Types\DatabaseDriverMap;

if (! \defined('TYPEPHP_TEST_GLOBAL_MAP')) {
    \define('TYPEPHP_TEST_GLOBAL_MAP', ['first' => 10, 'second' => 20]);
}

if (! \defined('TYPEPHP_TEST_GLOBAL_SCALAR')) {
    \define('TYPEPHP_TEST_GLOBAL_SCALAR', 'scalar_val');
}

class GenericConstFlagsFixture
{
    public const FLAG_A = 1;
    public const FLAG_B = 2;
    public const FLAGS_ARRAY = [1, 2, 4];
    public const SCALAR_CONST = 'not_an_array';
}

describe('GenericValidator Unit Tests', function () {
    beforeEach(function () {
        $this->registry = new TypeValidatorRegistry();
        $this->validator = new GenericValidator();
    });

    afterEach(function () {
        Config::reset();
    });

    test('validates key-of on array shape with constant fetch keys', function () {
        $shape = new GenericTypeNode(new IdentifierTypeNode('key-of'), [
            ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(new ConstFetchNode('self', 'SOME_KEY'), false, new IdentifierTypeNode('string')),
            ]),
        ]);

        expect($this->validator->validate('self::SOME_KEY', $shape, 'key', $this->registry))->toBeNull();
        expect($this->validator->validate('other', $shape, 'key', $this->registry))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates class-string with non-identifier target node', function () {
        $node = new GenericTypeNode(new IdentifierTypeNode('class-string'), [
            new GenericTypeNode(new IdentifierTypeNode(Producer::class), [new IdentifierTypeNode(Dog::class)]),
        ]);

        expect($this->validator->validate(stdClass::class, $node, 'cls', $this->registry))->toBeNull();
    });

    test('validates single argument array with scalars in hybrid mode', function () {
        Config::set(['array_validation' => 'hybrid']);

        $large = range(1, 150);
        $node = new GenericTypeNode(new IdentifierTypeNode('array'), [new IdentifierTypeNode('int')]);

        expect($this->validator->validate($large, $node, 'arr', $this->registry))->toBeNull();
    });

    test('validates two argument array with complex object generic values in hybrid mode', function () {
        Config::set(['array_validation' => 'hybrid']);

        $large = [];
        for ($i = 0; $i < 150; $i++) {
            $large["key_{$i}"] = new Producer(new Dog());
        }

        $node = new GenericTypeNode(new IdentifierTypeNode('array'), [
            new IdentifierTypeNode('string'),
            new GenericTypeNode(new IdentifierTypeNode(Producer::class), [new IdentifierTypeNode(Dog::class)]),
        ]);

        expect($this->validator->validate($large, $node, 'arr', $this->registry))->toBeNull();
    });

    test('covers resolveConstantValue for global constants in key-of and value-of', function () {
        $globalKeyOf = new GenericTypeNode(new IdentifierTypeNode('key-of'), [
            new ConstTypeNode(new ConstFetchNode('', 'TYPEPHP_TEST_GLOBAL_MAP')),
        ]);
        expect($this->validator->validate('first', $globalKeyOf, 'arg', $this->registry))->toBeNull()
            ->and($this->validator->validate('second', $globalKeyOf, 'arg', $this->registry))->toBeNull()
            ->and($this->validator->validate('third', $globalKeyOf, 'arg', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $globalValueOf = new GenericTypeNode(new IdentifierTypeNode('value-of'), [
            new ConstTypeNode(new ConstFetchNode('', 'TYPEPHP_TEST_GLOBAL_MAP')),
        ]);
        expect($this->validator->validate(10, $globalValueOf, 'arg', $this->registry))->toBeNull()
            ->and($this->validator->validate(20, $globalValueOf, 'arg', $this->registry))->toBeNull()
            ->and($this->validator->validate(999, $globalValueOf, 'arg', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates key-of on array shapes with quoted string keys and explicit integer keys', function () {
        $quotedShape = new GenericTypeNode(new IdentifierTypeNode('key-of'), [
            ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(new ConstExprStringNode('user_id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('int')),
                new ArrayShapeItemNode(new ConstExprStringNode('user_name', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('string')),
            ]),
        ]);
        expect($this->validator->validate('user_id', $quotedShape, 'key', $this->registry))->toBeNull()
            ->and($this->validator->validate('user_name', $quotedShape, 'key', $this->registry))->toBeNull()
            ->and($this->validator->validate('missing_key', $quotedShape, 'key', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $intKeyShape = new GenericTypeNode(new IdentifierTypeNode('key-of'), [
            ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(new ConstExprIntegerNode('0'), false, new IdentifierTypeNode('string')),
                new ArrayShapeItemNode(new ConstExprIntegerNode('10'), false, new IdentifierTypeNode('int')),
            ]),
        ]);
        expect($this->validator->validate(0, $intKeyShape, 'key', $this->registry))->toBeNull()
            ->and($this->validator->validate(10, $intKeyShape, 'key', $this->registry))->toBeNull()
            ->and($this->validator->validate(1, $intKeyShape, 'key', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates key-of on nested value-of with ConstFetchNode and keyless tuple items', function () {
        $nestedShape = new GenericTypeNode(new IdentifierTypeNode('key-of'), [
            new GenericTypeNode(new IdentifierTypeNode('value-of'), [
                ArrayShapeNode::createSealed([
                    new ArrayShapeItemNode(new IdentifierTypeNode('config'), false, ArrayShapeNode::createSealed([
                        new ArrayShapeItemNode(new ConstExprStringNode('host', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('string')),
                        new ArrayShapeItemNode(new ConstExprIntegerNode('0'), false, new IdentifierTypeNode('int')),
                        new ArrayShapeItemNode(new IdentifierTypeNode('port'), false, new IdentifierTypeNode('int')),
                        new ArrayShapeItemNode(new ConstFetchNode(DatabaseDriverMap::class, 'PUBLIC_MAP'), false, new IdentifierTypeNode('int')),
                        new ArrayShapeItemNode(null, false, new IdentifierTypeNode('string')),
                    ])),
                ]),
            ]),
        ]);

        expect($this->validator->validate('host', $nestedShape, 'key', $this->registry))->toBeNull()
            ->and($this->validator->validate(0, $nestedShape, 'key', $this->registry))->toBeNull()
            ->and($this->validator->validate('port', $nestedShape, 'key', $this->registry))->toBeNull()
            ->and($this->validator->validate(DatabaseDriverMap::class . '::PUBLIC_MAP', $nestedShape, 'key', $this->registry))->toBeNull()
            ->and($this->validator->validate('unmatched_key', $nestedShape, 'key', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates key-of on Enums and non-array constant fallbacks', function () {
        $enumKeyOf = new GenericTypeNode(new IdentifierTypeNode('key-of'), [new IdentifierTypeNode(Suit::class)]);
        expect($this->validator->validate('Hearts', $enumKeyOf, 'key', $this->registry))->toBeNull()
            ->and($this->validator->validate('invalid_case', $enumKeyOf, 'key', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $constKeyOf = new GenericTypeNode(new IdentifierTypeNode('key-of'), [
            new ConstTypeNode(new ConstFetchNode(DatabaseDriverMap::class, 'PUBLIC_MAP')),
        ]);
        expect($this->validator->validate(true, $constKeyOf, 'key', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate(1.5, $constKeyOf, 'key', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $scalarConstKeyOf = new GenericTypeNode(new IdentifierTypeNode('key-of'), [
            new ConstTypeNode(new ConstFetchNode(GenericConstFlagsFixture::class, 'SCALAR_CONST')),
        ]);
        expect($this->validator->validate('anything', $scalarConstKeyOf, 'key', $this->registry))->toBeNull();
    });

    test('validates value-of on BackedEnums, UnitEnums, and non-array constants', function () {
        $backedEnum = new GenericTypeNode(new IdentifierTypeNode('value-of'), [new IdentifierTypeNode(TransactionStatus::class)]);
        expect($this->validator->validate(1, $backedEnum, 'val', $this->registry))->toBeNull()
            ->and($this->validator->validate(99, $backedEnum, 'val', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $unitEnum = new GenericTypeNode(new IdentifierTypeNode('value-of'), [new IdentifierTypeNode(Suit::class)]);
        expect($this->validator->validate('Hearts', $unitEnum, 'val', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        $scalarConstValueOf = new GenericTypeNode(new IdentifierTypeNode('value-of'), [
            new ConstTypeNode(new ConstFetchNode(GenericConstFlagsFixture::class, 'SCALAR_CONST')),
        ]);
        expect($this->validator->validate('anything', $scalarConstValueOf, 'val', $this->registry))->toBeNull();

        $shapeValueOf = new GenericTypeNode(new IdentifierTypeNode('value-of'), [
            ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(new IdentifierTypeNode('id'), false, new IdentifierTypeNode('positive-int')),
                new ArrayShapeItemNode(new IdentifierTypeNode('role'), false, new IdentifierTypeNode('string')),
            ]),
        ]);
        expect($this->validator->validate(10, $shapeValueOf, 'val', $this->registry))->toBeNull()
            ->and($this->validator->validate('admin', $shapeValueOf, 'val', $this->registry))->toBeNull()
            ->and($this->validator->validate(false, $shapeValueOf, 'val', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates int-mask and int-mask-of with non-integers, wildcards, and literals', function () {
        $intMask = new GenericTypeNode(new IdentifierTypeNode('int-mask'), [
            new ConstTypeNode(new ConstExprIntegerNode('1')),
            new ConstTypeNode(new ConstExprIntegerNode('2')),
        ]);
        expect($this->validator->validate('not_an_int', $intMask, 'mask', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate(3, $intMask, 'mask', $this->registry))->toBeNull()
            ->and($this->validator->validate(4, $intMask, 'mask', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $intMaskOf = new GenericTypeNode(new IdentifierTypeNode('int-mask-of'), [
            new ConstTypeNode(new ConstFetchNode(BitmaskFlags::class, 'FLAG_*')),
        ]);
        expect($this->validator->validate('not_an_int', $intMaskOf, 'mask', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate(12.34, $intMaskOf, 'mask', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        expect($this->validator->validate(1, $intMaskOf, 'mask', $this->registry))->toBeNull()
            ->and($this->validator->validate(3, $intMaskOf, 'mask', $this->registry))->toBeNull()
            ->and($this->validator->validate(7, $intMaskOf, 'mask', $this->registry))->toBeNull()
            ->and($this->validator->validate(16, $intMaskOf, 'mask', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $intMaskOfArray = new GenericTypeNode(new IdentifierTypeNode('int-mask-of'), [
            new ConstTypeNode(new ConstFetchNode(GenericConstFlagsFixture::class, 'FLAGS_ARRAY')),
        ]);
        expect($this->validator->validate(7, $intMaskOfArray, 'mask', $this->registry))->toBeNull()
            ->and($this->validator->validate(16, $intMaskOfArray, 'mask', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $intMaskOfSingle = new GenericTypeNode(new IdentifierTypeNode('int-mask-of'), [
            new ConstTypeNode(new ConstFetchNode(GenericConstFlagsFixture::class, 'FLAG_A')),
        ]);
        expect($this->validator->validate(1, $intMaskOfSingle, 'mask', $this->registry))->toBeNull()
            ->and($this->validator->validate(4, $intMaskOfSingle, 'mask', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $emptyMaskOf = new GenericTypeNode(new IdentifierTypeNode('int-mask-of'), [new IdentifierTypeNode('int')]);
        expect($this->validator->validate(1, $emptyMaskOf, 'mask', $this->registry))->toBeNull();
    });

    test('validates integer ranges with min and max bounds and non-int inputs', function () {
        $range = new GenericTypeNode(new IdentifierTypeNode('int'), [
            new ConstExprIntegerNode('1'),
            new ConstExprIntegerNode('10'),
        ]);

        expect($this->validator->validate(5, $range, 'val', $this->registry))->toBeNull()
            ->and($this->validator->validate(0, $range, 'val', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate(15, $range, 'val', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate('not_int', $range, 'val', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate(3.14, $range, 'val', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $minRange = new GenericTypeNode(new IdentifierTypeNode('int'), [
            new IdentifierTypeNode('min'),
            new ConstExprIntegerNode('100'),
        ]);
        expect($this->validator->validate(-500, $minRange, 'val', $this->registry))->toBeNull()
            ->and($this->validator->validate(105, $minRange, 'val', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $maxRange = new GenericTypeNode(new IdentifierTypeNode('int'), [
            new ConstExprIntegerNode('0'),
            new IdentifierTypeNode('max'),
        ]);
        expect($this->validator->validate(500, $maxRange, 'val', $this->registry))->toBeNull()
            ->and($this->validator->validate(-1, $maxRange, 'val', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates class-string edge cases including non-string, object/mixed bounds, and intersection failures', function () {
        $bareClassString = new GenericTypeNode(new IdentifierTypeNode('class-string'), []);
        expect($this->validator->validate(stdClass::class, $bareClassString, 'cls', $this->registry))->toBeNull()
            ->and($this->validator->validate(12345, $bareClassString, 'cls', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate('Invalid Class Name!', $bareClassString, 'cls', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $objectBound = new GenericTypeNode(new IdentifierTypeNode('class-string'), [new IdentifierTypeNode('object')]);
        expect($this->validator->validate(stdClass::class, $objectBound, 'cls', $this->registry))->toBeNull();

        $mixedBound = new GenericTypeNode(new IdentifierTypeNode('class-string'), [new IdentifierTypeNode('mixed')]);
        expect($this->validator->validate(stdClass::class, $mixedBound, 'cls', $this->registry))->toBeNull();

        $singleBound = new GenericTypeNode(new IdentifierTypeNode('class-string'), [new IdentifierTypeNode(DateTime::class)]);
        expect($this->validator->validate(DateTime::class, $singleBound, 'cls', $this->registry))->toBeNull()
            ->and($this->validator->validate(stdClass::class, $singleBound, 'cls', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $unionBound = new GenericTypeNode(new IdentifierTypeNode('class-string'), [
            new UnionTypeNode([new IdentifierTypeNode(DateTime::class), new IdentifierTypeNode(ArrayObject::class)]),
        ]);
        expect($this->validator->validate(DateTime::class, $unionBound, 'cls', $this->registry))->toBeNull()
            ->and($this->validator->validate(ArrayObject::class, $unionBound, 'cls', $this->registry))->toBeNull()
            ->and($this->validator->validate(stdClass::class, $unionBound, 'cls', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;
        $intersectionBound = new GenericTypeNode(new IdentifierTypeNode('class-string'), [
            new IntersectionTypeNode([new IdentifierTypeNode('Countable'), new IdentifierTypeNode('ArrayAccess')]),
        ]);
        expect($this->validator->validate(ArrayObject::class, $intersectionBound, 'cls', $this->registry))->toBeNull()
            ->and($this->validator->validate(stdClass::class, $intersectionBound, 'cls', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates lists and non-empty-lists with mixed shortcuts, complex objects, and hybrid sampling', function () {
        $intList = new GenericTypeNode(new IdentifierTypeNode('list'), [new IdentifierTypeNode('int')]);
        expect($this->validator->validate('not_array', $intList, 'list', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate([], $intList, 'list', $this->registry))->toBeNull()
        ;

        $nonEmptyList = new GenericTypeNode(new IdentifierTypeNode('non-empty-list'), [new IdentifierTypeNode('int')]);
        expect($this->validator->validate([], $nonEmptyList, 'list', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate(['key' => 1], $nonEmptyList, 'list', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $mixedList = new GenericTypeNode(new IdentifierTypeNode('list'), [new IdentifierTypeNode('mixed')]);
        expect($this->validator->validate([1, 'string', false], $mixedList, 'list', $this->registry))->toBeNull();

        $complexList = new GenericTypeNode(new IdentifierTypeNode('list'), [
            new GenericTypeNode(new IdentifierTypeNode(Producer::class), [new IdentifierTypeNode(Dog::class)]),
        ]);
        expect($this->validator->validate([new Producer(new Dog())], $complexList, 'list', $this->registry))->toBeNull()
            ->and($this->validator->validate([new Producer(new Car())], $complexList, 'list', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        Config::set(['array_validation' => 'hybrid']);
        $largeList = [];
        for ($i = 0; $i < 150; $i++) {
            $largeList[] = new Producer(new Dog());
        }
        expect($this->validator->validate($largeList, $complexList, 'list', $this->registry))->toBeNull();

        $badLargeList = $largeList;
        $badLargeList[0] = new Producer(new Car());
        expect($this->validator->validate($badLargeList, $complexList, 'list', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        $largeIntList = range(1, 150);
        $posIntList = new GenericTypeNode(new IdentifierTypeNode('list'), [new IdentifierTypeNode('positive-int')]);
        expect($this->validator->validate($largeIntList, $posIntList, 'list', $this->registry))->toBeNull();

        $badLargeIntList = $largeIntList;
        $badLargeIntList[0] = -10;
        expect($this->validator->validate($badLargeIntList, $posIntList, 'list', $this->registry))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates generic arrays with Traversable bypass, shortcuts, and hybrid sampling', function () {
        $genericArray = new GenericTypeNode(new IdentifierTypeNode('array'), [
            new IdentifierTypeNode('string'),
            new IdentifierTypeNode('int'),
        ]);

        expect($this->validator->validate('not_arr', $genericArray, 'arr', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        expect($this->validator->validate(new ArrayIterator(['a' => 1]), $genericArray, 'arr', $this->registry))->toBeNull();

        expect($this->validator->validate([], $genericArray, 'arr', $this->registry))->toBeNull();

        $nonEmptyArray = new GenericTypeNode(new IdentifierTypeNode('non-empty-array'), [new IdentifierTypeNode('int')]);
        expect($this->validator->validate([], $nonEmptyArray, 'arr', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        $singleMixedArray = new GenericTypeNode(new IdentifierTypeNode('array'), [new IdentifierTypeNode('mixed')]);
        expect($this->validator->validate(['a' => 1, 'b' => 'text'], $singleMixedArray, 'arr', $this->registry))->toBeNull();

        $singleComplexArray = new GenericTypeNode(new IdentifierTypeNode('array'), [
            new GenericTypeNode(new IdentifierTypeNode(Producer::class), [new IdentifierTypeNode(Dog::class)]),
        ]);
        expect($this->validator->validate([new Producer(new Dog())], $singleComplexArray, 'arr', $this->registry))->toBeNull()
            ->and($this->validator->validate([new Producer(new Car())], $singleComplexArray, 'arr', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $unconstrainedMap = new GenericTypeNode(new IdentifierTypeNode('array'), [
            new IdentifierTypeNode('array-key'),
            new IdentifierTypeNode('mixed'),
        ]);
        expect($this->validator->validate(['a' => 'anything', 1 => 42], $unconstrainedMap, 'map', $this->registry))->toBeNull();

        $complexMap = new GenericTypeNode(new IdentifierTypeNode('array'), [
            new IdentifierTypeNode('string'),
            new GenericTypeNode(new IdentifierTypeNode(Producer::class), [new IdentifierTypeNode(Dog::class)]),
        ]);
        expect($this->validator->validate(['item' => new Producer(new Dog())], $complexMap, 'map', $this->registry))->toBeNull()
            ->and($this->validator->validate(['item' => new Producer(new Car())], $complexMap, 'map', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        $strictMap = new GenericTypeNode(new IdentifierTypeNode('array'), [
            new IdentifierTypeNode('int'),
            new IdentifierTypeNode('positive-int'),
        ]);
        expect($this->validator->validate(['string_key' => 10], $strictMap, 'map', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate([0 => -5], $strictMap, 'map', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;

        Config::set(['array_validation' => 'hybrid']);
        $largeSingleArray = [];
        for ($i = 0; $i < 150; $i++) {
            $largeSingleArray[] = new Producer(new Dog());
        }
        expect($this->validator->validate($largeSingleArray, $singleComplexArray, 'arr', $this->registry))->toBeNull();

        $badLargeSingle = $largeSingleArray;
        $badLargeSingle[0] = new Producer(new Car());
        expect($this->validator->validate($badLargeSingle, $singleComplexArray, 'arr', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        $largeMap = [];
        for ($i = 0; $i < 150; $i++) {
            $largeMap["k_{$i}"] = $i + 1;
        }
        $genericMap = new GenericTypeNode(new IdentifierTypeNode('array'), [
            new IdentifierTypeNode('string'),
            new IdentifierTypeNode('positive-int'),
        ]);
        expect($this->validator->validate($largeMap, $genericMap, 'map', $this->registry))->toBeNull();

        $badKeyMap = $largeMap;
        $badKeyMap = [0 => 10, ...$largeMap];
        expect($this->validator->validate($badKeyMap, $genericMap, 'map', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        $badValueMap = $largeMap;
        $badValueMap['k_0'] = -50;
        expect($this->validator->validate($badValueMap, $genericMap, 'map', $this->registry))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates object generics and handles non-object and wrong class inputs', function () {
        $invalidSyntax = new GenericTypeNode(new IdentifierTypeNode('invalid-class!'), [new IdentifierTypeNode('int')]);
        expect($this->validator->validate(new stdClass(), $invalidSyntax, 'obj', $this->registry))->toBeNull();

        $producerDog = new GenericTypeNode(new IdentifierTypeNode(Producer::class), [new IdentifierTypeNode(Dog::class)]);
        expect($this->validator->validate('not_an_object', $producerDog, 'p', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate(new stdClass(), $producerDog, 'p', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate(new Producer(new Dog()), $producerDog, 'p', $this->registry))->toBeNull()
        ;
    });
});
