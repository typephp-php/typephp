<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Enums\Suit;
use TypePHP\Tests\Fixtures\Enums\TransactionStatus;
use TypePHP\Tests\Fixtures\Generics\Producer;
use TypePHP\Tests\Fixtures\Readonly\UninitializedReadonlyContainer;
use TypePHP\Tests\Fixtures\Types\ArrayAccessOnly;
use TypePHP\Tests\Fixtures\Types\BitmaskFlags;
use TypePHP\Tests\Fixtures\Types\CountableArrayAccess;
use TypePHP\Tests\Fixtures\Types\CountableOnly;
use TypePHP\Tests\Fixtures\Types\DatabaseDriverMap;
use TypePHP\Tests\Fixtures\Types\StatusEnum;
use TypePHP\Tests\Fixtures\Types\UserObjectShape;
use TypePHP\Tests\Fixtures\Types\WildcardConstantFixture;

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
    test('validates case-insensitive refinement types (Positive-Int, Non-Empty-String)', function () {
        $posInt = parseType('Positive-Int', $this->lexer, $this->typeParser);

        expect($this->registry->validate(5, $posInt, 'arg'))->toBeNull();
        expect($this->registry->validate(-5, $posInt, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates basic primitives', function () {
        $intNode = parseType('int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(10, $intNode, 'arg'))->toBeNull()
            ->and($this->registry->validate('hello', $intNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $stringNode = parseType('string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('hello', $stringNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(123, $stringNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $boolNode = parseType('bool', $this->lexer, $this->typeParser);
        expect($this->registry->validate(true, $boolNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(false, $boolNode, 'arg'))->toBeNull()
            ->and($this->registry->validate('true', $boolNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $floatNode = parseType('float', $this->lexer, $this->typeParser);
        expect($this->registry->validate(12.34, $floatNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(10, $floatNode, 'arg'))->toBeNull() // Int coerced to float
            ->and($this->registry->validate('not_float', $floatNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates int refinements (positive, negative, non-positive, non-negative, non-zero, unsigned)', function () {
        $posInt = parseType('positive-int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(5, $posInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(0, $posInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate(-5, $posInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $negInt = parseType('negative-int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(-5, $negInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(0, $negInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate(5, $negInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonPosInt = parseType('non-positive-int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(0, $nonPosInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(-5, $nonPosInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(5, $nonPosInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonNegInt = parseType('non-negative-int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(0, $nonNegInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(5, $nonNegInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(-5, $nonNegInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonZeroInt = parseType('non-zero-int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(1, $nonZeroInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(-1, $nonZeroInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(0, $nonZeroInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $unsignedInt = parseType('unsigned-int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(0, $unsignedInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(10, $unsignedInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(-1, $unsignedInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates float refinements (positive, negative, non-positive, non-negative, non-zero)', function () {
        $posFloat = parseType('positive-float', $this->lexer, $this->typeParser);
        expect($this->registry->validate(12.34, $posFloat, 'arg'))->toBeNull()
            ->and($this->registry->validate(-12.34, $posFloat, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $negFloat = parseType('negative-float', $this->lexer, $this->typeParser);
        expect($this->registry->validate(-5.5, $negFloat, 'arg'))->toBeNull()
            ->and($this->registry->validate(5.5, $negFloat, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonPosFloat = parseType('non-positive-float', $this->lexer, $this->typeParser);
        expect($this->registry->validate(0.0, $nonPosFloat, 'arg'))->toBeNull()
            ->and($this->registry->validate(-5.5, $nonPosFloat, 'arg'))->toBeNull()
            ->and($this->registry->validate(5.5, $nonPosFloat, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonNegFloat = parseType('non-negative-float', $this->lexer, $this->typeParser);
        expect($this->registry->validate(0.0, $nonNegFloat, 'arg'))->toBeNull()
            ->and($this->registry->validate(5.5, $nonNegFloat, 'arg'))->toBeNull()
            ->and($this->registry->validate(-5.5, $nonNegFloat, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonZeroFloat = parseType('non-zero-float', $this->lexer, $this->typeParser);
        expect($this->registry->validate(1.5, $nonZeroFloat, 'arg'))->toBeNull()
            ->and($this->registry->validate(0.0, $nonZeroFloat, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates string refinements', function () {
        $nonEmpty = parseType('non-empty-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('hello', $nonEmpty, 'arg'))->toBeNull()
            ->and($this->registry->validate('', $nonEmpty, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $numericStr = parseType('numeric-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('123.45', $numericStr, 'arg'))->toBeNull()
            ->and($this->registry->validate('not_a_number', $numericStr, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $lowerStr = parseType('lowercase-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('hello', $lowerStr, 'arg'))->toBeNull()
            ->and($this->registry->validate('Hello', $lowerStr, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonEmptyLower = parseType('non-empty-lowercase-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('hello', $nonEmptyLower, 'arg'))->toBeNull()
            ->and($this->registry->validate('', $nonEmptyLower, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate('Hello', $nonEmptyLower, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $upperStr = parseType('uppercase-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('USD', $upperStr, 'arg'))->toBeNull()
            ->and($this->registry->validate('', $upperStr, 'arg'))->toBeNull()
            ->and($this->registry->validate('Usd', $upperStr, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nonEmptyUpper = parseType('non-empty-uppercase-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('EUR', $nonEmptyUpper, 'arg'))->toBeNull()
            ->and($this->registry->validate('', $nonEmptyUpper, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate('eur', $nonEmptyUpper, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $arrayKey = parseType('array-key', $this->lexer, $this->typeParser);
        expect($this->registry->validate(123, $arrayKey, 'arg'))->toBeNull()
            ->and($this->registry->validate('key_1', $arrayKey, 'arg'))->toBeNull()
            ->and($this->registry->validate(true, $arrayKey, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $literalStr = parseType('literal-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('any_string', $literalStr, 'arg'))->toBeNull()
            ->and($this->registry->validate(123, $literalStr, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $truthyStr = parseType('truthy-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('hello', $truthyStr, 'arg'))->toBeNull()
            ->and($this->registry->validate('0', $truthyStr, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate('', $truthyStr, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates pseudo-types (mixed, scalar, void, never, truthy, falsy, numeric, resources)', function () {
        $mixed = parseType('mixed', $this->lexer, $this->typeParser);
        expect($this->registry->validate(123, $mixed, 'arg'))->toBeNull()
            ->and($this->registry->validate(null, $mixed, 'arg'))->toBeNull()
            ->and($this->registry->validate(new stdClass(), $mixed, 'arg'))->toBeNull()
        ;

        $scalar = parseType('scalar', $this->lexer, $this->typeParser);
        expect($this->registry->validate(123, $scalar, 'arg'))->toBeNull()
            ->and($this->registry->validate('hello', $scalar, 'arg'))->toBeNull()
            ->and($this->registry->validate(true, $scalar, 'arg'))->toBeNull()
            ->and($this->registry->validate([], $scalar, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $void = parseType('void', $this->lexer, $this->typeParser);
        expect($this->registry->validate(null, $void, 'arg'))->toBeNull()
            ->and($this->registry->validate(123, $void, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $never = parseType('never', $this->lexer, $this->typeParser);
        expect($this->registry->validate('returned', $never, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $truthy = parseType('truthy', $this->lexer, $this->typeParser);
        expect($this->registry->validate('true', $truthy, 'arg'))->toBeNull()
            ->and($this->registry->validate(0, $truthy, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $falsy = parseType('falsy', $this->lexer, $this->typeParser);
        expect($this->registry->validate(false, $falsy, 'arg'))->toBeNull()
            ->and($this->registry->validate(0, $falsy, 'arg'))->toBeNull()
            ->and($this->registry->validate('hello', $falsy, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $numeric = parseType('numeric', $this->lexer, $this->typeParser);
        expect($this->registry->validate(10, $numeric, 'arg'))->toBeNull()
            ->and($this->registry->validate('10.5', $numeric, 'arg'))->toBeNull()
            ->and($this->registry->validate('not_numeric', $numeric, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $res = fopen('php://memory', 'r+');
        $openResource = parseType('open-resource', $this->lexer, $this->typeParser);
        expect($this->registry->validate($res, $openResource, 'arg'))->toBeNull();
        fclose($res);

        $closedResource = parseType('closed-resource', $this->lexer, $this->typeParser);
        expect($this->registry->validate($res, $closedResource, 'arg'))->toBeNull();
    });

    test('validates string class type identifiers (class-string, interface-string, trait-string, enum-string, callable-string)', function () {
        $classString = parseType('class-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate(DateTimeInterface::class, $classString, 'arg'))->toBeNull()
            ->and($this->registry->validate(Dog::class, $classString, 'arg'))->toBeNull()
            ->and($this->registry->validate('Invalid Class Name', $classString, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $ifaceString = parseType('interface-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate(DateTimeInterface::class, $ifaceString, 'arg'))->toBeNull()
            ->and($this->registry->validate(stdClass::class, $ifaceString, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $enumString = parseType('enum-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate(StatusEnum::class, $enumString, 'arg'))->toBeNull()
            ->and($this->registry->validate(stdClass::class, $enumString, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $callableStr = parseType('callable-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('strlen', $callableStr, 'arg'))->toBeNull()
            ->and($this->registry->validate('non_existent_func_123', $callableStr, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates class instances and ignores invalid custom syntax with hyphens', function () {
        $dogNode = parseType(Dog::class, $this->lexer, $this->typeParser);
        expect($this->registry->validate(new Dog(), $dogNode, 'arg'))->toBeNull()
            ->and($this->registry->validate(new Car(), $dogNode, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $hyphenSyntax = parseType('custom-type-with-hyphens', $this->lexer, $this->typeParser);
        expect($this->registry->validate('anything', $hyphenSyntax, 'arg'))->toBeNull();
    });

    test('validates class-string with union bounds (class-string<Dog|Cat>)', function () {
        $unionClassString = parseType('class-string<' . Dog::class . '|' . Cat::class . '>', $this->lexer, $this->typeParser);

        expect($this->registry->validate(Dog::class, $unionClassString, 'arg'))->toBeNull();
        expect($this->registry->validate(Cat::class, $unionClassString, 'arg'))->toBeNull();
        expect($this->registry->validate(Car::class, $unionClassString, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });
});

describe('ConstValidator', function () {
    test('validates string, integer, bool, and null literals', function () {
        $strLiteral = parseType("'active'", $this->lexer, $this->typeParser);
        expect($this->registry->validate('active', $strLiteral, 'arg'))->toBeNull()
            ->and($this->registry->validate('inactive', $strLiteral, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $intLiteral = parseType('42', $this->lexer, $this->typeParser);
        expect($this->registry->validate(42, $intLiteral, 'arg'))->toBeNull()
            ->and($this->registry->validate(100, $intLiteral, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $trueLiteral = parseType('true', $this->lexer, $this->typeParser);
        expect($this->registry->validate(true, $trueLiteral, 'arg'))->toBeNull()
            ->and($this->registry->validate(1, $trueLiteral, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $nullLiteral = parseType('null', $this->lexer, $this->typeParser);
        expect($this->registry->validate(null, $nullLiteral, 'arg'))->toBeNull()
            ->and($this->registry->validate(false, $nullLiteral, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates float literals and handles IEEE 754 precision', function () {
        $floatLiteral = parseType('12.34', $this->lexer, $this->typeParser);
        expect($this->registry->validate(12.34, $floatLiteral, 'arg'))->toBeNull()
            ->and($this->registry->validate(12.35, $floatLiteral, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $precisionLiteral = parseType('0.3', $this->lexer, $this->typeParser);
        $sum = 0.1 + 0.2; // Evaluates to 0.30000000000000004 in IEEE 754
        expect($this->registry->validate($sum, $precisionLiteral, 'arg'))->toBeNull();
    });

    test('validates wildcard class constant patterns', function () {
        $wildcardType = parseType(WildcardConstantFixture::class . '::VERSION_SELECTION_*', $this->lexer, $this->typeParser);
        expect($this->registry->validate('all', $wildcardType, 'arg'))->toBeNull()
            ->and($this->registry->validate('blue-green', $wildcardType, 'arg'))->toBeNull()
            ->and($this->registry->validate('internal-mode', $wildcardType, 'arg'))->toBeNull()
            ->and($this->registry->validate('invalid', $wildcardType, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });
});

describe('GenericValidator', function () {
    test('validates int range bounds', function () {
        $range = parseType('int<1, 10>', $this->lexer, $this->typeParser);
        expect($this->registry->validate(5, $range, 'arg'))->toBeNull()
            ->and($this->registry->validate(0, $range, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate(15, $range, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $minRange = parseType('int<min, 100>', $this->lexer, $this->typeParser);
        expect($this->registry->validate(-99999, $minRange, 'arg'))->toBeNull()
            ->and($this->registry->validate(101, $minRange, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $maxRange = parseType('int<0, max>', $this->lexer, $this->typeParser);
        expect($this->registry->validate(999999, $maxRange, 'arg'))->toBeNull()
            ->and($this->registry->validate(-1, $maxRange, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates class-string<T> with bounds', function () {
        $classStringBound = parseType('class-string<' . DateTimeInterface::class . '>', $this->lexer, $this->typeParser);
        expect($this->registry->validate(DateTime::class, $classStringBound, 'arg'))->toBeNull()
            ->and($this->registry->validate(DateTimeImmutable::class, $classStringBound, 'arg'))->toBeNull()
            ->and($this->registry->validate(stdClass::class, $classStringBound, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates key-of on Constants, Enums, and Shapes', function () {
        $constKeyOf = parseType('key-of<' . DatabaseDriverMap::class . '::PUBLIC_MAP>', $this->lexer, $this->typeParser);
        expect($this->registry->validate('read', $constKeyOf, 'arg'))->toBeNull()
            ->and($this->registry->validate('write', $constKeyOf, 'arg'))->toBeNull()
            ->and($this->registry->validate('invalid', $constKeyOf, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $enumKeyOf = parseType('key-of<' . Suit::class . '>', $this->lexer, $this->typeParser);
        expect($this->registry->validate('Hearts', $enumKeyOf, 'arg'))->toBeNull()
            ->and($this->registry->validate('invalid_case', $enumKeyOf, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $shapeKeyOf = parseType('key-of<array{id: int, name: string}>', $this->lexer, $this->typeParser);
        expect($this->registry->validate('id', $shapeKeyOf, 'arg'))->toBeNull()
            ->and($this->registry->validate('name', $shapeKeyOf, 'arg'))->toBeNull()
            ->and($this->registry->validate('missing_key', $shapeKeyOf, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates value-of on Constants, BackedEnums, and UnitEnums', function () {
        $constValueOf = parseType('value-of<' . DatabaseDriverMap::class . '::PUBLIC_MAP>', $this->lexer, $this->typeParser);
        expect($this->registry->validate(1, $constValueOf, 'arg'))->toBeNull()
            ->and($this->registry->validate(99, $constValueOf, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $enumValueOf = parseType('value-of<' . TransactionStatus::class . '>', $this->lexer, $this->typeParser);
        expect($this->registry->validate(1, $enumValueOf, 'arg'))->toBeNull()
            ->and($this->registry->validate(99, $enumValueOf, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $unitEnumValueOf = parseType('value-of<' . Suit::class . '>', $this->lexer, $this->typeParser);
        expect($this->registry->validate('Hearts', $unitEnumValueOf, 'arg'))->toBeInstanceOf(ErrorMessage::class); // UnitEnums have no backing values
    });

    test('validates int-mask and int-mask-of bitmasks', function () {
        $intMask = parseType('int-mask<1, 2, 4>', $this->lexer, $this->typeParser);
        expect($this->registry->validate(0, $intMask, 'arg'))->toBeNull()
            ->and($this->registry->validate(1, $intMask, 'arg'))->toBeNull()
            ->and($this->registry->validate(3, $intMask, 'arg'))->toBeNull()
            ->and($this->registry->validate(8, $intMask, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $intMaskOf = parseType('int-mask-of<' . BitmaskFlags::class . '::FLAG_*>', $this->lexer, $this->typeParser);
        expect($this->registry->validate(3, $intMaskOf, 'arg'))->toBeNull()
            ->and($this->registry->validate(16, $intMaskOf, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates generic lists and key-value arrays', function () {
        $list = parseType('list<int>', $this->lexer, $this->typeParser);
        expect($this->registry->validate([1, 2, 3], $list, 'arg'))->toBeNull()
            ->and($this->registry->validate(['key' => 1], $list, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $map = parseType('array<string, int>', $this->lexer, $this->typeParser);
        expect($this->registry->validate(['a' => 10], $map, 'arg'))->toBeNull()
            ->and($this->registry->validate([0 => 10], $map, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate(['a' => 'invalid'], $map, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates object generics (Producer<Dog>)', function () {
        $producerDog = parseType(Producer::class . '<' . Dog::class . '>', $this->lexer, $this->typeParser);
        expect($this->registry->validate(new Producer(new Dog()), $producerDog, 'arg'))->toBeNull()
            ->and($this->registry->validate(new Producer(new Car()), $producerDog, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate('not_an_object', $producerDog, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });
});

describe('ArrayShapeValidator & ObjectShapeValidator', function () {
    test('validates array shapes with required, optional, sealed, and unsealed keys', function () {
        $shape = parseType('array{id: int, name: string, active?: bool}', $this->lexer, $this->typeParser);
        expect($this->registry->validate(['id' => 1, 'name' => 'Alice', 'active' => true], $shape, 'arg'))->toBeNull()
            ->and($this->registry->validate(['id' => 1, 'name' => 'Alice'], $shape, 'arg'))->toBeNull()
            ->and($this->registry->validate(['id' => 1], $shape, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate(['id' => 1, 'name' => 'Alice', 'extra' => 1], $shape, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $unsealedShape = parseType('array{id: int, ...<string, string>}', $this->lexer, $this->typeParser);
        expect($this->registry->validate(['id' => 1, 'role' => 'admin'], $unsealedShape, 'arg'))->toBeNull()
            ->and($this->registry->validate(['id' => 1, 'role' => 999], $unsealedShape, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates object shapes on stdClass (fast-path) and custom objects', function () {
        $objShape = parseType('object{id: int, name: string}', $this->lexer, $this->typeParser);

        $std = new stdClass();
        $std->id = 1;
        $std->name = 'Alice';
        expect($this->registry->validate($std, $objShape, 'arg'))->toBeNull();

        $badStd = new stdClass();
        $badStd->id = 1;
        expect($this->registry->validate($badStd, $objShape, 'arg'))->toBeInstanceOf(ErrorMessage::class);

        $custom = new UserObjectShape(1, 'Alice');
        expect($this->registry->validate($custom, $objShape, 'arg'))->toBeNull();

        $uninit = new UninitializedReadonlyContainer();
        expect($this->registry->validate($uninit, $objShape, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });
});

describe('ArrayValidator, UnionValidator, NullableValidator & IntersectionValidator', function () {
    test('validates typed arrays (Type[])', function () {
        $intArray = parseType('int[]', $this->lexer, $this->typeParser);
        expect($this->registry->validate([1, 2, 3], $intArray, 'arg'))->toBeNull()
            ->and($this->registry->validate([], $intArray, 'arg'))->toBeNull()
            ->and($this->registry->validate([1, 'bad', 3], $intArray, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate('not_array', $intArray, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates key-of on implicit keyless tuple shapes (key-of<array{string, int}>)', function () {
        $tupleKeyOf = parseType('key-of<array{string, int}>', $this->lexer, $this->typeParser);

        expect($this->registry->validate(0, $tupleKeyOf, 'arg'))->toBeNull();
        expect($this->registry->validate(1, $tupleKeyOf, 'arg'))->toBeNull();

        expect($this->registry->validate(2, $tupleKeyOf, 'arg'))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates nullable types (?Type)', function () {
        $nullableInt = parseType('?int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(null, $nullableInt, 'arg'))->toBeNull()
            ->and($this->registry->validate(10, $nullableInt, 'arg'))->toBeNull()
            ->and($this->registry->validate('str', $nullableInt, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates union types with deep error bubbling', function () {
        $union = parseType('int|string', $this->lexer, $this->typeParser);
        expect($this->registry->validate(10, $union, 'arg'))->toBeNull()
            ->and($this->registry->validate('str', $union, 'arg'))->toBeNull()
            ->and($this->registry->validate(true, $union, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;

        $deepUnion = parseType('array{id: int, tags: list<string>}|null', $this->lexer, $this->typeParser);
        expect($this->registry->validate(null, $deepUnion, 'arg'))->toBeNull()
            ->and($this->registry->validate(['id' => 10, 'tags' => ['a', 'b']], $deepUnion, 'arg'))->toBeNull()
            ->and($this->registry->validate(['id' => 10, 'tags' => ['a', 123]], $deepUnion, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates intersection types', function () {
        $intersection = parseType('Countable&ArrayAccess', $this->lexer, $this->typeParser);
        expect($this->registry->validate(new CountableArrayAccess(), $intersection, 'arg'))->toBeNull()
            ->and($this->registry->validate(new CountableOnly(), $intersection, 'arg'))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->registry->validate(new ArrayAccessOnly(), $intersection, 'arg'))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('memoizes object validations in TypeValidatorRegistry', function () {
        $dog = new Dog();
        $dogType = parseType(Dog::class, $this->lexer, $this->typeParser);

        expect($this->registry->validate($dog, $dogType, 'arg'))->toBeNull();
        expect($this->registry->validate($dog, $dogType, 'arg'))->toBeNull();

        TypeValidatorRegistry::reset();
        expect($this->registry->validate($dog, $dogType, 'arg'))->toBeNull();
    });
});
