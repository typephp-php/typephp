<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Validator\TypeValidatorRegistry;

beforeEach(function () {
    $this->registry = new TypeValidatorRegistry();
    $config = new ParserConfig(usedAttributes: []);
    $this->lexer = new Lexer($config);
    $constExprParser = new ConstExprParser($config);
    $this->typeParser = new TypeParser($config, $constExprParser);
});

function parseType(string $typeString, Lexer $lexer, TypeParser $typeParser): TypeNode
{
    $tokens = new TokenIterator($lexer->tokenize($typeString));

    return $typeParser->parse($tokens);
}

describe('IdentifierValidator', function () {
    test('validates basic primitives', function () {
        $intNode = parseType('int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(10, $intNode, 'arg'))->toBeNull();
        expect($this->registry->validate('hello', $intNode, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $stringNode = parseType('string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('hello', $stringNode, 'arg'))->toBeNull();
        expect($this->registry->validate(123, $stringNode, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates special string types', function () {
        $nonEmpty = parseType('non-empty-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('hello', $nonEmpty, 'arg'))->toBeNull();
        expect($this->registry->validate('', $nonEmpty, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $numericStr = parseType('numeric-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('123.45', $numericStr, 'arg'))->toBeNull();
        expect($this->registry->validate('not_a_number', $numericStr, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $lowerStr = parseType('lowercase-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('hello', $lowerStr, 'arg'))->toBeNull();
        expect($this->registry->validate('Hello', $lowerStr, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates int ranges and constraints', function () {
        $posInt = parseType('positive-int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(5, $posInt, 'arg'))->toBeNull();
        expect($this->registry->validate(-5, $posInt, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $negInt = parseType('negative-int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(-5, $negInt, 'arg'))->toBeNull();
        expect($this->registry->validate(5, $negInt, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates truthy and falsy', function () {
        $truthy = parseType('truthy', $this->lexer, $this->typeParser);
        expect($this->registry->validate('true', $truthy, 'arg'))->toBeNull();
        expect($this->registry->validate(0, $truthy, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $falsy = parseType('falsy', $this->lexer, $this->typeParser);
        expect($this->registry->validate(false, $falsy, 'arg'))->toBeNull();
        expect($this->registry->validate('hello', $falsy, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('edge case: validates class-string against interfaces, traits, and enums', function () {
        $classString = parseType('class-string', $this->lexer, $this->typeParser);

        expect($this->registry->validate(DateTimeInterface::class, $classString, 'arg'))->toBeNull();
        expect($this->registry->validate('NonExistentClass12345', $classString, 'arg'))->toBeInstanceOf(ErrorMessage::class);
        expect($this->registry->validate(123, $classString, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('edge case: validates callable-string', function () {
        $callableStr = parseType('callable-string', $this->lexer, $this->typeParser);

        expect($this->registry->validate('strlen', $callableStr, 'arg'))->toBeNull();
        expect($this->registry->validate('non_existent_function_abc_123', $callableStr, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('edge case: validates boundary conditions for positive, negative, and non-zero ints', function () {
        $posInt = parseType('positive-int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(0, $posInt, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $nonZero = parseType('non-zero-int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(0, $nonZero, 'arg'))->toBeInstanceOf(ErrorMessage::class);
        expect($this->registry->validate(-1, $nonZero, 'arg'))->toBeNull();
        expect($this->registry->validate(1, $nonZero, 'arg'))->toBeNull();
    });

    test('validates interface-string, trait-string, and enum-string', function () {
        $interfaceStr = parseType('interface-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate(DateTimeInterface::class, $interfaceStr, 'arg'))->toBeNull();
        expect($this->registry->validate(stdClass::class, $interfaceStr, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $enumStr = parseType('enum-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate(TypePHP\Tests\Fixtures\Types\StatusEnum::class, $enumStr, 'arg'))->toBeNull();
        expect($this->registry->validate(stdClass::class, $enumStr, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates float refinements (positive-float, negative-float, non-zero-float)', function () {
        $posFloat = parseType('positive-float', $this->lexer, $this->typeParser);
        expect($this->registry->validate(12.34, $posFloat, 'arg'))->toBeNull();
        expect($this->registry->validate(-12.34, $posFloat, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $nonZeroFloat = parseType('non-zero-float', $this->lexer, $this->typeParser);
        expect($this->registry->validate(0.0, $nonZeroFloat, 'arg'))->toBeInstanceOf(ErrorMessage::class);
        expect($this->registry->validate(1.5, $nonZeroFloat, 'arg'))->toBeNull();
    });

    test('validates truthy-string and never return type', function () {
        $truthyStr = parseType('truthy-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('hello', $truthyStr, 'arg'))->toBeNull();
        expect($this->registry->validate('0', $truthyStr, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $neverNode = parseType('never', $this->lexer, $this->typeParser);
        expect($this->registry->validate('returned_value', $neverNode, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates array-key pseudo-type (int|string)', function () {
        $arrayKeyNode = parseType('array-key', $this->lexer, $this->typeParser);

        expect($this->registry->validate(123, $arrayKeyNode, 'arg'))->toBeNull();
        expect($this->registry->validate('custom_key', $arrayKeyNode, 'arg'))->toBeNull();
        expect($this->registry->validate(true, $arrayKeyNode, 'arg'))->toBeInstanceOf(ErrorMessage::class);
        expect($this->registry->validate([], $arrayKeyNode, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates uppercase-string and non-empty-uppercase-string', function () {
        $uppercase = parseType('uppercase-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('USD', $uppercase, 'arg'))->toBeNull();
        expect($this->registry->validate('hello', $uppercase, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $nonEmptyUppercase = parseType('non-empty-uppercase-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('EUR', $nonEmptyUppercase, 'arg'))->toBeNull();
        expect($this->registry->validate('', $nonEmptyUppercase, 'arg'))->toBeInstanceOf(ErrorMessage::class);
        expect($this->registry->validate('eur', $nonEmptyUppercase, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });
});

describe('ConstValidator', function () {
    test('validates string and integer literals', function () {
        $strLiteral = parseType("'active'", $this->lexer, $this->typeParser);
        expect($this->registry->validate('active', $strLiteral, 'arg'))->toBeNull();
        expect($this->registry->validate('inactive', $strLiteral, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $intLiteral = parseType('42', $this->lexer, $this->typeParser);
        expect($this->registry->validate(42, $intLiteral, 'arg'))->toBeNull();
        expect($this->registry->validate(100, $intLiteral, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('edge case: strict type matching for constant literals', function () {
        $intLiteral = parseType('42', $this->lexer, $this->typeParser);
        expect($this->registry->validate('42', $intLiteral, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $trueLiteral = parseType('true', $this->lexer, $this->typeParser);
        expect($this->registry->validate(1, $trueLiteral, 'arg'))->toBeInstanceOf(ErrorMessage::class);
        expect($this->registry->validate(true, $trueLiteral, 'arg'))->toBeNull();

        $nullLiteral = parseType('null', $this->lexer, $this->typeParser);
        expect($this->registry->validate(null, $nullLiteral, 'arg'))->toBeNull();
        expect($this->registry->validate(false, $nullLiteral, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });
});

describe('ArrayShapeValidator', function () {
    test('checks required and optional keys', function () {
        $shape = parseType('array{id: int, name: string, email?: string}', $this->lexer, $this->typeParser);

        expect($this->registry->validate(['id' => 1, 'name' => 'Alice', 'email' => 'alice@test.com'], $shape, 'arg'))->toBeNull();
        expect($this->registry->validate(['id' => 1, 'name' => 'Alice'], $shape, 'arg'))->toBeNull();
        expect($this->registry->validate(['id' => 1], $shape, 'arg'))->toBeInstanceOf(ErrorMessage::class);
        expect($this->registry->validate(['id' => 'not_an_int', 'name' => 'Alice'], $shape, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('edge case: sealed shapes reject unexpected extra keys', function () {
        $sealedShape = parseType('array{id: int}', $this->lexer, $this->typeParser);

        expect($this->registry->validate(['id' => 1], $sealedShape, 'arg'))->toBeNull();
        expect($this->registry->validate(['id' => 1, 'extra' => 'value'], $sealedShape, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('edge case: unsealed shapes allow extra keys matching unsealed type', function () {
        $unsealedShape = parseType('array{id: int, ...<string, string>}', $this->lexer, $this->typeParser);

        expect($this->registry->validate(['id' => 1, 'role' => 'admin'], $unsealedShape, 'arg'))->toBeNull();
        expect($this->registry->validate(['id' => 1, 'role' => 999], $unsealedShape, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('edge case: nested array shapes', function () {
        $nestedShape = parseType('array{user: array{id: int, name: string}}', $this->lexer, $this->typeParser);

        expect($this->registry->validate(['user' => ['id' => 1, 'name' => 'Alice']], $nestedShape, 'arg'))->toBeNull();
        expect($this->registry->validate(['user' => ['id' => 'invalid']], $nestedShape, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });
});

describe('GenericValidator', function () {
    test('checks int range bounds int<1, 10>', function () {
        $rangeNode = parseType('int<1, 10>', $this->lexer, $this->typeParser);

        expect($this->registry->validate(5, $rangeNode, 'arg'))->toBeNull();
        expect($this->registry->validate(0, $rangeNode, 'arg'))->toBeInstanceOf(ErrorMessage::class);
        expect($this->registry->validate(15, $rangeNode, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('checks list<T> and array<K, V>', function () {
        $listNode = parseType('list<string>', $this->lexer, $this->typeParser);

        expect($this->registry->validate(['a', 'b', 'c'], $listNode, 'arg'))->toBeNull();
        expect($this->registry->validate(['a' => 'b'], $listNode, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $assocArrayNode = parseType('array<string, int>', $this->lexer, $this->typeParser);

        expect($this->registry->validate(['age' => 30, 'score' => 100], $assocArrayNode, 'arg'))->toBeNull();
        expect($this->registry->validate(['age' => 'thirty'], $assocArrayNode, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('edge case: int range bounds with min, max, and wildcard *', function () {
        $minRange = parseType('int<min, 100>', $this->lexer, $this->typeParser);
        expect($this->registry->validate(-99999, $minRange, 'arg'))->toBeNull();
        expect($this->registry->validate(100, $minRange, 'arg'))->toBeNull();
        expect($this->registry->validate(101, $minRange, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $maxRange = parseType('int<0, max>', $this->lexer, $this->typeParser);
        expect($this->registry->validate(0, $maxRange, 'arg'))->toBeNull();
        expect($this->registry->validate(999999, $maxRange, 'arg'))->toBeNull();
        expect($this->registry->validate(-1, $maxRange, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('edge case: non-empty-list rejects empty array', function () {
        $nonEmptyList = parseType('non-empty-list<int>', $this->lexer, $this->typeParser);

        expect($this->registry->validate([10, 20], $nonEmptyList, 'arg'))->toBeNull();
        expect($this->registry->validate([], $nonEmptyList, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('edge case: nested generic arrays array<string, list<int>>', function () {
        $nestedGeneric = parseType('array<string, list<int>>', $this->lexer, $this->typeParser);

        expect($this->registry->validate(['scores' => [10, 20, 30]], $nestedGeneric, 'arg'))->toBeNull();
        expect($this->registry->validate(['scores' => ['a' => 10]], $nestedGeneric, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });
});

describe('NullableValidator', function () {
    test('handles null and wrapped types', function () {
        $nullableInt = parseType('?int', $this->lexer, $this->typeParser);

        expect($this->registry->validate(null, $nullableInt, 'arg'))->toBeNull();
        expect($this->registry->validate(100, $nullableInt, 'arg'))->toBeNull();
        expect($this->registry->validate('string', $nullableInt, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('edge case: nullable array shape ?array{id: int}', function () {
        $nullableShape = parseType('?array{id: int}', $this->lexer, $this->typeParser);

        expect($this->registry->validate(null, $nullableShape, 'arg'))->toBeNull();
        expect($this->registry->validate(['id' => 10], $nullableShape, 'arg'))->toBeNull();
        expect($this->registry->validate(['id' => 'invalid'], $nullableShape, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });
});

describe('UnionValidator', function () {
    test('accepts valid choices and rejects invalid choices', function () {
        $union = parseType('int|string', $this->lexer, $this->typeParser);

        expect($this->registry->validate(10, $union, 'arg'))->toBeNull();
        expect($this->registry->validate('hello', $union, 'arg'))->toBeNull();
        expect($this->registry->validate(true, $union, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('edge case: literal string union active|pending|closed', function () {
        $enumUnion = parseType("'active'|'pending'|'closed'", $this->lexer, $this->typeParser);

        expect($this->registry->validate('active', $enumUnion, 'arg'))->toBeNull();
        expect($this->registry->validate('pending', $enumUnion, 'arg'))->toBeNull();
        expect($this->registry->validate('archived', $enumUnion, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });
});

describe('IntersectionValidator', function () {
    test('requires value to satisfy all types', function () {
        $intersection = parseType('Countable&ArrayAccess', $this->lexer, $this->typeParser);

        $validObj = new ArrayObject();
        $invalidObj = new stdClass();

        expect($this->registry->validate($validObj, $intersection, 'arg'))->toBeNull();
        expect($this->registry->validate($invalidObj, $intersection, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('edge case: object failing one interface in intersection', function () {
        $intersection = parseType('Countable&ArrayAccess', $this->lexer, $this->typeParser);

        $countableOnly = new class () implements Countable {
            public function count(): int
            {
                return 0;
            }
        };

        expect($this->registry->validate($countableOnly, $intersection, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });
});

describe('ArrayValidator', function () {
    test('checks array element types', function () {
        $intArray = parseType('int[]', $this->lexer, $this->typeParser);

        expect($this->registry->validate([1, 2, 3], $intArray, 'arg'))->toBeNull();
        expect($this->registry->validate([1, 'invalid_string', 3], $intArray, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('edge case: empty array is valid for typed array', function () {
        $intArray = parseType('int[]', $this->lexer, $this->typeParser);

        expect($this->registry->validate([], $intArray, 'arg'))->toBeNull();
    });
});
