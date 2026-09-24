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
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\Producer;
use TypePHP\Tests\Fixtures\Types\BitmaskFlags;
use TypePHP\Tests\Fixtures\Types\DatabaseDriverMap;

class GenericConstFlagsFixture
{
    public const FLAG_A = 1;
    public const FLAG_B = 2;
    public const FLAGS_ARRAY = [1, 2, 4];
}

describe('GenericValidator Unit Tests', function () {
    beforeEach(function () {
        $this->registry = new TypeValidatorRegistry();
        $this->validator = new GenericValidator();
    });

    afterEach(function () {
        Config::reset();
    });

    test('validates integer ranges with min and max bounds', function () {
        $range = new GenericTypeNode(new IdentifierTypeNode('int'), [
            new ConstExprIntegerNode('1'),
            new ConstExprIntegerNode('10'),
        ]);

        expect($this->validator->validate(5, $range, 'val', $this->registry))->toBeNull()
            ->and($this->validator->validate(0, $range, 'val', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate(15, $range, 'val', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate('not_int', $range, 'val', $this->registry))->toBeInstanceOf(ErrorMessage::class)
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

    test('validates class-string with union and intersection bounds', function () {
        $bareClassString = new GenericTypeNode(new IdentifierTypeNode('class-string'), []);
        expect($this->validator->validate(stdClass::class, $bareClassString, 'cls', $this->registry))->toBeNull();

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

    test('validates key-of on nested value-of and array shapes with multiple key styles', function () {
        $nestedShape = new GenericTypeNode(new IdentifierTypeNode('key-of'), [
            new GenericTypeNode(new IdentifierTypeNode('value-of'), [
                ArrayShapeNode::createSealed([
                    new ArrayShapeItemNode(new IdentifierTypeNode('config'), false, ArrayShapeNode::createSealed([
                        new ArrayShapeItemNode(new ConstExprStringNode('host', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('string')),
                        new ArrayShapeItemNode(new ConstExprIntegerNode('0'), false, new IdentifierTypeNode('int')),
                    ])),
                ]),
            ]),
        ]);

        expect($this->validator->validate('host', $nestedShape, 'key', $this->registry))->toBeNull();
        expect($this->validator->validate(0, $nestedShape, 'key', $this->registry))->toBeNull();
        expect($this->validator->validate('missing', $nestedShape, 'key', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        $shapeWithAutoIndex = new GenericTypeNode(new IdentifierTypeNode('key-of'), [
            ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(null, false, new IdentifierTypeNode('string')),
                new ArrayShapeItemNode(new ConstFetchNode('self', 'KEY'), false, new IdentifierTypeNode('string')),
            ]),
        ]);
        expect($this->validator->validate(0, $shapeWithAutoIndex, 'key', $this->registry))->toBeNull();
        expect($this->validator->validate('self::KEY', $shapeWithAutoIndex, 'key', $this->registry))->toBeNull();
    });

    test('validates value-of on constant arrays and array shapes', function () {
        $constValueOf = new GenericTypeNode(new IdentifierTypeNode('value-of'), [
            new ConstTypeNode(new ConstFetchNode(DatabaseDriverMap::class, 'PUBLIC_MAP')),
        ]);
        expect($this->validator->validate(1, $constValueOf, 'val', $this->registry))->toBeNull();
        expect($this->validator->validate(99, $constValueOf, 'val', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        $shapeValueOf = new GenericTypeNode(new IdentifierTypeNode('value-of'), [
            ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(new IdentifierTypeNode('id'), false, new IdentifierTypeNode('positive-int')),
                new ArrayShapeItemNode(new IdentifierTypeNode('role'), false, new IdentifierTypeNode('string')),
            ]),
        ]);
        expect($this->validator->validate(10, $shapeValueOf, 'val', $this->registry))->toBeNull();
        expect($this->validator->validate('admin', $shapeValueOf, 'val', $this->registry))->toBeNull();
        expect($this->validator->validate(false, $shapeValueOf, 'val', $this->registry))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates int-mask and int-mask-of with constant fetches and array of flags', function () {
        $intMaskConst = new GenericTypeNode(new IdentifierTypeNode('int-mask'), [
            new ConstTypeNode(new ConstFetchNode(BitmaskFlags::class, 'FLAG_READ')),
            new ConstTypeNode(new ConstFetchNode(BitmaskFlags::class, 'FLAG_WRITE')),
        ]);

        expect($this->validator->validate(1, $intMaskConst, 'mask', $this->registry))->toBeNull();
        expect($this->validator->validate(3, $intMaskConst, 'mask', $this->registry))->toBeNull();
        expect($this->validator->validate(8, $intMaskConst, 'mask', $this->registry))->toBeInstanceOf(ErrorMessage::class);
        expect($this->validator->validate('not_int', $intMaskConst, 'mask', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        $intMaskOfArray = new GenericTypeNode(new IdentifierTypeNode('int-mask-of'), [
            new ConstTypeNode(new ConstFetchNode(GenericConstFlagsFixture::class, 'FLAGS_ARRAY')),
        ]);
        expect($this->validator->validate(7, $intMaskOfArray, 'mask', $this->registry))->toBeNull();
        expect($this->validator->validate(16, $intMaskOfArray, 'mask', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        $intMaskOfSingle = new GenericTypeNode(new IdentifierTypeNode('int-mask-of'), [
            new ConstTypeNode(new ConstFetchNode(GenericConstFlagsFixture::class, 'FLAG_A')),
        ]);
        expect($this->validator->validate(1, $intMaskOfSingle, 'mask', $this->registry))->toBeNull();
        expect($this->validator->validate(4, $intMaskOfSingle, 'mask', $this->registry))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates lists and non-empty-lists with exhaustive and hybrid sampling', function () {
        $nonEmptyList = new GenericTypeNode(new IdentifierTypeNode('non-empty-list'), [new IdentifierTypeNode('int')]);
        expect($this->validator->validate([], $nonEmptyList, 'list', $this->registry))->toBeInstanceOf(ErrorMessage::class);
        expect($this->validator->validate(['key' => 1], $nonEmptyList, 'list', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        Config::set(['array_validation' => 'hybrid']);
        $largeList = range(1, 150);
        $posIntList = new GenericTypeNode(new IdentifierTypeNode('list'), [new IdentifierTypeNode('positive-int')]);
        expect($this->validator->validate($largeList, $posIntList, 'list', $this->registry))->toBeNull();

        $badLargeList = $largeList;
        $badLargeList[0] = -10;
        expect($this->validator->validate($badLargeList, $posIntList, 'list', $this->registry))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates generic arrays and handles unconstrained key and value shortcuts', function () {
        $nonEmptyArray = new GenericTypeNode(new IdentifierTypeNode('non-empty-array'), [new IdentifierTypeNode('int')]);
        expect($this->validator->validate([], $nonEmptyArray, 'arr', $this->registry))->toBeInstanceOf(ErrorMessage::class);
        expect($this->validator->validate('not_arr', $nonEmptyArray, 'arr', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        $unconstrainedMap = new GenericTypeNode(new IdentifierTypeNode('array'), [
            new IdentifierTypeNode('array-key'),
            new IdentifierTypeNode('mixed'),
        ]);
        expect($this->validator->validate(['a' => 'anything', 1 => 42], $unconstrainedMap, 'map', $this->registry))->toBeNull();

        $singleGenericArray = new GenericTypeNode(new IdentifierTypeNode('array'), [new IdentifierTypeNode('positive-int')]);
        expect($this->validator->validate([1, 2, 3], $singleGenericArray, 'arr', $this->registry))->toBeNull();
        expect($this->validator->validate([1, -2], $singleGenericArray, 'arr', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        Config::set(['array_validation' => 'hybrid']);
        $largeMap = [];
        for ($i = 0; $i < 150; $i++) {
            $largeMap["k_{$i}"] = $i + 1;
        }
        $genericMap = new GenericTypeNode(new IdentifierTypeNode('array'), [
            new IdentifierTypeNode('string'),
            new IdentifierTypeNode('positive-int'),
        ]);
        expect($this->validator->validate($largeMap, $genericMap, 'map', $this->registry))->toBeNull();

        $badLargeMap = $largeMap;
        $badLargeMap['k_0'] = -50;
        expect($this->validator->validate($badLargeMap, $genericMap, 'map', $this->registry))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates object generics and handles invalid syntax gracefully', function () {
        $invalidSyntax = new GenericTypeNode(new IdentifierTypeNode('invalid-class!'), [new IdentifierTypeNode('int')]);
        expect($this->validator->validate(new stdClass(), $invalidSyntax, 'obj', $this->registry))->toBeNull();

        $producerDog = new GenericTypeNode(new IdentifierTypeNode(Producer::class), [new IdentifierTypeNode(Dog::class)]);
        expect($this->validator->validate('not_an_object', $producerDog, 'p', $this->registry))->toBeInstanceOf(ErrorMessage::class);
        expect($this->validator->validate(new stdClass(), $producerDog, 'p', $this->registry))->toBeInstanceOf(ErrorMessage::class);
        expect($this->validator->validate(new Producer(new Dog()), $producerDog, 'p', $this->registry))->toBeNull();
    });
});