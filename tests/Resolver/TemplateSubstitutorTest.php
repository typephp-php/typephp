<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeUnsealedTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Resolver\TemplateSubstitutor;

describe('TemplateSubstitutor Unit Tests', function () {
    test('substitutes simple identifier template placeholders', function () {
        $templateNode = new IdentifierTypeNode('T');
        $boundTemplates = ['T' => new IdentifierTypeNode('int')];

        $result = TemplateSubstitutor::substitute($templateNode, $boundTemplates);

        expect($result)->toBeInstanceOf(IdentifierTypeNode::class)
            ->and($result->name)->toBe('int')
        ;
    });

    test('falls back to default template type when unbound (@template T = string)', function () {
        $templateNode = new IdentifierTypeNode('T');
        $declaredTemplates = [
            'T' => new TemplateTagValueNode(
                name: 'T',
                bound: null,
                description: '',
                default: new IdentifierTypeNode('string')
            ),
        ];

        $result = TemplateSubstitutor::substitute($templateNode, [], $declaredTemplates);

        expect($result)->toBeInstanceOf(IdentifierTypeNode::class)
            ->and($result->name)->toBe('string')
        ;
    });

    test('prefers default template over bound template when unbound (@template T of object = stdClass)', function () {
        $templateNode = new IdentifierTypeNode('T');
        $declaredTemplates = [
            'T' => new TemplateTagValueNode(
                name: 'T',
                bound: new IdentifierTypeNode('object'),
                description: '',
                default: new IdentifierTypeNode('stdClass')
            ),
        ];

        $result = TemplateSubstitutor::substitute($templateNode, [], $declaredTemplates);

        expect($result)->toBeInstanceOf(IdentifierTypeNode::class)
            ->and($result->name)->toBe('stdClass')
        ;
    });

    test('falls back to bound template when default is null (@template T of object)', function () {
        $templateNode = new IdentifierTypeNode('T');
        $declaredTemplates = [
            'T' => new TemplateTagValueNode(
                name: 'T',
                bound: new IdentifierTypeNode('object'),
                description: '',
                default: null
            ),
        ];

        $result = TemplateSubstitutor::substitute($templateNode, [], $declaredTemplates);

        expect($result)->toBeInstanceOf(IdentifierTypeNode::class)
            ->and($result->name)->toBe('object')
        ;
    });

    test('substitutes template placeholders in CallableTypeNode', function () {
        $callableNode = new CallableTypeNode(
            new IdentifierTypeNode('callable'),
            [new CallableTypeParameterNode(new IdentifierTypeNode('T'), false, false, '$item', false)],
            new IdentifierTypeNode('T'),
            []
        );
        $bound = ['T' => new IdentifierTypeNode('int')];

        $result = TemplateSubstitutor::substitute($callableNode, $bound);

        expect($result)->toBeInstanceOf(CallableTypeNode::class)
            ->and((string) $result)->toBe('callable(int $item): int');
    });

    test('substitutes template placeholders in ConditionalTypeNode & ConditionalTypeForParameterNode', function () {
        $conditional = new ConditionalTypeNode(
            new IdentifierTypeNode('T'),
            new IdentifierTypeNode('Dog'),
            new IdentifierTypeNode('T'),
            new IdentifierTypeNode('string'),
            false
        );
        $bound = ['T' => new IdentifierTypeNode('Dog')];

        $result = TemplateSubstitutor::substitute($conditional, $bound);
        expect($result)->toBeInstanceOf(ConditionalTypeNode::class)
            ->and((string) $result->subjectType)->toBe('Dog')
        ;

        $paramConditional = new ConditionalTypeForParameterNode(
            '$flag',
            new IdentifierTypeNode('true'),
            new IdentifierTypeNode('T'),
            new IdentifierTypeNode('string'),
            false
        );
        $paramResult = TemplateSubstitutor::substitute($paramConditional, $bound);
        expect($paramResult)->toBeInstanceOf(ConditionalTypeForParameterNode::class)
            ->and((string) $paramResult->if)->toBe('Dog')
        ;
    });

    test('substitutes array template placeholders (T[] -> int[])', function () {
        $arrayNode = new ArrayTypeNode(new IdentifierTypeNode('T'));
        $boundTemplates = ['T' => new IdentifierTypeNode('int')];

        $result = TemplateSubstitutor::substitute($arrayNode, $boundTemplates);

        expect($result)->toBeInstanceOf(ArrayTypeNode::class)
            ->and($result->type->name)->toBe('int')
        ;
    });

    test('substitutes generic template parameters (Container<T> -> Container<string>)', function () {
        $genericNode = new GenericTypeNode(
            new IdentifierTypeNode('Container'),
            [new IdentifierTypeNode('T')]
        );
        $boundTemplates = ['T' => new IdentifierTypeNode('string')];

        $result = TemplateSubstitutor::substitute($genericNode, $boundTemplates);

        expect($result)->toBeInstanceOf(GenericTypeNode::class)
            ->and($result->genericTypes[0]->name)->toBe('string')
        ;
    });

    test('substitutes template placeholders inside nullable types (?T -> ?int)', function () {
        $nullableNode = new NullableTypeNode(new IdentifierTypeNode('T'));
        $boundTemplates = ['T' => new IdentifierTypeNode('int')];

        $result = TemplateSubstitutor::substitute($nullableNode, $boundTemplates);

        expect($result)->toBeInstanceOf(NullableTypeNode::class)
            ->and($result->type->name)->toBe('int')
        ;
    });

    test('substitutes template placeholders inside union and intersection types', function () {
        $unionNode = new UnionTypeNode([
            new IdentifierTypeNode('T'),
            new IdentifierTypeNode('string'),
        ]);
        $boundTemplates = ['T' => new IdentifierTypeNode('int')];

        $result = TemplateSubstitutor::substitute($unionNode, $boundTemplates);
        expect($result)->toBeInstanceOf(UnionTypeNode::class)
            ->and($result->types[0]->name)->toBe('int')
            ->and($result->types[1]->name)->toBe('string')
        ;

        $intersection = new IntersectionTypeNode([
            new IdentifierTypeNode('T'),
            new IdentifierTypeNode('Countable'),
        ]);
        $resIntersection = TemplateSubstitutor::substitute($intersection, $boundTemplates);
        expect($resIntersection)->toBeInstanceOf(IntersectionTypeNode::class)
            ->and($resIntersection->types[0]->name)->toBe('int')
        ;
    });

    test('substitutes template placeholders in ArrayShapeNode and ObjectShapeNode', function () {
        $unsealed = new ArrayShapeUnsealedTypeNode(new IdentifierTypeNode('T'), new IdentifierTypeNode('string'));
        $shape = ArrayShapeNode::createUnsealed([
            new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('T')),
        ], $unsealed);
        $bound = ['T' => new IdentifierTypeNode('positive-int')];

        $resShape = TemplateSubstitutor::substitute($shape, $bound);
        expect($resShape)->toBeInstanceOf(ArrayShapeNode::class)
            ->and((string) $resShape->items[0]->valueType)->toBe('positive-int')
            ->and((string) $resShape->unsealedType?->valueType)->toBe('positive-int')
        ;

        $objShape = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('item'), false, new IdentifierTypeNode('T')),
        ]);
        $resObjShape = TemplateSubstitutor::substitute($objShape, $bound);
        expect($resObjShape)->toBeInstanceOf(ObjectShapeNode::class)
            ->and((string) $resObjShape->items[0]->valueType)->toBe('positive-int')
        ;
    });

    test('leaves non-template types untouched', function () {
        $intNode = new IdentifierTypeNode('int');
        $boundTemplates = ['T' => new IdentifierTypeNode('string')];

        $result = TemplateSubstitutor::substitute($intNode, $boundTemplates);

        expect($result->name)->toBe('int');
    });
});
