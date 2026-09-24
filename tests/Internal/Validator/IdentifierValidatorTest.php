<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Validator\IdentifierValidator;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Enums\StatusEnum;
use TypePHP\Tests\Fixtures\Oop\ExecutorTrait;
use TypePHP\Tests\Fixtures\Enums\Suit;

describe('IdentifierValidator Unit Tests', function () {
    beforeEach(function () {
        $this->registry = new TypeValidatorRegistry();
    });

    test('validates primitives and alias keywords', function () {
        $intNode = new IdentifierTypeNode('int');
        $integerNode = new IdentifierTypeNode('integer');
        expect($this->registry->validate(10, $intNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(20, $integerNode, 'arg'))->toBeNull()
            ->and($this->registry->validate('not_int', $intNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $stringNode = new IdentifierTypeNode('string');
        expect($this->registry->validate('hello', $stringNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(123, $stringNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $boolNode = new IdentifierTypeNode('bool');
        $booleanNode = new IdentifierTypeNode('boolean');
        expect($this->registry->validate(true, $boolNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(false, $booleanNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(1, $boolNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $floatNode = new IdentifierTypeNode('float');
        $doubleNode = new IdentifierTypeNode('double');
        expect($this->registry->validate(1.5, $floatNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(2.5, $doubleNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(10, $floatNode, 'arg'))->toBeNull()
            ->and($this->registry->validate('str', $floatNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $trueNode = new IdentifierTypeNode('true');
        expect($this->registry->validate(true, $trueNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(false, $trueNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $falseNode = new IdentifierTypeNode('false');
        expect($this->registry->validate(false, $falseNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(true, $falseNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nullNode = new IdentifierTypeNode('null');
        expect($this->registry->validate(null, $nullNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(0, $nullNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates array, list, iterable, and object aliases', function () {
        $arrayNode = new IdentifierTypeNode('array');
        expect($this->registry->validate(['a' => 1], $arrayNode, 'arg'))->toBeNull()
            ->and($this->registry->validate('not_arr', $arrayNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $listNode = new IdentifierTypeNode('list');
        expect($this->registry->validate([], $listNode, 'arg'))->toBeNull()
            ->and($this->registry->validate([1, 2, 3], $listNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(['a' => 1], $listNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $iterNode = new IdentifierTypeNode('iterable');
        expect($this->registry->validate([1, 2], $iterNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(new ArrayIterator([1, 2]), $iterNode, 'arg'))->toBeNull()
            ->and($this->registry->validate('not_iterable', $iterNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $objectAliases = ['object', 'self', 'static', 'parent', '$this'];
        foreach ($objectAliases as $alias) {
            $node = new IdentifierTypeNode($alias);
            expect($this->registry->validate(new stdClass(), $node, 'arg'))->toBeNull()
                ->and($this->registry->validate('not_obj', $node, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ;
        }
    });

    test('validates integer refinements including non-negative and unsigned', function () {
        $posInt = new IdentifierTypeNode('positive-int');
        expect($this->registry->validate(5, $posInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(0, $posInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate(-5, $posInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $negInt = new IdentifierTypeNode('negative-int');
        expect($this->registry->validate(-5, $negInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(0, $negInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonPosInt = new IdentifierTypeNode('non-positive-int');
        expect($this->registry->validate(0, $nonPosInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(-5, $nonPosInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(5, $nonPosInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonNegInt = new IdentifierTypeNode('non-negative-int');
        expect($this->registry->validate(0, $nonNegInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(10, $nonNegInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(-1, $nonNegInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonZeroInt = new IdentifierTypeNode('non-zero-int');
        expect($this->registry->validate(1, $nonZeroInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(-1, $nonZeroInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(0, $nonZeroInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $unsignedInt = new IdentifierTypeNode('unsigned-int');
        expect($this->registry->validate(0, $unsignedInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(10, $unsignedInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(-1, $unsignedInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates float refinements', function () {
        $posFloat = new IdentifierTypeNode('positive-float');
        expect($this->registry->validate(1.5, $posFloat, 'arg'))->toBeNull()
            ->and($this->registry->validate(-1.5, $posFloat, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $negFloat = new IdentifierTypeNode('negative-float');
        expect($this->registry->validate(-2.5, $negFloat, 'arg'))->toBeNull()
            ->and($this->registry->validate(2.5, $negFloat, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonPosFloat = new IdentifierTypeNode('non-positive-float');
        expect($this->registry->validate(-1.0, $nonPosFloat, 'arg'))->toBeNull()
            ->and($this->registry->validate(0.0, $nonPosFloat, 'arg'))->toBeNull()
            ->and($this->registry->validate(1.0, $nonPosFloat, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonNegFloat = new IdentifierTypeNode('non-negative-float');
        expect($this->registry->validate(1.0, $nonNegFloat, 'arg'))->toBeNull()
            ->and($this->registry->validate(0.0, $nonNegFloat, 'arg'))->toBeNull()
            ->and($this->registry->validate(-1.0, $nonNegFloat, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonZeroFloat = new IdentifierTypeNode('non-zero-float');
        expect($this->registry->validate(0.5, $nonZeroFloat, 'arg'))->toBeNull()
            ->and($this->registry->validate(-0.5, $nonZeroFloat, 'arg'))->toBeNull()
            ->and($this->registry->validate(0.0, $nonZeroFloat, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates pseudo-types and control flow types', function () {
        $voidNode = new IdentifierTypeNode('void');
        expect($this->registry->validate(null, $voidNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(1, $voidNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $neverVariants = ['never', 'never-return', 'never-returns', 'no-return'];
        foreach ($neverVariants as $variant) {
            $node = new IdentifierTypeNode($variant);
            expect($this->registry->validate('returned', $node, 'arg'))->toBeInstanceOf(ErrorMessage::class);
        }

        $mixedNode = new IdentifierTypeNode('mixed');
        expect($this->registry->validate('any', $mixedNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(null, $mixedNode, 'arg'))->toBeNull()
        ;

        $scalarNode = new IdentifierTypeNode('scalar');
        expect($this->registry->validate('text', $scalarNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(123, $scalarNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(new stdClass(), $scalarNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $truthyNode = new IdentifierTypeNode('truthy');
        expect($this->registry->validate('yes', $truthyNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(0, $truthyNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $falsyNode = new IdentifierTypeNode('falsy');
        $falseyNode = new IdentifierTypeNode('falsey');
        expect($this->registry->validate(0, $falsyNode, 'arg'))->toBeNull()
            ->and($this->registry->validate('', $falseyNode, 'arg'))->toBeNull()
            ->and($this->registry->validate('yes', $falsyNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $numericNode = new IdentifierTypeNode('numeric');
        $numberNode = new IdentifierTypeNode('number');
        expect($this->registry->validate(10, $numericNode, 'arg'))->toBeNull()
            ->and($this->registry->validate('12.5', $numberNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(3.14, $numberNode, 'arg'))->toBeNull()
            ->and($this->registry->validate('abc', $numericNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates standard, open, and closed resources', function () {
        $res = fopen('php://memory', 'r+');
        $resourceNode = new IdentifierTypeNode('resource');
        $openResourceNode = new IdentifierTypeNode('open-resource');
        $closedResourceNode = new IdentifierTypeNode('closed-resource');

        expect($this->registry->validate($res, $resourceNode, 'arg'))->toBeNull()
            ->and($this->registry->validate($res, $openResourceNode, 'arg'))->toBeNull()
            ->and($this->registry->validate('not_resource', $resourceNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate('not_resource', $openResourceNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate($res, $closedResourceNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        fclose($res);

        expect($this->registry->validate($res, $closedResourceNode, 'arg'))->toBeNull()
            ->and($this->registry->validate($res, $openResourceNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate('not_resource', $closedResourceNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates string class subtypes, trait-string, and callables', function () {
        $classString = new IdentifierTypeNode('class-string');
        expect($this->registry->validate(stdClass::class, $classString, 'arg'))->toBeNull()
            ->and($this->registry->validate('Invalid Class', $classString, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $ifaceString = new IdentifierTypeNode('interface-string');
        expect($this->registry->validate(DateTimeInterface::class, $ifaceString, 'arg'))->toBeNull()
            ->and($this->registry->validate(stdClass::class, $ifaceString, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $traitString = new IdentifierTypeNode('trait-string');
        expect($this->registry->validate(ExecutorTrait::class, $traitString, 'arg'))->toBeNull()
            ->and($this->registry->validate(stdClass::class, $traitString, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate(12345, $traitString, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $enumString = new IdentifierTypeNode('enum-string');
        expect($this->registry->validate(Suit::class, $enumString, 'arg'))->toBeNull()
            ->and($this->registry->validate(stdClass::class, $enumString, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $callableString = new IdentifierTypeNode('callable-string');
        expect($this->registry->validate('strlen', $callableString, 'arg'))->toBeNull()
            ->and($this->registry->validate('non_existent_func_xyz', $callableString, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $pureCallable = new IdentifierTypeNode('pure-callable');
        $callableNode = new IdentifierTypeNode('callable');
        expect($this->registry->validate('strlen', $pureCallable, 'arg'))->toBeNull()
            ->and($this->registry->validate(fn() => 1, $callableNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(12345, $pureCallable, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates string and array refinements', function () {
        $truthyString = new IdentifierTypeNode('truthy-string');
        $nonFalsyString = new IdentifierTypeNode('non-falsy-string');
        expect($this->registry->validate('yes', $truthyString, 'arg'))->toBeNull()
            ->and($this->registry->validate('valid', $nonFalsyString, 'arg'))->toBeNull()
            ->and($this->registry->validate('0', $truthyString, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate('', $nonFalsyString, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonEmptyArray = new IdentifierTypeNode('non-empty-array');
        expect($this->registry->validate(['a' => 1], $nonEmptyArray, 'arg'))->toBeNull()
            ->and($this->registry->validate([], $nonEmptyArray, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonEmptyList = new IdentifierTypeNode('non-empty-list');
        expect($this->registry->validate([1, 2], $nonEmptyList, 'arg'))->toBeNull()
            ->and($this->registry->validate([], $nonEmptyList, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate(['key' => 1], $nonEmptyList, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $lowerStr = new IdentifierTypeNode('lowercase-string');
        expect($this->registry->validate('abc', $lowerStr, 'arg'))->toBeNull()
            ->and($this->registry->validate('Abc', $lowerStr, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonEmptyLower = new IdentifierTypeNode('non-empty-lowercase-string');
        expect($this->registry->validate('abc', $nonEmptyLower, 'arg'))->toBeNull()
            ->and($this->registry->validate('', $nonEmptyLower, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate('Abc', $nonEmptyLower, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $upperStr = new IdentifierTypeNode('uppercase-string');
        expect($this->registry->validate('ABC', $upperStr, 'arg'))->toBeNull()
            ->and($this->registry->validate('Abc', $upperStr, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonEmptyUpper = new IdentifierTypeNode('non-empty-uppercase-string');
        expect($this->registry->validate('ABC', $nonEmptyUpper, 'arg'))->toBeNull()
            ->and($this->registry->validate('', $nonEmptyUpper, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate('Abc', $nonEmptyUpper, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $arrayKey = new IdentifierTypeNode('array-key');
        expect($this->registry->validate(10, $arrayKey, 'arg'))->toBeNull()
            ->and($this->registry->validate('key', $arrayKey, 'arg'))->toBeNull()
            ->and($this->registry->validate(false, $arrayKey, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $literalString = new IdentifierTypeNode('literal-string');
        expect($this->registry->validate('string_val', $literalString, 'arg'))->toBeNull()
            ->and($this->registry->validate(10, $literalString, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates objects and handles class validation fallback', function () {
        $dogNode = new IdentifierTypeNode(Dog::class);

        expect($this->registry->validate(new Dog(), $dogNode, 'arg'))->toBeNull();

        $errClass = $this->registry->validate(new Car(), $dogNode, 'arg');
        expect($errClass)->toBeInstanceOf(ErrorMessage::class)
            ->and($errClass->getMessage())->toContain(Dog::class)
        ;

        $errNonObj = $this->registry->validate('string_val', $dogNode, 'arg');
        expect($errNonObj)->toBeInstanceOf(ErrorMessage::class);

        $invalidSyntaxNode = new IdentifierTypeNode('invalid-class-syntax!');
        expect($this->registry->validate('anything', $invalidSyntaxNode, 'arg'))->toBeNull();
    });

    test('formats error messages respecting sensitivity redaction', function () {
        $intNode = new IdentifierTypeNode('int');
        $err = $this->registry->validate('secret_val', $intNode, 'secretField', isSensitive: true);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain('string given')
            ->and($err->getMessage())->not()->toContain('secret_val')
        ;
    });

    test('direct validator instance validation handles valid and invalid classes directly', function () {
        $validator = new IdentifierValidator();
        $dogNode = new IdentifierTypeNode(Dog::class);

        expect($validator->validate(new Dog(), $dogNode, 'arg', $this->registry))->toBeNull();
        expect($validator->validate(new Car(), $dogNode, 'arg', $this->registry))->toBeInstanceOf(ErrorMessage::class);
    });
});
