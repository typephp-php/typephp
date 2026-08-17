<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeUnsealedTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeParameterNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\OffsetAccessTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Contract\ContractParser;
use TypePHP\Internal\Config;
use TypePHP\Tests\Fixtures\IgnoreTags\IgnoredMethod;
use TypePHP\Tests\Fixtures\Services\ChildMagicMethodService;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\Tests\Fixtures\Types\ChildMagicPropertyFixture;
use TypePHP\Tests\Fixtures\Types\ConfiguredProperty;
use TypePHP\Tests\Fixtures\Types\HookedInterfaceImplementation;
use TypePHP\Tests\Fixtures\Types\MagicMethodFixture;
use TypePHP\Tests\Fixtures\Types\MagicPropertyFixture;
use TypePHP\Tests\Fixtures\Types\NestedAliasService;
use TypePHP\Tests\Fixtures\Types\NonCpmStrings;

describe('ContractParser Unit Tests', function () {
    beforeEach(function () {
        Config::reset();
        ContractParser::reset();
    });

    afterEach(function () {
        Config::reset();
        ContractParser::reset();
    });

    describe('Function and Method Parsing (parse)', function () {
        test('parses class method contracts and caches results', function () {
            $target = UserService::class . '::find';

            $contract1 = ContractParser::parse($target);
            $contract2 = ContractParser::parse($target);

            expect($contract1)->toBeArray()
                ->and($contract1['types'])->toHaveKey('id')
                ->and($contract1['return'])->not()->toBeNull()
                ->and($contract1)->toBe($contract2)
            ;
        });

        test('parses standalone global/namespaced functions', function () {
            $target = 'TypePHP\Tests\Fixtures\Functions\calculateDiscount';

            $contract = ContractParser::parse($target);

            expect($contract['types'])->toHaveKey('price')
                ->and($contract['types'])->toHaveKey('percentage')
                ->and((string) $contract['types']['price'])->toBe('positive-int')
                ->and($contract['return'])->not()->toBeNull()
                ->and((string) $contract['return'])->toBe('positive-int')
            ;
        });

        test('returns empty contract array for non-existent classes or functions', function () {
            $contract = ContractParser::parse('NonExistentClass12345::method');

            expect($contract['types'])->toBeEmpty()
                ->and($contract['templates'])->toBeEmpty()
                ->and($contract['return'])->toBeNull()
                ->and($contract['aliases'])->toBeEmpty()
            ;
        });

        test('returns class-level templates and aliases when class has no requested method', function () {
            $target = NestedAliasService::class . '::nonExistentMethod';

            $contract = ContractParser::parse($target);

            expect($contract['types'])->toBeEmpty()
                ->and($contract['aliases'])->toHaveKey('LocalId')
                ->and($contract['aliases'])->toHaveKey('LocalRecordShape')
            ;
        });

        test('falls back to property @var docblock for constructor property promotion', function () {
            $target = NonCpmStrings::class . '::__construct';
            $contract = ContractParser::parse($target);

            expect($contract['types'])->toHaveKey('strings')
                ->and($contract['types']['strings'])->toBeInstanceOf(ArrayTypeNode::class)
            ;
        });
    });

    describe('Property Contract Parsing (parseProperty)', function () {
        test('parses instance and static property @var docblocks', function () {
            $instanceProp = ContractParser::parseProperty(ConfiguredProperty::class, 'numbers');
            expect($instanceProp)->toBeInstanceOf(ArrayTypeNode::class);

            $staticProp = ContractParser::parseProperty(ConfiguredProperty::class, 'staticTitle');
            expect($staticProp)->toBeInstanceOf(IdentifierTypeNode::class)
                ->and($staticProp->name)->toBe('string')
            ;
        });

        test('parses interface property docblocks', function () {
            if (PHP_VERSION_ID < 80400) {
                expect(true)->toBeTrue();

                return;
            }

            $readOnlyProp = ContractParser::parseProperty(HookedInterfaceImplementation::class, 'readOnlyProp');

            expect($readOnlyProp)->not()->toBeNull()
                ->and((string) $readOnlyProp)->toBe('positive-int')
            ;
        });

        test('parses class-level magic @property docblocks', function () {
            $magicScore = ContractParser::parseProperty(MagicPropertyFixture::class, 'magicScore');
            expect((string) $magicScore)->toBe('positive-int');

            $magicName = ContractParser::parseProperty(MagicPropertyFixture::class, 'magicName');
            expect((string) $magicName)->toBe('non-empty-string');

            $magicTags = ContractParser::parseProperty(MagicPropertyFixture::class, 'magicTags');
            expect((string) $magicTags)->toBe('list<int>');
        });

        test('inherits magic @property docblocks across inheritance hierarchy', function () {
            $inheritedRole = ContractParser::parseProperty(ChildMagicPropertyFixture::class, 'magicRole');

            expect($inheritedRole)->not()->toBeNull()
                ->and((string) $inheritedRole)->toContain('admin')
            ;
        });

        test('returns null for un-annotated properties or non-existent classes', function () {
            expect(ContractParser::parseProperty('NonExistentClass123', 'prop'))->toBeNull();
            expect(ContractParser::parseProperty(ConfiguredProperty::class, 'nonExistentProperty'))->toBeNull();
        });

        test('returns null for properties marked with @typephp-ignore', function () {
            $ignored = ContractParser::parseProperty(IgnoredMethod::class, 'ignoredProperty');

            expect($ignored)->toBeNull();
        });
    });

    describe('Magic Method Parsing (parseMagicMethod)', function () {
        test('parses dynamic @method annotations with variadics and optional parameters', function () {
            $method = ContractParser::parseMagicMethod(MagicMethodFixture::class, 'processId');

            expect($method)->not()->toBeNull()
                ->and((string) $method['return'])->toBe('positive-int')
                ->and($method['parameters'])->toHaveCount(2)
                ->and($method['parameters'][0]['name'])->toBe('id')
                ->and((string) $method['parameters'][0]['type'])->toBe('positive-int')
            ;

            $variadicMethod = ContractParser::parseMagicMethod(MagicMethodFixture::class, 'fetchList');
            expect($variadicMethod['parameters'][0]['isVariadic'])->toBeTrue();
        });

        test('inherits magic @method annotations from parent classes, interfaces, and traits', function () {
            $parentMethod = ContractParser::parseMagicMethod(ChildMagicMethodService::class, 'parentMethod');
            expect($parentMethod)->not()->toBeNull();

            $interfaceMethod = ContractParser::parseMagicMethod(ChildMagicMethodService::class, 'interfaceMethod');
            expect($interfaceMethod)->not()->toBeNull();

            $traitMethod = ContractParser::parseMagicMethod(ChildMagicMethodService::class, 'traitMethod');
            expect($traitMethod)->not()->toBeNull();
        });

        test('returns null for non-existent magic methods or non-existent classes', function () {
            expect(ContractParser::parseMagicMethod('NonExistentClass123', 'method'))->toBeNull();
            expect(ContractParser::parseMagicMethod(MagicMethodFixture::class, 'nonExistentMagicMethod'))->toBeNull();
        });
    });

    describe('Class Aliases (parseClassAliases)', function () {
        test('parses and returns all local type aliases for a class', function () {
            $aliases = ContractParser::parseClassAliases(NestedAliasService::class);

            expect($aliases)->toHaveKey('LocalId')
                ->and($aliases)->toHaveKey('LocalStatus')
                ->and($aliases)->toHaveKey('LocalRecordShape')
                ->and((string) $aliases['LocalId'])->toBe('positive-int')
            ;
        });

        test('returns empty array for non-existent classes', function () {
            expect(ContractParser::parseClassAliases('NonExistentClass123'))->toBe([]);
        });
    });

    describe('AST Type Alias Substitution (substituteAliases)', function () {
        beforeEach(function () {
            $this->aliases = [
                'UserId' => new IdentifierTypeNode('positive-int'),
                'UserName' => new IdentifierTypeNode('non-empty-string'),
                'UserRole' => new IdentifierTypeNode("'admin'|'user'"),
            ];
        });

        test('substitutes aliases in IdentifierTypeNode', function () {
            $node = new IdentifierTypeNode('UserId');
            $result = ContractParser::substituteAliases($node, $this->aliases);

            expect((string) $result)->toBe('positive-int');

            $unaliased = new IdentifierTypeNode('string');
            expect(ContractParser::substituteAliases($unaliased, $this->aliases))->toBe($unaliased);
        });

        test('substitutes parameter and return aliases in CallableTypeNode', function () {
            $callableNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [new CallableTypeParameterNode(new IdentifierTypeNode('UserId'), false, false, 'id', false)],
                new IdentifierTypeNode('UserName'),
                []
            );

            $result = ContractParser::substituteAliases($callableNode, $this->aliases);

            expect($result)->toBeInstanceOf(CallableTypeNode::class)
                ->and((string) $result)->toContain('positive-int')
                ->and((string) $result)->toContain('non-empty-string')
            ;
        });

        test('substitutes target and offset aliases in OffsetAccessTypeNode', function () {
            $offsetNode = new OffsetAccessTypeNode(new IdentifierTypeNode('UserId'), new IdentifierTypeNode('UserName'));
            $result = ContractParser::substituteAliases($offsetNode, $this->aliases);

            expect($result)->toBeInstanceOf(OffsetAccessTypeNode::class)
                ->and((string) $result->type)->toBe('positive-int')
                ->and((string) $result->offset)->toBe('non-empty-string')
            ;
        });

        test('substitutes inner type aliases in ArrayTypeNode (UserId[] -> positive-int[])', function () {
            $arrNode = new ArrayTypeNode(new IdentifierTypeNode('UserId'));
            $result = ContractParser::substituteAliases($arrNode, $this->aliases);

            expect($result)->toBeInstanceOf(ArrayTypeNode::class)
                ->and((string) $result)->toBe('positive-int[]')
            ;
        });

        test('substitutes base and generic argument aliases in GenericTypeNode (Collection<UserId>)', function () {
            $genericNode = new GenericTypeNode(new IdentifierTypeNode('Collection'), [new IdentifierTypeNode('UserId')]);
            $result = ContractParser::substituteAliases($genericNode, $this->aliases);

            expect($result)->toBeInstanceOf(GenericTypeNode::class)
                ->and((string) $result)->toContain('positive-int')
            ;
        });

        test('substitutes inner type aliases in NullableTypeNode (?UserId -> ?positive-int)', function () {
            $nullableNode = new NullableTypeNode(new IdentifierTypeNode('UserId'));
            $result = ContractParser::substituteAliases($nullableNode, $this->aliases);

            expect($result)->toBeInstanceOf(NullableTypeNode::class)
                ->and((string) $result)->toBe('?positive-int')
            ;
        });

        test('substitutes member type aliases in UnionTypeNode (UserId|UserName)', function () {
            $unionNode = new UnionTypeNode([new IdentifierTypeNode('UserId'), new IdentifierTypeNode('UserName')]);
            $result = ContractParser::substituteAliases($unionNode, $this->aliases);

            expect($result)->toBeInstanceOf(UnionTypeNode::class)
                ->and((string) $result)->toContain('positive-int')
                ->and((string) $result)->toContain('non-empty-string')
            ;
        });

        test('substitutes member type aliases in IntersectionTypeNode (UserId&UserName)', function () {
            $intersectionNode = new IntersectionTypeNode([new IdentifierTypeNode('UserId'), new IdentifierTypeNode('UserName')]);
            $result = ContractParser::substituteAliases($intersectionNode, $this->aliases);

            expect($result)->toBeInstanceOf(IntersectionTypeNode::class)
                ->and((string) $result)->toContain('positive-int')
                ->and((string) $result)->toContain('non-empty-string')
            ;
        });

        test('substitutes field and unsealed type aliases in ArrayShapeNode', function () {
            $unsealed = new ArrayShapeUnsealedTypeNode(new IdentifierTypeNode('UserName'), new IdentifierTypeNode('UserId'));
            $shapeNode = ArrayShapeNode::createUnsealed([
                new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('UserId')),
            ], $unsealed);

            $result = ContractParser::substituteAliases($shapeNode, $this->aliases);

            expect($result)->toBeInstanceOf(ArrayShapeNode::class)
                ->and((string) $result->items[0]->valueType)->toBe('positive-int')
                ->and((string) $result->unsealedType?->keyType)->toBe('positive-int')
                ->and((string) $result->unsealedType?->valueType)->toBe('non-empty-string')
            ;
        });

        test('substitutes property value aliases in ObjectShapeNode', function () {
            $objShapeNode = new ObjectShapeNode([
                new ObjectShapeItemNode(new IdentifierTypeNode('id'), false, new IdentifierTypeNode('UserId')),
                new ObjectShapeItemNode(new IdentifierTypeNode('name'), false, new IdentifierTypeNode('UserName')),
            ]);

            $result = ContractParser::substituteAliases($objShapeNode, $this->aliases);

            expect($result)->toBeInstanceOf(ObjectShapeNode::class)
                ->and((string) $result->items[0]->valueType)->toBe('positive-int')
                ->and((string) $result->items[1]->valueType)->toBe('non-empty-string')
            ;
        });
    });
});
