<?php

declare(strict_types=1);

namespace TypePHP\Resolver;

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
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;

/**
 * @internal Recursively substitutes template placeholders (like T) with their bound concrete types (like int).
 */
final class TemplateSubstitutor
{
    /**
     * Recursively substitutes template placeholders (like T) with their bound concrete types (like int).
     *
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     */
    public static function substitute(TypeNode $node, array $boundTemplates, array $declaredTemplates = []): TypeNode
    {
        if (\count($boundTemplates) === 0 && \count($declaredTemplates) === 0) {
            return $node;
        }

        return self::substituteNode($node, $boundTemplates, $declaredTemplates, []);
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     * @param array<string, true> $visited
     */
    private static function substituteNode(
        TypeNode $node,
        array $boundTemplates,
        array $declaredTemplates,
        array $visited
    ): TypeNode {
        if ($node instanceof IdentifierTypeNode) {
            return self::substituteIdentifier($node, $boundTemplates, $declaredTemplates, $visited);
        }

        if ($node instanceof CallableTypeNode) {
            return self::substituteCallable($node, $boundTemplates, $declaredTemplates, $visited);
        }

        if ($node instanceof ConditionalTypeNode) {
            return self::substituteConditional($node, $boundTemplates, $declaredTemplates, $visited);
        }

        if ($node instanceof ConditionalTypeForParameterNode) {
            return self::substituteParameterConditional($node, $boundTemplates, $declaredTemplates, $visited);
        }

        if ($node instanceof ArrayTypeNode) {
            return new ArrayTypeNode(self::substituteNode($node->type, $boundTemplates, $declaredTemplates, $visited));
        }

        if ($node instanceof GenericTypeNode) {
            return self::substituteGeneric($node, $boundTemplates, $declaredTemplates, $visited);
        }

        if ($node instanceof NullableTypeNode) {
            return new NullableTypeNode(self::substituteNode($node->type, $boundTemplates, $declaredTemplates, $visited));
        }

        if ($node instanceof UnionTypeNode) {
            return new UnionTypeNode(array_map(
                fn ($t) => self::substituteNode($t, $boundTemplates, $declaredTemplates, $visited),
                $node->types
            ));
        }

        if ($node instanceof IntersectionTypeNode) {
            return new IntersectionTypeNode(array_map(
                fn ($t) => self::substituteNode($t, $boundTemplates, $declaredTemplates, $visited),
                $node->types
            ));
        }

        if ($node instanceof ArrayShapeNode) {
            return self::substituteArrayShape($node, $boundTemplates, $declaredTemplates, $visited);
        }

        if ($node instanceof ObjectShapeNode) {
            return self::substituteObjectShape($node, $boundTemplates, $declaredTemplates, $visited);
        }

        return $node;
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     * @param array<string, true> $visited
     */
    private static function substituteIdentifier(
        IdentifierTypeNode $node,
        array $boundTemplates,
        array $declaredTemplates,
        array $visited
    ): TypeNode {
        if (isset($boundTemplates[$node->name])) {
            return $boundTemplates[$node->name];
        }

        if (isset($declaredTemplates[$node->name])) {
            if (isset($visited[$node->name])) {
                return new IdentifierTypeNode('mixed');
            }
            $visited[$node->name] = true;

            $templateTag = $declaredTemplates[$node->name];
            $fallback = $templateTag->default ?? $templateTag->bound ?? new IdentifierTypeNode('mixed');

            return self::substituteNode($fallback, $boundTemplates, $declaredTemplates, $visited);
        }

        return $node;
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     * @param array<string, true> $visited
     */
    private static function substituteCallable(
        CallableTypeNode $node,
        array $boundTemplates,
        array $declaredTemplates,
        array $visited
    ): CallableTypeNode {
        $parameters = array_map(
            fn (CallableTypeParameterNode $param) => new CallableTypeParameterNode(
                self::substituteNode($param->type, $boundTemplates, $declaredTemplates, $visited),
                $param->isReference,
                $param->isVariadic,
                $param->parameterName,
                $param->isOptional
            ),
            $node->parameters
        );

        $returnType = self::substituteNode($node->returnType, $boundTemplates, $declaredTemplates, $visited);

        return new CallableTypeNode(
            $node->identifier,
            $parameters,
            $returnType,
            $node->templateTypes
        );
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     * @param array<string, true> $visited
     */
    private static function substituteConditional(
        ConditionalTypeNode $node,
        array $boundTemplates,
        array $declaredTemplates,
        array $visited
    ): ConditionalTypeNode {
        return new ConditionalTypeNode(
            self::substituteNode($node->subjectType, $boundTemplates, $declaredTemplates, $visited),
            self::substituteNode($node->targetType, $boundTemplates, $declaredTemplates, $visited),
            self::substituteNode($node->if, $boundTemplates, $declaredTemplates, $visited),
            self::substituteNode($node->else, $boundTemplates, $declaredTemplates, $visited),
            $node->negated
        );
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     * @param array<string, true> $visited
     */
    private static function substituteParameterConditional(
        ConditionalTypeForParameterNode $node,
        array $boundTemplates,
        array $declaredTemplates,
        array $visited
    ): ConditionalTypeForParameterNode {
        return new ConditionalTypeForParameterNode(
            $node->parameterName,
            self::substituteNode($node->targetType, $boundTemplates, $declaredTemplates, $visited),
            self::substituteNode($node->if, $boundTemplates, $declaredTemplates, $visited),
            self::substituteNode($node->else, $boundTemplates, $declaredTemplates, $visited),
            $node->negated
        );
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     * @param array<string, true> $visited
     */
    private static function substituteGeneric(
        GenericTypeNode $node,
        array $boundTemplates,
        array $declaredTemplates,
        array $visited
    ): GenericTypeNode {
        $type = self::substituteNode($node->type, $boundTemplates, $declaredTemplates, $visited);
        $genericTypes = array_map(
            fn ($t) => self::substituteNode($t, $boundTemplates, $declaredTemplates, $visited),
            $node->genericTypes
        );

        return new GenericTypeNode(
            $type instanceof IdentifierTypeNode ? $type : $node->type,
            $genericTypes,
            $node->variances
        );
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     * @param array<string, true> $visited
     */
    private static function substituteArrayShape(
        ArrayShapeNode $node,
        array $boundTemplates,
        array $declaredTemplates,
        array $visited
    ): ArrayShapeNode {
        $newItems = [];
        foreach ($node->items as $item) {
            $newItems[] = new ArrayShapeItemNode(
                $item->keyName,
                $item->optional,
                self::substituteNode($item->valueType, $boundTemplates, $declaredTemplates, $visited)
            );
        }

        $newUnsealed = null;
        if ($node->unsealedType !== null) {
            $unsealedKey = $node->unsealedType->keyType !== null
                ? self::substituteNode($node->unsealedType->keyType, $boundTemplates, $declaredTemplates, $visited)
                : null;
            $unsealedVal = self::substituteNode($node->unsealedType->valueType, $boundTemplates, $declaredTemplates, $visited);
            $newUnsealed = new ArrayShapeUnsealedTypeNode($unsealedVal, $unsealedKey);
        }

        if ($node->sealed) {
            return ArrayShapeNode::createSealed($newItems, $node->kind);
        }

        return ArrayShapeNode::createUnsealed($newItems, $newUnsealed, $node->kind);
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     * @param array<string, true> $visited
     */
    private static function substituteObjectShape(
        ObjectShapeNode $node,
        array $boundTemplates,
        array $declaredTemplates,
        array $visited
    ): ObjectShapeNode {
        $newItems = [];
        foreach ($node->items as $item) {
            $newItems[] = new ObjectShapeItemNode(
                $item->keyName,
                $item->optional,
                self::substituteNode($item->valueType, $boundTemplates, $declaredTemplates, $visited)
            );
        }

        return new ObjectShapeNode($newItems);
    }
}