<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast;

use PhpParser\Node;

/**
 * @internal Factory class building AST check nodes and ternary throw expressions.
 */
final class NodeBuilder
{
    public static function createPropertyCheckCall(Node\Expr $valueExpr, Node\Expr $objectOrClassExpr, string $propertyName): Node\Expr\FuncCall
    {
        return new Node\Expr\FuncCall(
            new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkProperty'),
            [
                new Node\Arg($valueExpr),
                new Node\Arg($objectOrClassExpr),
                new Node\Arg(new Node\Scalar\String_($propertyName)),
                new Node\Arg(new Node\Scalar\MagicConst\File()),
            ]
        );
    }

    public static function createVariableCheckCall(Node\Expr $valueExpr, string $typeString, string $varName): Node\Expr\FuncCall
    {
        return new Node\Expr\FuncCall(
            new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkVariable'),
            [
                new Node\Arg($valueExpr),
                new Node\Arg(new Node\Scalar\String_($typeString)),
                new Node\Arg(new Node\Scalar\String_($varName)),
                new Node\Arg(new Node\Scalar\MagicConst\File()),
            ]
        );
    }

    public static function createTernaryThrowExpr(Node\Expr\FuncCall $checkCall, int $startLine = -1): Node\Expr\Ternary
    {
        $args = [
            new Node\Arg(
                new Node\Expr\New_(
                    new Node\Name\FullyQualified('TypePHP\Exception\TypeError'),
                    [
                        new Node\Arg(
                            new Node\Expr\MethodCall(
                                new Node\Expr\Variable('__typephpVal'),
                                'getMessage'
                            )
                        ),
                    ]
                )
            ),
        ];

        if ($startLine !== -1) {
            $args[] = new Node\Arg(new Node\Scalar\LNumber($startLine));
        }

        return new Node\Expr\Ternary(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(
                    new Node\Expr\Variable('__typephpVal'),
                    $checkCall
                ),
                new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
            ),
            new Node\Expr\Throw_(
                new Node\Expr\StaticCall(
                    new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorFactory'),
                    'prepareException',
                    $args
                )
            ),
            new Node\Expr\Variable('__typephpVal')
        );
    }
}