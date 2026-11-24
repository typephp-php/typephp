<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasImportTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Ast\Elimination\EliminationSafetyPolicy;
use TypePHP\Internal\Ast\Elimination\Evaluator\GeneratorEvaluator;
use TypePHP\Internal\Ast\Elimination\StaticTypeEvaluator;
use TypePHP\Internal\Checker\ConditionalChecker;
use TypePHP\Internal\Docblock\DocblockExtractor;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\ClassNameValidator;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\StubManager;

/**
 * @internal Injects parameter checks, return checks, param-out checks, and generator interceptors into functions and methods.
 */
final class FunctionContractInjector
{
    private const ITERABLE_TYPES = [
        'iterable' => true,
        'traversable' => true,
        'generator' => true,
        'iterator' => true,
        'iteratoraggregate' => true,
    ];

    private const CALLABLE_TYPES = [
        'callable' => true,
        'closure' => true,
    ];

    /**
     * @param array{name?: ?string, hasInheritance?: bool, hasRealInheritance?: bool, hasPropertyWithDoc?: bool, isReadonly?: bool, isFinal?: bool, hasTemplates?: bool, isTrait?: bool, aliases?: array<string, TypeNode>, templates?: array<string, TemplateTagValueNode>}|null $classContext
     */
    public static function inject(
        Node\Stmt\Function_|Node\Stmt\ClassMethod $node,
        ?array $classContext = null,
        string $currentFile = '',
        ?string $currentNamespace = null
    ): void {
        if ($node->stmts === null) {
            return;
        }

        $isClassMethod = $node instanceof Node\Stmt\ClassMethod;
        $doc = self::resolveDocComment($node, $classContext);
        $docText = $doc !== null ? $doc->getText() : '';

        $hasInheritance = $classContext['hasInheritance'] ?? true;
        $hasRealInheritance = $classContext['hasRealInheritance'] ?? $hasInheritance;
        $hasPropertyWithDoc = $classContext['hasPropertyWithDoc'] ?? true;
        $isReadonlyClass = $classContext['isReadonly'] ?? false;
        $isFinalClass = $classContext['isFinal'] ?? false;
        $hasClassTemplates = $classContext['hasTemplates'] ?? false;

        $methodName = $isClassMethod ? strtolower($node->name->toString()) : '';
        $isConstructor = $isClassMethod && $methodName === '__construct';

        if ($isConstructor && ! $hasPropertyWithDoc) {
            foreach ($node->params as $param) {
                if ($param->isPromoted() && $param->getDocComment() !== null) {
                    $hasPropertyWithDoc = true;

                    break;
                }
            }
        }

        $isMagicLifecycle = $isClassMethod && \in_array($methodName, ['__construct', '__destruct', '__clone'], true);
        $isMagicGet = $isClassMethod && $methodName === '__get';
        $isMagicCall = $isClassMethod && ($methodName === '__call' || $methodName === '__callstatic');
        $isNativeNever = $node->returnType instanceof Node\Identifier && strtolower($node->returnType->name) === 'never';
        $isNativeVoid = $node->returnType instanceof Node\Identifier && strtolower($node->returnType->name) === 'void';
        $isPrivate = $isClassMethod && $node->isPrivate();

        $paramCount = \count($node->params);

        $hasReturnDoc = str_contains($docText, '@return')
            || str_contains($docText, '@phpstan-return')
            || str_contains($docText, '@psalm-return');

        $hasReturn = ! $isMagicLifecycle
            && ! $isNativeNever
            && ! ($isNativeVoid && ! $hasReturnDoc)
            && ($isMagicGet || $isMagicCall || self::hasReturnContracts(
                $docText,
                $isClassMethod,
                $hasInheritance,
                $isPrivate,
                $classContext === null,
            ));

        $byRefParams = [];
        foreach ($node->params as $p) {
            if ($p->byRef && $p->var instanceof Node\Expr\Variable && \is_string($p->var->name)) {
                $byRefParams[] = $p->var->name;
            }
        }

        $hasParamOutDoc = str_contains($docText, '@param-out')
            || str_contains($docText, '@phpstan-param-out')
            || str_contains($docText, '@psalm-param-out');

        $hasParamOut = $byRefParams !== [] && ($hasParamOutDoc || $hasInheritance);

        $hasSelfOutDoc = str_contains($docText, 'self-out') || str_contains($docText, 'this-out');
        $hasSelfOut = $isClassMethod && ! $node->isStatic() && ($hasSelfOutDoc || ($hasClassTemplates && $hasRealInheritance));

        $hasParam = ($isMagicGet || $isMagicCall) || ($paramCount > 0 && (
            str_contains($docText, ' is ') || (($hasReturnDoc || $hasSelfOutDoc || $hasParamOutDoc) && str_contains($docText, '$'))
        )) || self::hasParamContracts(
            $docText,
            $isClassMethod,
            $hasInheritance,
            $paramCount,
            $isPrivate,
            $isConstructor,
            $hasPropertyWithDoc,
            $classContext === null,
            $node->attrGroups !== [],
        );

        if (! $hasParam && ! $hasReturn && ! $hasParamOut && ! $hasSelfOut) {
            return;
        }

        $thisArg = self::resolveThisArg($isClassMethod, $node);
        $needsReturnVars = ($hasParam && ($paramCount > 0) && (
            $hasInheritance || str_contains($docText, ' is ') || ($hasReturnDoc && str_contains($docText, '$'))
        )) || ($isClassMethod && $paramCount > 0 && ($isMagicGet || $isMagicCall));

        $useDynamicMethodKey = $isClassMethod && ! $isPrivate && ! $isFinalClass;

        $phpDocNode = null;
        $returnTypeNode = null;
        $hasMethodTemplates = false;
        $declaredTemplates = [];
        if ($doc !== null) {
            try {
                $phpDocNode = DocblockExtractor::parseDocString($doc->getText());
                $returnTag = DocblockExtractor::getReturnTag($phpDocNode);
                if ($returnTag !== null) {
                    $returnTypeNode = $returnTag->type;
                }
                $declaredTemplates = DocblockExtractor::extractTemplates($phpDocNode);
                $hasMethodTemplates = $declaredTemplates !== [];
            } catch (\Throwable) {
            }
        }

        $effectiveAliases = $classContext['aliases'] ?? [];
        if ($phpDocNode !== null && (str_contains($docText, '-type') || str_contains($docText, 'import-type'))) {
            $effectiveAliases = self::mergeDocblockAliases($phpDocNode, $effectiveAliases, $currentFile, $currentNamespace);
        }

        /** @var array<string, TemplateTagValueNode> $effectiveTemplates */
        $effectiveTemplates = [...($classContext['templates'] ?? []), ...$declaredTemplates];

        /** @var array<string, TypeNode> $resolvedParamTypes */
        $resolvedParamTypes = [];
        if ($phpDocNode !== null) {
            foreach (DocblockExtractor::getParamTags($phpDocNode) as $pName => $pTag) {
                $pType = $pTag->type;
                if ($effectiveAliases !== []) {
                    $pType = DocblockParser::substituteAliases($pType, $effectiveAliases);
                }
                $resolvedParamTypes[$pName] = $pType;
            }
        }

        $isTrait = $classContext['isTrait'] ?? false;

        if (
            $hasParam
            && ! $isConstructor
            && ! $isMagicGet
            && ! $isMagicCall
            && ! $hasMethodTemplates
            && ! $hasParamOut
            && ! $hasSelfOut
            && ! $isTrait
            && ! ($isClassMethod && $node->isStatic() && $hasClassTemplates)
            && self::canEliminateSetupScope(
                $node,
                $phpDocNode,
                $docText,
                $isClassMethod,
                $isPrivate,
                $hasRealInheritance,
                $returnTypeNode,
                $hasReturn,
                $resolvedParamTypes,
                $effectiveTemplates
            )
        ) {
            $hasParam = false;
            $needsReturnVars = false;
        }

        if (! $hasParam && ! $hasReturn && ! $hasParamOut && ! $hasSelfOut) {
            return;
        }

        $injectedStmts = [];
        if ($hasParam) {
            $injectedStmts = self::buildParamInjections(
                $node->params,
                $docText,
                $thisArg,
                $isReadonlyClass,
                $needsReturnVars,
                $useDynamicMethodKey,
                $resolvedParamTypes,
                $effectiveTemplates
            );
        }

        $trustedParamTypes = self::collectTrustedParamTypes($node, $doc, $declaredTemplates, $currentFile, $currentNamespace);

        if ($hasReturn || $hasParamOut || $hasSelfOut) {
            $node->stmts = self::isGenerator($node)
                ? self::wrapGeneratorReturns(
                    $node->stmts,
                    $thisArg,
                    $node,
                    $classContext,
                    $returnTypeNode,
                    $currentFile,
                    $hasMethodTemplates,
                    $needsReturnVars,
                    $hasParamOut ? $byRefParams : [],
                    $hasSelfOut,
                    $hasParam,
                    $trustedParamTypes,
                    $useDynamicMethodKey,
                    $currentNamespace
                )
                : self::wrapNonGeneratorReturns(
                    $node->stmts,
                    $thisArg,
                    $isNativeVoid,
                    $needsReturnVars,
                    $hasReturn,
                    $hasParamOut ? $byRefParams : [],
                    $hasSelfOut,
                    $node->byRef,
                    $node,
                    $classContext,
                    $returnTypeNode,
                    $currentFile,
                    $trustedParamTypes,
                    $useDynamicMethodKey,
                    ! $isMagicGet && ! $isMagicCall,
                    $currentNamespace
                );
        }

        $node->stmts = [...$injectedStmts, ...$node->stmts];
    }

    /**
     * @param array<string, TypeNode> $resolvedParamTypes
     * @param array<string, TemplateTagValueNode> $effectiveTemplates
     */
    private static function canEliminateSetupScope(
        Node\Stmt\Function_|Node\Stmt\ClassMethod $node,
        ?PhpDocNode $phpDocNode,
        string $docText,
        bool $isClassMethod,
        bool $isPrivate,
        bool $hasRealInheritance,
        ?TypeNode $returnTypeNode,
        bool $hasReturn,
        array $resolvedParamTypes = [],
        array $effectiveTemplates = []
    ): bool {
        if (! Config::isEliminateStaticallyProvenChecksEnabled()) {
            return false;
        }

        if ($node->attrGroups !== [] || str_contains($docText, ' is ')) {
            return false;
        }

        if ($isClassMethod && ! $isPrivate && $hasRealInheritance) {
            return false;
        }

        if ($hasReturn && ($returnTypeNode !== null && ConditionalChecker::containsConditional($returnTypeNode))) {
            return false;
        }

        $paramTags = $phpDocNode !== null ? DocblockExtractor::getParamTags($phpDocNode) : [];

        foreach ($node->params as $param) {
            if ($param->variadic || ! ($param->var instanceof Node\Expr\Variable) || ! \is_string($param->var->name)) {
                return false;
            }

            $pName = $param->var->name;
            $resolvedType = $resolvedParamTypes[$pName] ?? null;
            $paramDoc = $param->getDocComment() !== null ? $param->getDocComment()->getText() : '';
            $effectiveDoc = $docText . ' ' . $paramDoc;
            if (
                self::isCallableCandidate($param, $effectiveDoc, $resolvedType, $effectiveTemplates)
                || self::isIterableCandidate($param, $effectiveDoc, $resolvedType, $effectiveTemplates)
            ) {
                return false;
            }

            $paramTag = $paramTags[$pName] ?? null;

            if ($paramTag === null) {
                if ($hasRealInheritance) {
                    return false;
                }

                continue;
            }

            if (! self::nativeParamSatisfiesDocType($param->type, $resolvedType ?? $paramTag->type)) {
                return false;
            }
        }

        return true;
    }

    private static function nativeParamSatisfiesDocType(
        Node\Identifier|Node\Name|Node\ComplexType|null $nativeType,
        TypeNode $docType
    ): bool {
        if ($docType instanceof IdentifierTypeNode && strtolower($docType->name) === 'mixed') {
            return true;
        }

        if ($nativeType === null) {
            return false;
        }

        if ($nativeType instanceof Node\NullableType) {
            if ($docType instanceof NullableTypeNode) {
                return self::nativeParamSatisfiesDocType($nativeType->type, $docType->type);
            }

            if ($docType instanceof UnionTypeNode) {
                $hasNull = false;
                foreach ($docType->types as $member) {
                    if ($member instanceof IdentifierTypeNode && strtolower($member->name) === 'null') {
                        $hasNull = true;

                        break;
                    }
                }

                return $hasNull && self::nativeParamSatisfiesDocType($nativeType->type, $docType);
            }

            return false;
        }

        if ($nativeType instanceof Node\UnionType) {
            foreach ($nativeType->types as $t) {
                if (! self::nativeParamSatisfiesDocType($t, $docType)) {
                    return false;
                }
            }

            return true;
        }

        if ($docType instanceof NullableTypeNode) {
            return self::nativeParamSatisfiesDocType($nativeType, $docType->type);
        }

        if ($docType instanceof UnionTypeNode) {
            foreach ($docType->types as $member) {
                if (self::nativeParamSatisfiesDocType($nativeType, $member)) {
                    return true;
                }
            }

            return false;
        }

        if ($nativeType instanceof Node\Identifier && $docType instanceof IdentifierTypeNode) {
            $native = strtolower($nativeType->name);
            $doc = strtolower($docType->name);

            if ($native === $doc) {
                return true;
            }

            return match ($native) {
                'int' => \in_array($doc, ['integer', 'float', 'double', 'array-key', 'scalar', 'numeric', 'number'], true),
                'string' => \in_array($doc, ['array-key', 'scalar'], true),
                'bool' => \in_array($doc, ['boolean', 'scalar'], true),
                'float' => \in_array($doc, ['double', 'scalar', 'numeric', 'number'], true),
                'array' => $doc === 'iterable',
                default => false,
            };
        }

        return false;
    }

    /**
     * Extracts concrete parameter contracts and verifies they are never mutated in the body.
     *
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     *
     * @return array<string, TypeNode>
     */
    private static function collectTrustedParamTypes(
        Node\Stmt\Function_|Node\Stmt\ClassMethod $node,
        ?Doc $doc,
        array $declaredTemplates,
        string $currentFile,
        ?string $currentNamespace = null
    ): array {
        if ($node->stmts === null || $node->stmts === [] || $node->params === []) {
            return [];
        }

        if (Config::getOnViolation() !== 'throw' || ! Config::isParamsEnabled()) {
            return [];
        }

        $candidateNames = [];
        foreach ($node->params as $p) {
            if (! $p->byRef && $p->var instanceof Node\Expr\Variable && \is_string($p->var->name)) {
                $candidateNames[$p->var->name] = true;
            }
        }

        if ($candidateNames === []) {
            return [];
        }

        $unmutatedNames = self::findUnmutatedVariables($node->stmts, $candidateNames);
        if ($unmutatedNames === []) {
            return [];
        }

        $paramTags = [];
        if ($doc !== null) {
            try {
                $phpDocNode = DocblockExtractor::parseDocString($doc->getText());
                $paramTags = DocblockExtractor::getParamTags($phpDocNode);
            } catch (\Throwable) {
            }
        }

        $trustedTypes = [];
        foreach ($node->params as $p) {
            if (! ($p->var instanceof Node\Expr\Variable) || ! \is_string($p->var->name)) {
                continue;
            }

            $pName = $p->var->name;
            if (! isset($unmutatedNames[$pName])) {
                continue;
            }

            if (
                Config::isRespectNativeNullabilityEnabled()
                && self::parameterAllowsNullNatively($p)
            ) {
                continue;
            }

            $typeNode = null;
            if (isset($paramTags[$pName])) {
                $typeNode = $paramTags[$pName]->type;
            } elseif ($p->type !== null) {
                if ($p->type instanceof Node\Identifier) {
                    $typeNode = new IdentifierTypeNode($p->type->name);
                } elseif ($p->type instanceof Node\NullableType && $p->type->type instanceof Node\Identifier) {
                    $typeNode = new NullableTypeNode(new IdentifierTypeNode($p->type->type->name));
                }
            }

            if ($typeNode === null) {
                continue;
            }

            if ($currentFile !== '') {
                $typeNode = SpecialTypeResolver::resolveForFile($typeNode, $currentFile, $currentNamespace, false);
            }

            if ($declaredTemplates !== [] && DocblockParser::typeReferencesTemplate($typeNode, $declaredTemplates)) {
                continue;
            }

            if ($typeNode instanceof IdentifierTypeNode && strtolower($typeNode->name) === 'mixed') {
                continue;
            }

            $trustedTypes[$pName] = $typeNode;
        }

        return $trustedTypes;
    }

    private static function parameterAllowsNullNatively(Node\Param $param): bool
    {
        if ($param->default instanceof Node\Expr\ConstFetch && strtolower($param->default->name->toString()) === 'null') {
            return true;
        }

        $type = $param->type;
        if ($type instanceof Node\NullableType) {
            return true;
        }

        if ($type instanceof Node\Identifier && strtolower($type->name) === 'null') {
            return true;
        }

        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $t) {
                if ($t instanceof Node\Identifier && strtolower($t->name) === 'null') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Traverses statements to identify parameters that are never mutated or passed by reference.
     *
     * @param array<Node\Stmt> $stmts
     * @param array<string, true> $candidateNames
     *
     * @return array<string, true>
     */
    private static function findUnmutatedVariables(array $stmts, array $candidateNames): array
    {
        $unmutated = $candidateNames;

        $visitor = new class ($unmutated) extends NodeVisitorAbstract {
            /**
             * @param array<string, true> $unmutated
             */
            public function __construct(public array &$unmutated)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($this->unmutated === []) {
                    return NodeTraverser::STOP_TRAVERSAL;
                }

                if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp) {
                    $this->disqualifyTarget($node->var);
                } elseif ($node instanceof Node\Expr\AssignRef) {
                    $this->disqualifyTarget($node->var);
                    $this->disqualifyTarget($node->expr);
                } elseif (
                    $node instanceof Node\Expr\PreInc
                    || $node instanceof Node\Expr\PostInc
                    || $node instanceof Node\Expr\PreDec
                    || $node instanceof Node\Expr\PostDec
                ) {
                    $this->disqualifyTarget($node->var);
                } elseif ($node instanceof Node\Stmt\Unset_) {
                    foreach ($node->vars as $v) {
                        $this->disqualifyTarget($v);
                    }
                } elseif ($node instanceof Node\Stmt\Foreach_) {
                    $this->disqualifyTarget($node->valueVar);
                    if ($node->keyVar !== null) {
                        $this->disqualifyTarget($node->keyVar);
                    }
                } elseif ($node instanceof Node\Stmt\Catch_) {
                    if ($node->var !== null && \is_string($node->var->name)) {
                        unset($this->unmutated[$node->var->name]);
                    }
                } elseif ($node instanceof Node\Stmt\Static_) {
                    foreach ($node->vars as $sv) {
                        if (\is_string($sv->var->name)) {
                            unset($this->unmutated[$sv->var->name]);
                        }
                    }
                } elseif ($node instanceof Node\Stmt\Global_) {
                    foreach ($node->vars as $gv) {
                        $this->disqualifyTarget($gv);
                    }
                } elseif ($node instanceof Node\ArrayItem && $node->byRef) {
                    $this->disqualifyTarget($node->value);
                } elseif ($node instanceof Node\Expr\Closure) {
                    foreach ($node->uses as $u) {
                        if ($u->byRef && $u->var instanceof Node\Expr\Variable && \is_string($u->var->name)) {
                            unset($this->unmutated[$u->var->name]);
                        }
                    }
                } elseif ($node instanceof Node\Expr\CallLike) {
                    if (
                        $node instanceof Node\Expr\FuncCall
                        && $node->name instanceof Node\Name
                        && strtolower($node->name->getLast()) === 'extract'
                    ) {
                        $this->unmutated = [];

                        return NodeTraverser::STOP_TRAVERSAL;
                    }

                    if (! $node->isFirstClassCallable()) {
                        foreach ($node->getArgs() as $arg) {
                            if ($arg instanceof Node\Arg && $arg->value instanceof Node\Expr\Variable && \is_string($arg->value->name)) {
                                unset($this->unmutated[$arg->value->name]);
                            }
                        }
                    }
                }

                return null;
            }

            private function disqualifyTarget(Node $target): void
            {
                while ($target instanceof Node\Expr\ArrayDimFetch || $target instanceof Node\Expr\PropertyFetch) {
                    $target = $target->var;
                }

                if ($target instanceof Node\Expr\Variable) {
                    if (\is_string($target->name)) {
                        unset($this->unmutated[$target->name]);
                    } else {
                        $this->unmutated = [];
                    }
                } elseif ($target instanceof Node\Expr\Array_ || $target instanceof Node\Expr\List_) {
                    foreach ($target->items as $item) {
                        if ($item !== null) {
                            $this->disqualifyTarget($item->value);
                        }
                    }
                }
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $visitor->unmutated;
    }

    public static function buildSelfOutCheckStmt(
        Node\Expr $thisArg,
        bool $needsReturnVars = false,
        bool $useDynamicMethodKey = false
    ): Node\Stmt\If_ {
        $varsArg = $needsReturnVars
            ? new Node\Expr\Variable('_typephpArgs')
            : new Node\Expr\Array_();

        $checkCall = new Node\Expr\FuncCall(
            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::checkSelfOut'),
            [
                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                new Node\Arg($thisArg),
                new Node\Arg($varsArg),
            ]
        );

        $cacheKeyExpr = $useDynamicMethodKey
            ? new Node\Expr\BinaryOp\Concat(
                new Node\Expr\BinaryOp\Concat(
                    new Node\Expr\ClassConstFetch(new Node\Name('static'), 'class'),
                    new Node\Scalar\String_('::')
                ),
                new Node\Scalar\MagicConst\Function_()
            )
            : new Node\Scalar\MagicConst\Method();

        $noSelfOutFetch = new Node\Expr\StaticPropertyFetch(
            new Node\Name\FullyQualified('TypePHP\Internal\Checker\SelfOutChecker'),
            'noSelfOutContractCache'
        );
        $noSelfOutFetch->setAttribute('typephp_checked', true);

        $ifStmt = new Node\Stmt\If_(
            new Node\Expr\BooleanNot(
                new Node\Expr\Isset_([
                    new Node\Expr\ArrayDimFetch($noSelfOutFetch, $cacheKeyExpr),
                ])
            ),
            ['stmts' => [new Node\Stmt\Expression($checkCall)]]
        );
        $ifStmt->setAttribute('typephp_injected', true);

        return $ifStmt;
    }

    public static function buildGuardedTernaryReturnExpr(
        Node\Expr $exprToWrap,
        Node\Expr $thisArg,
        bool $needsReturnVars = false,
        bool $useDynamicMethodKey = false,
        ?int $line = null
    ): Node\Expr\Ternary {
        $cacheKeyExpr = $useDynamicMethodKey
            ? new Node\Expr\BinaryOp\Concat(
                new Node\Expr\BinaryOp\Concat(
                    new Node\Expr\ClassConstFetch(new Node\Name('static'), 'class'),
                    new Node\Scalar\String_('::')
                ),
                new Node\Scalar\MagicConst\Function_()
            )
            : new Node\Scalar\MagicConst\Method();

        $noReturnFetch = new Node\Expr\StaticPropertyFetch(
            new Node\Name\FullyQualified('TypePHP\Internal\Checker\ReturnChecker'),
            'noReturnContractCache'
        );
        $noReturnFetch->setAttribute('typephp_checked', true);

        $retVar = new Node\Expr\Variable('__typephpRet');
        $assignAndFalse = new Node\Expr\BinaryOp\BooleanAnd(
            new Node\Expr\Assign($retVar, $exprToWrap),
            new Node\Expr\ConstFetch(new Node\Name('false'))
        );

        $fastSkipCond = new Node\Expr\BinaryOp\BooleanOr(
            $assignAndFalse,
            new Node\Expr\Isset_([new Node\Expr\ArrayDimFetch($noReturnFetch, $cacheKeyExpr)])
        );

        $checkCall = self::buildReturnCheckCall(new Node\Expr\Variable('__typephpRet'), $thisArg, $needsReturnVars);
        $slowPath = self::buildTernaryReturnExpr($checkCall, $line);

        return new Node\Expr\Ternary($fastSkipCond, new Node\Expr\Variable('__typephpRet'), $slowPath);
    }

    /**
     * @param array{name?: ?string, hasInheritance?: bool, hasRealInheritance?: bool, hasPropertyWithDoc?: bool, isReadonly?: bool, isFinal?: bool, hasTemplates?: bool}|null $classContext
     */
    private static function resolveDocComment(
        Node\Stmt\Function_|Node\Stmt\ClassMethod $node,
        ?array $classContext = null,
    ): ?Doc {
        $doc = $node->getDocComment();
        if ($doc !== null) {
            return $doc;
        }

        if ($node->attrGroups !== []) {
            foreach ($node->attrGroups as $group) {
                $groupDoc = $group->getDocComment();
                if ($groupDoc !== null) {
                    return $groupDoc;
                }
            }
        }

        $className = $classContext['name'] ?? null;
        if ($node instanceof Node\Stmt\ClassMethod && $className !== null) {
            $methodName = $node->name->toString();
            if (StubManager::hasMethodStub($className, $methodName)) {
                $stubText = StubManager::getMethodDoc($className, $methodName);
                if ($stubText !== null) {
                    return new Doc($stubText);
                }
            }
        } elseif ($node instanceof Node\Stmt\Function_) {
            $funcName = $node->name->toString();
            if (StubManager::hasFunctionStub($funcName)) {
                $stubText = StubManager::getFunctionDoc($funcName);
                if ($stubText !== null) {
                    return new Doc($stubText);
                }
            }
        }

        return null;
    }

    private static function hasParamContracts(
        string $docText,
        bool $isClassMethod,
        bool $hasInheritance,
        int $paramCount,
        bool $isPrivate,
        bool $isConstructor,
        bool $hasPropertyWithDoc,
        bool $isDirectUnitTest,
        bool $hasAttributes = false
    ): bool {
        if ($paramCount === 0 && ! str_contains($docText, '@template')) {
            return $isDirectUnitTest && $isClassMethod;
        }

        if ($isDirectUnitTest && $isClassMethod) {
            return true;
        }

        if ($isConstructor && $docText === '' && ! $hasPropertyWithDoc) {
            return false;
        }

        if ($isPrivate && $docText === '' && ! ($isConstructor && $hasPropertyWithDoc)) {
            return false;
        }

        if (! $hasInheritance && $docText === '' && ! ($isConstructor && $hasPropertyWithDoc) && ! $hasAttributes) {
            return false;
        }

        if (str_contains($docText, '@template') || str_contains($docText, '@phpstan-param') || str_contains($docText, '@psalm-param')) {
            return true;
        }

        if (str_contains($docText, '@param')) {
            return self::hasNonMixedParam($docText);
        }

        return $isClassMethod && (! $isPrivate || ($isConstructor && $hasPropertyWithDoc));
    }

    private static function hasNonMixedParam(string $docText): bool
    {
        if ((int) preg_match_all('/@param\s+([^\s$]+)/', $docText, $matches) === 0) {
            return false;
        }

        foreach ($matches[1] as $typeStr) {
            $unionParts = explode('|', $typeStr);
            $hasMixed = false;
            foreach ($unionParts as $part) {
                if (strtolower(trim($part)) === 'mixed') {
                    $hasMixed = true;

                    break;
                }
            }
            if (! $hasMixed) {
                return true;
            }
        }

        return false;
    }

    private static function hasReturnContracts(
        string $docText,
        bool $isClassMethod,
        bool $hasInheritance,
        bool $isPrivate,
        bool $isDirectUnitTest = false,
    ): bool {
        if ($isDirectUnitTest && $isClassMethod) {
            return true;
        }

        if ($isPrivate && $docText === '') {
            return false;
        }

        if (
            str_contains($docText, '@template')
            || str_contains($docText, '@phpstan-return')
            || str_contains($docText, '@psalm-return')
            || str_contains($docText, '$this')
        ) {
            return true;
        }

        if (preg_match('/@return\s+([^\s]+)/', $docText, $matches) === 1) {
            $returnTypeStr = $matches[1];
            $unionParts = explode('|', $returnTypeStr);
            foreach ($unionParts as $part) {
                if (strtolower(trim($part)) === 'mixed') {
                    return false;
                }
            }

            return true;
        }

        if (! $isClassMethod) {
            return false;
        }

        if (! $hasInheritance) {
            return false;
        }

        return ! $isPrivate;
    }

    private static function resolveThisArg(bool $isClassMethod, Node\Stmt\Function_|Node\Stmt\ClassMethod $node): Node\Expr
    {
        if (! $isClassMethod) {
            return new Node\Expr\ConstFetch(new Node\Name('null'));
        }

        /** @var Node\Stmt\ClassMethod $node */
        return $node->isStatic()
            ? new Node\Expr\ClassConstFetch(new Node\Name('static'), 'class')
            : new Node\Expr\Variable('this');
    }

    private static function isGenerator(Node\Stmt\Function_|Node\Stmt\ClassMethod $node): bool
    {
        $visitor = new class () extends NodeVisitorAbstract {
            public bool $isGen = false;

            public function enterNode(Node $n): ?int
            {
                if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($n instanceof Node\Expr\Yield_ || $n instanceof Node\Expr\YieldFrom) {
                    $this->isGen = true;

                    return NodeTraverser::STOP_TRAVERSAL;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        /** @var array<Node\Stmt> $stmts */
        $stmts = $node->stmts;
        $traverser->traverse($stmts);

        return $visitor->isGen;
    }

    /**
     * @param array<Node\Param> $params
     * @param array<string, TypeNode> $resolvedParamTypes
     * @param array<string, TemplateTagValueNode> $effectiveTemplates
     *
     * @return array<Node\Stmt>
     */
    private static function buildParamInjections(
        array $params,
        string $docText,
        Node\Expr $thisArg,
        bool $isReadonlyClass = false,
        bool $needsReturnVars = false,
        bool $useDynamicMethodKey = false,
        array $resolvedParamTypes = [],
        array $effectiveTemplates = []
    ): array {
        $setupScopeStmts = self::buildSetupScopeStmt($params, $thisArg, $needsReturnVars, $useDynamicMethodKey);
        $callableWrappers = self::buildParamWrappers($params, $docText, $thisArg, [self::class, 'isCallableCandidate'], 'wrapCallable', $isReadonlyClass, $resolvedParamTypes, $effectiveTemplates);
        $iterableWrappers = self::buildParamWrappers($params, $docText, $thisArg, [self::class, 'isIterableCandidate'], 'wrapIterable', $isReadonlyClass, $resolvedParamTypes, $effectiveTemplates);

        return [...$setupScopeStmts, ...$callableWrappers, ...$iterableWrappers];
    }

    /**
     * @param array<Node\Param> $params
     *
     * @return array<Node\Stmt>
     */
    private static function buildSetupScopeStmt(
        array $params,
        Node\Expr $thisArg,
        bool $needsReturnVars = false,
        bool $useDynamicMethodKey = false
    ): array {
        $arrayItems = [];
        foreach ($params as $param) {
            if ($param->var instanceof Node\Expr\Variable && \is_string($param->var->name)) {
                $pName = $param->var->name;
                $arrayItems[] = new Node\ArrayItem(
                    new Node\Expr\Variable($pName),
                    new Node\Scalar\String_($pName)
                );
            }
        }

        $argsAssign = new Node\Stmt\Expression(
            new Node\Expr\Assign(
                new Node\Expr\Variable('_typephpArgs'),
                new Node\Expr\Array_($arrayItems)
            )
        );
        $argsAssign->setAttribute('typephp_injected', true);

        $checkCall = new Node\Expr\FuncCall(
            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::setupScope'),
            [
                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                new Node\Arg(new Node\Expr\Variable('_typephpArgs')),
                new Node\Arg($thisArg),
            ]
        );

        $throwStmt = self::buildTypeErrorThrowStmt(new Node\Expr\Variable('__typephpScope'));
        $cacheKeyExpr = $useDynamicMethodKey
            ? new Node\Expr\BinaryOp\Concat(
                new Node\Expr\BinaryOp\Concat(
                    new Node\Expr\ClassConstFetch(new Node\Name('static'), 'class'),
                    new Node\Scalar\String_('::')
                ),
                new Node\Scalar\MagicConst\Function_()
            )
            : new Node\Scalar\MagicConst\Method();

        $noParamFetch = new Node\Expr\StaticPropertyFetch(
            new Node\Name\FullyQualified('TypePHP\Internal\Checker\ParamChecker'),
            'noParamContractCache'
        );
        $noParamFetch->setAttribute('typephp_checked', true);

        $noParamCacheCheck = new Node\Expr\BooleanNot(
            new Node\Expr\Isset_([
                new Node\Expr\ArrayDimFetch(
                    $noParamFetch,
                    $cacheKeyExpr
                ),
            ])
        );

        $hasTemplatesFetch = new Node\Expr\StaticPropertyFetch(
            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker'),
            'hasMethodTemplatesCache'
        );
        $hasTemplatesFetch->setAttribute('typephp_checked', true);

        $hasTemplatesCheck = new Node\Expr\BinaryOp\Coalesce(
            new Node\Expr\ArrayDimFetch(
                $hasTemplatesFetch,
                $cacheKeyExpr
            ),
            new Node\Expr\ConstFetch(new Node\Name('false'))
        );

        $combinedCondition = new Node\Expr\BinaryOp\BooleanOr($noParamCacheCheck, $hasTemplatesCheck);

        $checkIfStmt = new Node\Stmt\If_(
            $combinedCondition,
            [
                'stmts' => [
                    new Node\Stmt\If_(
                        new Node\Expr\Instanceof_(
                            new Node\Expr\Assign(new Node\Expr\Variable('__typephpScope'), $checkCall),
                            new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
                        ),
                        ['stmts' => [$throwStmt]]
                    ),
                ],
            ]
        );
        $checkIfStmt->setAttribute('typephp_injected', true);

        if ($needsReturnVars) {
            return [$argsAssign, $checkIfStmt];
        }

        $checkIfStmt->stmts = [$argsAssign, ...$checkIfStmt->stmts];

        return [$checkIfStmt];
    }

    /**
     * @param array<string> $byRefParamNames
     *
     * @return array<Node\Stmt>
     */
    public static function buildParamOutCheckStmts(
        array $byRefParamNames,
        Node\Expr $thisArg,
        bool $needsReturnVars = false
    ): array {
        $varsArg = $needsReturnVars
            ? new Node\Expr\Variable('_typephpArgs')
            : new Node\Expr\Array_();

        $stmts = [];
        foreach ($byRefParamNames as $pName) {
            $checkCall = new Node\Expr\FuncCall(
                new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::checkParamOut'),
                [
                    new Node\Arg(new Node\Scalar\MagicConst\Method()),
                    new Node\Arg(new Node\Scalar\String_($pName)),
                    new Node\Arg(new Node\Expr\Variable($pName)),
                    new Node\Arg($thisArg),
                    new Node\Arg($varsArg),
                ]
            );

            $ifStmt = new Node\Stmt\If_(
                new Node\Expr\Instanceof_(
                    new Node\Expr\Assign(new Node\Expr\Variable('__typephpErr'), $checkCall),
                    new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
                ),
                ['stmts' => [self::buildTypeErrorThrowStmt(new Node\Expr\Variable('__typephpErr'))]]
            );
            $ifStmt->setAttribute('typephp_injected', true);
            $stmts[] = $ifStmt;
        }

        return $stmts;
    }

    /**
     * @param array<Node\Param> $params
     * @param callable(Node\Param, string, ?TypeNode, array<string, TemplateTagValueNode>): bool $predicate
     * @param array<string, TypeNode> $resolvedParamTypes
     * @param array<string, TemplateTagValueNode> $effectiveTemplates
     *
     * @return array<Node\Stmt>
     */
    private static function buildParamWrappers(
        array $params,
        string $docText,
        Node\Expr $thisArg,
        callable $predicate,
        string $wrapperMethod,
        bool $isReadonlyClass = false,
        array $resolvedParamTypes = [],
        array $effectiveTemplates = []
    ): array {
        $wrappers = [];

        foreach ($params as $param) {
            $paramName = $param->var instanceof Node\Expr\Variable && \is_string($param->var->name) ? $param->var->name : '';
            $resolvedType = $paramName !== '' ? ($resolvedParamTypes[$paramName] ?? null) : null;
            $paramDoc = $param->getDocComment() !== null ? $param->getDocComment()->getText() : '';
            $effectiveDoc = $docText . ' ' . $paramDoc;

            if ($paramName !== '' && $predicate($param, $effectiveDoc, $resolvedType, $effectiveTemplates)) {
                $assignExpr = new Node\Expr\Assign(
                    new Node\Expr\Variable($paramName),
                    new Node\Expr\FuncCall(
                        new Node\Name\FullyQualified("TypePHP\Internal\RuntimeTypeChecker::{$wrapperMethod}"),
                        [
                            new Node\Arg(new Node\Scalar\MagicConst\Method()),
                            new Node\Arg(new Node\Scalar\String_($paramName)),
                            new Node\Arg(new Node\Expr\Variable($paramName)),
                            new Node\Arg($thisArg),
                        ]
                    )
                );

                $expr = new Node\Stmt\Expression($assignExpr);
                $expr->setAttribute('typephp_injected', true);

                $bodyStmts = [$expr];

                $isReadonlyParam = ($param->flags & Node\Stmt\Class_::MODIFIER_READONLY) !== 0;
                $isReadonly = $isReadonlyParam || $isReadonlyClass;

                if ($param->isPromoted() && ! $isReadonly) {
                    $propAssign = new Node\Stmt\Expression(
                        new Node\Expr\Assign(
                            new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $paramName),
                            new Node\Expr\Variable($paramName)
                        )
                    );
                    $propAssign->setAttribute('typephp_injected', true);
                    $bodyStmts[] = $propAssign;
                }

                $guardCond = null;
                if ($wrapperMethod === 'wrapIterable' && self::paramMayBeArray($param)) {
                    $guardCond = new Node\Expr\Instanceof_(
                        new Node\Expr\Variable($paramName),
                        new Node\Name\FullyQualified('Traversable')
                    );
                } elseif ($wrapperMethod === 'wrapCallable' && self::paramMayBeNull($param)) {
                    $guardCond = new Node\Expr\BinaryOp\NotIdentical(
                        new Node\Expr\Variable($paramName),
                        new Node\Expr\ConstFetch(new Node\Name('null'))
                    );
                }

                if ($guardCond !== null) {
                    $ifStmt = new Node\Stmt\If_($guardCond, ['stmts' => $bodyStmts]);
                    $ifStmt->setAttribute('typephp_injected', true);
                    $wrappers[] = $ifStmt;
                } else {
                    foreach ($bodyStmts as $stmt) {
                        $wrappers[] = $stmt;
                    }
                }
            }
        }

        return $wrappers;
    }

    private static function paramMayBeNull(Node\Param $param): bool
    {
        if ($param->default instanceof Node\Expr\ConstFetch && strtolower($param->default->name->toString()) === 'null') {
            return true;
        }

        $type = $param->type;
        if ($type === null || $type instanceof Node\NullableType) {
            return true;
        }

        if ($type instanceof Node\Identifier) {
            return \in_array(strtolower($type->name), ['mixed', 'null'], true);
        }

        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $t) {
                if ($t instanceof Node\Identifier && \in_array(strtolower($t->name), ['mixed', 'null'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function paramMayBeArray(Node\Param $param): bool
    {
        $type = $param->type;
        if ($type === null) {
            return true;
        }

        while ($type instanceof Node\NullableType) {
            $type = $type->type;
        }

        if ($type instanceof Node\Identifier) {
            return \in_array(strtolower($type->name), ['array', 'iterable', 'mixed'], true);
        }

        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $t) {
                if ($t instanceof Node\Identifier && \in_array(strtolower($t->name), ['array', 'iterable', 'mixed'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, TemplateTagValueNode> $effectiveTemplates
     */
    private static function isCallableCandidate(
        Node\Param $param,
        string $docText,
        ?TypeNode $resolvedParamType = null,
        array $effectiveTemplates = []
    ): bool {
        if (self::typeMatchesName($param->type, self::CALLABLE_TYPES)) {
            return true;
        }

        if ($resolvedParamType !== null) {
            return self::typeNodeContainsCallable($resolvedParamType, $effectiveTemplates);
        }

        return str_contains($docText, 'callable')
            || str_contains($docText, 'Closure')
            || str_contains($docText, 'pure-callable')
            || str_contains($docText, 'static-closure');
    }

    /**
     * @param array<string, TemplateTagValueNode> $effectiveTemplates
     */
    private static function isIterableCandidate(
        Node\Param $param,
        string $docText,
        ?TypeNode $resolvedParamType = null,
        array $effectiveTemplates = []
    ): bool {
        if (self::typeMatchesName($param->type, self::ITERABLE_TYPES)) {
            return true;
        }

        if ($resolvedParamType !== null) {
            return self::typeNodeContainsIterable($resolvedParamType, $effectiveTemplates);
        }

        $paramName = $param->var instanceof Node\Expr\Variable && \is_string($param->var->name) ? $param->var->name : '';
        if ($paramName !== '' && preg_match('/@(?:param|phpstan-param|psalm-param)\s+[^\$]*?\b(?:iterable|Traversable|Generator|Iterator|IteratorAggregate)\b[^\$]*?\$' . preg_quote($paramName, '/') . '\b/i', $docText) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, true> $visited
     */
    private static function typeNodeContainsCallable(TypeNode $node, array $templates, array $visited = []): bool
    {
        while ($node instanceof NullableTypeNode || $node instanceof ArrayTypeNode) {
            $node = $node->type;
        }

        if ($node instanceof CallableTypeNode) {
            return true;
        }

        if ($node instanceof IdentifierTypeNode) {
            $lower = strtolower(ltrim($node->name, '\\'));
            if (\in_array($lower, ['callable', 'closure', 'pure-callable', 'static-closure', 'static-pure-closure'], true)) {
                return true;
            }

            if (isset($templates[$node->name]) && ! isset($visited[$node->name]) && $templates[$node->name]->bound !== null) {
                $visited[$node->name] = true;

                return self::typeNodeContainsCallable($templates[$node->name]->bound, $templates, $visited);
            }

            return false;
        }

        if ($node instanceof GenericTypeNode) {
            if (self::typeNodeContainsCallable($node->type, $templates, $visited)) {
                return true;
            }
            foreach ($node->genericTypes as $gt) {
                if (self::typeNodeContainsCallable($gt, $templates, $visited)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) {
            foreach ($node->types as $member) {
                if (self::typeNodeContainsCallable($member, $templates, $visited)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof ConditionalTypeNode || $node instanceof ConditionalTypeForParameterNode) {
            return self::typeNodeContainsCallable($node->if, $templates, $visited)
                || self::typeNodeContainsCallable($node->else, $templates, $visited);
        }

        return false;
    }

    /**
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, true> $visited
     */
    private static function typeNodeContainsIterable(TypeNode $node, array $templates, array $visited = []): bool
    {
        while ($node instanceof NullableTypeNode) {
            $node = $node->type;
        }

        if ($node instanceof IdentifierTypeNode) {
            $lower = strtolower(ltrim($node->name, '\\'));
            if (isset(self::ITERABLE_TYPES[$lower])) {
                return true;
            }

            if (isset($templates[$node->name]) && ! isset($visited[$node->name]) && $templates[$node->name]->bound !== null) {
                $visited[$node->name] = true;

                return self::typeNodeContainsIterable($templates[$node->name]->bound, $templates, $visited);
            }

            return false;
        }

        if ($node instanceof GenericTypeNode) {
            return self::typeNodeContainsIterable($node->type, $templates, $visited);
        }

        if ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) {
            foreach ($node->types as $member) {
                if (self::typeNodeContainsIterable($member, $templates, $visited)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof ConditionalTypeNode || $node instanceof ConditionalTypeForParameterNode) {
            return self::typeNodeContainsIterable($node->if, $templates, $visited)
                || self::typeNodeContainsIterable($node->else, $templates, $visited);
        }

        return false;
    }

    /**
     * @param array<string, TypeNode> $baseAliases
     *
     * @return array<string, TypeNode>
     */
    private static function mergeDocblockAliases(
        PhpDocNode $phpDocNode,
        array $baseAliases,
        string $currentFile,
        ?string $currentNamespace
    ): array {
        $aliases = $baseAliases;

        foreach ($phpDocNode->getTagsByName('@psalm-type') as $tag) {
            if ($tag->value instanceof TypeAliasTagValueNode) {
                $aliases[$tag->value->alias] = $tag->value->type;
            }
        }

        foreach ($phpDocNode->getTagsByName('@phpstan-type') as $tag) {
            if ($tag->value instanceof TypeAliasTagValueNode) {
                $aliases[$tag->value->alias] = $tag->value->type;
            }
        }

        $importTags = [
            ...$phpDocNode->getTagsByName('@psalm-import-type'),
            ...$phpDocNode->getTagsByName('@phpstan-import-type'),
        ];

        foreach ($importTags as $tag) {
            if ($tag->value instanceof TypeAliasImportTagValueNode) {
                $importTag = $tag->value;
                $localName = $importTag->importedAs ?? $importTag->importedAlias;
                $sourceName = (string) $importTag->importedFrom;
                $sourceFqcn = $currentFile !== ''
                    ? SpecialTypeResolver::resolveFqcnForFile($sourceName, $currentFile, true, $currentNamespace)
                    : $sourceName;

                if (
                    ClassNameValidator::isValid($sourceFqcn)
                    && (class_exists($sourceFqcn) || interface_exists($sourceFqcn) || trait_exists($sourceFqcn) || enum_exists($sourceFqcn))
                ) {
                    $imported = DocblockExtractor::resolveImportedTypeAlias($sourceFqcn, $importTag->importedAlias);
                    if ($imported !== null) {
                        $aliases[$localName] = $imported;
                    }
                }
            }
        }

        foreach ($aliases as $name => $type) {
            $aliases[$name] = DocblockParser::substituteAliases($type, $aliases);
        }

        return $aliases;
    }

    /**
     * @param array<string, true> $targetNames
     */
    private static function typeMatchesName(Node\Identifier|Node\Name|Node\ComplexType|null $type, array $targetNames): bool
    {
        while ($type instanceof Node\NullableType) {
            $type = $type->type;
        }

        if ($type instanceof Node\Identifier) {
            return isset($targetNames[strtolower($type->name)]);
        }
        if ($type instanceof Node\Name) {
            return isset($targetNames[strtolower($type->getLast())]);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $t) {
                if (self::typeMatchesName($t, $targetNames)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function buildTypeErrorThrowExpr(Node\Expr $errorVar, ?int $line = null): Node\Expr\Throw_
    {
        $args = [
            new Node\Arg(
                new Node\Expr\New_(
                    new Node\Name\FullyQualified('TypePHP\Exception\TypeError'),
                    [
                        new Node\Arg(
                            new Node\Expr\MethodCall($errorVar, 'getMessage')
                        ),
                    ]
                )
            ),
        ];

        if ($line !== null) {
            $args[] = new Node\Arg(new Node\Scalar\LNumber($line));
        }

        return new Node\Expr\Throw_(
            new Node\Expr\StaticCall(
                new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorFactory'),
                'prepareException',
                $args
            )
        );
    }

    public static function buildTypeErrorThrowStmt(Node\Expr $errorVar, ?int $line = null): Node\Stmt\Expression
    {
        return new Node\Stmt\Expression(self::buildTypeErrorThrowExpr($errorVar, $line));
    }

    public static function buildReturnCheckCall(Node\Expr $exprToWrap, Node\Expr $thisArg, bool $needsReturnVars = false): Node\Expr\FuncCall
    {
        $varsArg = $needsReturnVars
            ? new Node\Expr\Variable('_typephpArgs')
            : new Node\Expr\Array_();

        return new Node\Expr\FuncCall(
            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::checkReturn'),
            [
                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                new Node\Arg($exprToWrap),
                new Node\Arg($thisArg),
                new Node\Arg($varsArg),
            ]
        );
    }

    /**
     * @return array<Node\Stmt>
     */
    public static function buildVoidReturnGuard(Node\Expr\FuncCall $checkCall): array
    {
        $ifStmt = new Node\Stmt\If_(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(new Node\Expr\Variable('__typephpRet'), $checkCall),
                new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
            ),
            ['stmts' => [self::buildTypeErrorThrowStmt(new Node\Expr\Variable('__typephpRet'))]]
        );
        $ifStmt->setAttribute('typephp_injected', true);

        $retStmt = new Node\Stmt\Return_(null);
        $retStmt->setAttribute('typephp_injected', true);

        return [$ifStmt, $retStmt];
    }

    /**
     * @return array<Node\Stmt>
     */
    public static function buildByRefReturnGuard(Node\Expr\FuncCall $checkCall, Node\Stmt\Return_ $returnStmt, ?int $line = null): array
    {
        $ifStmt = new Node\Stmt\If_(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(new Node\Expr\Variable('__typephpRet'), $checkCall),
                new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
            ),
            ['stmts' => [self::buildTypeErrorThrowStmt(new Node\Expr\Variable('__typephpRet'), $line)]]
        );
        $ifStmt->setAttribute('typephp_injected', true);

        return [$ifStmt, $returnStmt];
    }

    public static function buildTernaryReturnExpr(Node\Expr\FuncCall $checkCall, ?int $line = null): Node\Expr\Ternary
    {
        return new Node\Expr\Ternary(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(new Node\Expr\Variable('__typephpRet'), $checkCall),
                new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
            ),
            self::buildTypeErrorThrowExpr(new Node\Expr\Variable('__typephpRet'), $line),
            new Node\Expr\Variable('__typephpRet')
        );
    }

    /**
     * @param array<string> $byRefParamNames
     */
    public static function buildParamOutGuardExpr(
        array $byRefParamNames,
        Node\Expr $innerExpr,
        Node\Expr $thisArg,
        ?int $line = null,
        bool $needsReturnVars = false
    ): Node\Expr {
        $varsArg = $needsReturnVars
            ? new Node\Expr\Variable('_typephpArgs')
            : new Node\Expr\Array_();

        $expr = $innerExpr;
        foreach (array_reverse($byRefParamNames) as $pName) {
            $checkCall = new Node\Expr\FuncCall(
                new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::checkParamOut'),
                [
                    new Node\Arg(new Node\Scalar\MagicConst\Method()),
                    new Node\Arg(new Node\Scalar\String_($pName)),
                    new Node\Arg(new Node\Expr\Variable($pName)),
                    new Node\Arg($thisArg),
                    new Node\Arg($varsArg),
                ]
            );

            $expr = new Node\Expr\Ternary(
                new Node\Expr\Instanceof_(
                    new Node\Expr\Assign(new Node\Expr\Variable('__typephpErr'), $checkCall),
                    new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
                ),
                self::buildTypeErrorThrowExpr(new Node\Expr\Variable('__typephpErr'), $line),
                $expr
            );
        }

        return $expr;
    }

    /**
     * @param array<string> $byRefParams
     */
    public static function buildWrappedYieldNode(
        Node\Expr\Yield_ $n,
        Node\Expr $thisArg,
        bool $wrapYield = true,
        bool $wrapSend = true,
        array $byRefParams = [],
        bool $needsReturnVars = false
    ): Node\Expr {
        $scopeArg = new Node\Expr\BinaryOp\Coalesce(
            new Node\Expr\Variable('__typephpScope'),
            new Node\Expr\ConstFetch(new Node\Name('null'))
        );

        if ($wrapYield) {
            $checkYieldCall = new Node\Expr\FuncCall(
                new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::checkYield'),
                [
                    new Node\Arg(new Node\Scalar\MagicConst\Method()),
                    new Node\Arg($n->key ?? new Node\Expr\ConstFetch(new Node\Name('null'))),
                    new Node\Arg($n->value ?? new Node\Expr\ConstFetch(new Node\Name('null'))),
                    new Node\Arg($thisArg),
                    new Node\Arg($scopeArg),
                ]
            );

            $n->value = new Node\Expr\Ternary(
                new Node\Expr\Instanceof_(
                    new Node\Expr\Assign(new Node\Expr\Variable('__typephpYld'), $checkYieldCall),
                    new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
                ),
                self::buildTypeErrorThrowExpr(new Node\Expr\Variable('__typephpYld'), $n->getStartLine()),
                new Node\Expr\Variable('__typephpYld')
            );
        }

        if ($byRefParams !== []) {
            $valExpr = $n->value ?? new Node\Expr\ConstFetch(new Node\Name('null'));
            $n->value = self::buildParamOutGuardExpr($byRefParams, $valExpr, $thisArg, $n->getStartLine(), $needsReturnVars);
        }

        if (! $wrapSend) {
            return $n;
        }

        $checkSendCall = new Node\Expr\FuncCall(
            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::checkSend'),
            [
                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                new Node\Arg($n),
                new Node\Arg($thisArg),
                new Node\Arg($scopeArg),
            ]
        );

        return new Node\Expr\Ternary(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(new Node\Expr\Variable('__typephpSnd'), $checkSendCall),
                new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
            ),
            self::buildTypeErrorThrowExpr(new Node\Expr\Variable('__typephpSnd')),
            new Node\Expr\Variable('__typephpSnd')
        );
    }

    /**
     * @param array<Node\Stmt> $stmts
     * @param array{name?: ?string, hasInheritance?: bool, hasRealInheritance?: bool, hasPropertyWithDoc?: bool, isReadonly?: bool, isFinal?: bool, hasTemplates?: bool}|null $classContext
     * @param array<string> $byRefParams
     * @param array<string, TypeNode> $trustedParamTypes
     *
     * @return array<Node\Stmt>
     */
    private static function wrapGeneratorReturns(
        array $stmts,
        Node\Expr $thisArg,
        Node\Stmt\Function_|Node\Stmt\ClassMethod|null $functionNode = null,
        ?array $classContext = null,
        ?TypeNode $returnTypeNode = null,
        string $currentFile = '',
        bool $hasMethodTemplates = false,
        bool $needsReturnVars = false,
        array $byRefParams = [],
        bool $hasSelfOut = false,
        bool $hasScope = false,
        array $trustedParamTypes = [],
        bool $useDynamicMethodKey = false,
        ?string $currentNamespace = null
    ): array {
        $canEliminate = $functionNode !== null
            && ! $hasMethodTemplates
            && ! ($classContext['hasTemplates'] ?? false)
            && EliminationSafetyPolicy::isGeneratorEliminationSafe($functionNode, $classContext, $returnTypeNode);

        $genContract = ($canEliminate && $returnTypeNode !== null)
            ? GeneratorEvaluator::extractContract($returnTypeNode)
            : null;

        $explicitGenContract = ($returnTypeNode !== null && Config::isEliminateStaticallyProvenChecksEnabled())
            ? GeneratorEvaluator::extractContract($returnTypeNode)
            : null;

        if ($genContract !== null) {
            self::tagPrecedingAssignments($stmts);
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class (
            $thisArg,
            $genContract,
            $explicitGenContract,
            $currentFile,
            $needsReturnVars,
            $byRefParams,
            $hasSelfOut,
            $trustedParamTypes,
            $useDynamicMethodKey,
            $currentNamespace
        ) extends NodeVisitorAbstract {
            /**
             * @param array{key: ?TypeNode, value: ?TypeNode, send: ?TypeNode, return: ?TypeNode}|null $genContract
             * @param array{key: ?TypeNode, value: ?TypeNode, send: ?TypeNode, return: ?TypeNode}|null $explicitGenContract
             * @param array<string> $byRefParams
             * @param array<string, TypeNode> $trustedParamTypes
             */
            public function __construct(
                private Node\Expr $thisArg,
                private ?array $genContract,
                private ?array $explicitGenContract,
                private string $currentFile,
                private bool $needsReturnVars,
                private array $byRefParams,
                private bool $hasSelfOut,
                private array $trustedParamTypes,
                private bool $useDynamicMethodKey,
                private ?string $currentNamespace
            ) {
            }

            public function enterNode(Node $n): int|Node|array|null
            {
                if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($this->genContract !== null) {
                    if (
                        $n instanceof Node\Stmt\If_
                        || $n instanceof Node\Stmt\Else_
                        || $n instanceof Node\Stmt\ElseIf_
                        || $n instanceof Node\Stmt\While_
                        || $n instanceof Node\Stmt\Do_
                        || $n instanceof Node\Stmt\For_
                        || $n instanceof Node\Stmt\Foreach_
                        || $n instanceof Node\Stmt\TryCatch
                        || $n instanceof Node\Stmt\Catch_
                        || $n instanceof Node\Stmt\Finally_
                        || $n instanceof Node\Stmt\Case_
                        || $n instanceof Node\Stmt\Block
                    ) {
                        FunctionContractInjector::tagPrecedingAssignments($n->stmts);
                    } elseif ($n instanceof Node\Stmt\Declare_ && $n->stmts !== null) {
                        FunctionContractInjector::tagPrecedingAssignments($n->stmts);
                    }
                }

                if ($n instanceof Node\Expr\Yield_) {
                    if ($n->getAttribute('typephp_wrapped') === true) {
                        return null;
                    }
                    $n->setAttribute('typephp_wrapped', true);

                    $assignedExpr = $n->getAttribute('typephp_preceding_assign_expr');
                    $precedingValueExpr = ($assignedExpr instanceof Node\Expr) ? $assignedExpr : null;

                    $wrapYield = $this->genContract === null
                        || ! GeneratorEvaluator::canEliminateYield(
                            $n,
                            $this->genContract['key'],
                            $this->genContract['value'],
                            $this->currentFile,
                            $precedingValueExpr,
                            $this->currentNamespace
                        );

                    $sendContract = $this->genContract ?? $this->explicitGenContract;
                    $wrapSend = $sendContract === null
                        || ! GeneratorEvaluator::canEliminateSend($sendContract['send']);

                    if (! $wrapYield && ! $wrapSend && $this->byRefParams === []) {
                        return null;
                    }

                    return FunctionContractInjector::buildWrappedYieldNode(
                        $n,
                        $this->thisArg,
                        $wrapYield,
                        $wrapSend,
                        $this->byRefParams,
                        $this->needsReturnVars
                    );
                }

                if ($n instanceof Node\Expr\YieldFrom) {
                    if ($n->getAttribute('typephp_wrapped') === true) {
                        return null;
                    }
                    $n->setAttribute('typephp_wrapped', true);

                    $canEliminateYieldFrom = $this->genContract !== null
                        && GeneratorEvaluator::canEliminateYieldFrom(
                            $n,
                            $this->genContract['key'],
                            $this->genContract['value'],
                            $this->currentFile,
                            $this->currentNamespace
                        );

                    if (! $canEliminateYieldFrom) {
                        $n->expr = new Node\Expr\FuncCall(
                            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::wrapIterable'),
                            [
                                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                                new Node\Arg(new Node\Scalar\String_('return')),
                                new Node\Arg($n->expr),
                                new Node\Arg($this->thisArg),
                            ]
                        );
                    }

                    if ($this->byRefParams !== []) {
                        $n->expr = FunctionContractInjector::buildParamOutGuardExpr(
                            $this->byRefParams,
                            $n->expr,
                            $this->thisArg,
                            $n->getStartLine(),
                            $this->needsReturnVars
                        );
                    }

                    return null;
                }

                if ($n instanceof Node\Stmt\Return_) {
                    if ($n->getAttribute('typephp_var_wrapped') === true || $n->getAttribute('typephp_generator_return_wrapped') === true) {
                        return null;
                    }
                    $n->setAttribute('typephp_generator_return_wrapped', true);

                    $exitStmts = [];
                    if ($this->hasSelfOut) {
                        $exitStmts[] = FunctionContractInjector::buildSelfOutCheckStmt($this->thisArg, $this->needsReturnVars, $this->useDynamicMethodKey);
                    }

                    $paramOutStmts = $this->byRefParams !== []
                        ? FunctionContractInjector::buildParamOutCheckStmts($this->byRefParams, $this->thisArg, $this->needsReturnVars)
                        : [];

                    if ($paramOutStmts !== []) {
                        $exitStmts = [...$exitStmts, ...$paramOutStmts];
                    }

                    $exprToWrap = $n->expr ?? new Node\Expr\ConstFetch(new Node\Name('null'));
                    $assignedExpr = $n->getAttribute('typephp_preceding_assign_expr');
                    $candidateExpr = ($assignedExpr instanceof Node\Expr) ? $assignedExpr : $exprToWrap;

                    if (
                        ($this->genContract !== null
                            && GeneratorEvaluator::canEliminateGeneratorReturn(
                                $candidateExpr,
                                $this->genContract['return'],
                                $this->currentFile,
                                $this->trustedParamTypes,
                                $this->currentNamespace
                            ))
                        || ($this->explicitGenContract !== null && $this->explicitGenContract['return'] === null)
                    ) {
                        return $exitStmts !== [] ? [...$exitStmts, $n] : $n;
                    }

                    $scopeArg = new Node\Expr\BinaryOp\Coalesce(
                        new Node\Expr\Variable('__typephpScope'),
                        new Node\Expr\ConstFetch(new Node\Name('null'))
                    );

                    $checkCall = new Node\Expr\FuncCall(
                        new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::checkGeneratorReturn'),
                        [
                            new Node\Arg(new Node\Scalar\MagicConst\Method()),
                            new Node\Arg($exprToWrap),
                            new Node\Arg($this->thisArg),
                            new Node\Arg($scopeArg),
                        ]
                    );

                    $n->expr = FunctionContractInjector::buildTernaryReturnExpr($checkCall, $n->getStartLine());

                    return $exitStmts !== [] ? [...$exitStmts, $n] : $n;
                }

                return null;
            }
        });

        /** @var array<Node\Stmt> $newStmts */
        $newStmts = $traverser->traverse($stmts);

        $lastStmt = end($newStmts);
        if (! $lastStmt instanceof Node\Stmt\Return_ && ! ($lastStmt instanceof Node\Stmt\Expression && $lastStmt->expr instanceof Node\Expr\Throw_)) {
            $exitStmts = [];
            if ($hasSelfOut) {
                $exitStmts[] = self::buildSelfOutCheckStmt($thisArg, $needsReturnVars, $useDynamicMethodKey);
            }

            $paramOutStmts = $byRefParams !== []
                ? self::buildParamOutCheckStmts($byRefParams, $thisArg, $needsReturnVars)
                : [];

            if ($paramOutStmts !== []) {
                $exitStmts = [...$exitStmts, ...$paramOutStmts];
            }

            if ($exitStmts !== []) {
                $newStmts = [...$newStmts, ...$exitStmts];
            }

            $canSkipImplicitGenReturn = ($genContract !== null
                && GeneratorEvaluator::canEliminateGeneratorReturn(null, $genContract['return'], $currentFile, $trustedParamTypes, $currentNamespace))
                || ($explicitGenContract !== null && $explicitGenContract['return'] === null);

            if (! $canSkipImplicitGenReturn) {
                $scopeArg = new Node\Expr\BinaryOp\Coalesce(
                    new Node\Expr\Variable('__typephpScope'),
                    new Node\Expr\ConstFetch(new Node\Name('null'))
                );

                $checkCall = new Node\Expr\FuncCall(
                    new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::checkGeneratorReturn'),
                    [
                        new Node\Arg(new Node\Scalar\MagicConst\Method()),
                        new Node\Arg(new Node\Expr\ConstFetch(new Node\Name('null'))),
                        new Node\Arg($thisArg),
                        new Node\Arg($scopeArg),
                    ]
                );

                $fallbackLine = $lastStmt instanceof Node\Stmt ? $lastStmt->getStartLine() : null;
                $ternaryExpr = self::buildTernaryReturnExpr($checkCall, $fallbackLine);

                $retStmt = new Node\Stmt\Return_($ternaryExpr);
                $retStmt->setAttribute('typephp_injected', true);
                $retStmt->setAttribute('typephp_generator_return_wrapped', true);
                $newStmts[] = $retStmt;
            }
        }

        return $newStmts;
    }

    /**
     * @param array<Node\Stmt> $stmts
     * @param array<string> $byRefParams
     * @param array{name?: ?string, hasInheritance?: bool, hasRealInheritance?: bool, hasPropertyWithDoc?: bool, isReadonly?: bool, isFinal?: bool, hasTemplates?: bool}|null $classContext
     * @param array<string, TypeNode> $trustedParamTypes
     *
     * @return array<Node\Stmt>
     */
    private static function wrapNonGeneratorReturns(
        array $stmts,
        Node\Expr $thisArg,
        bool $isNativeVoid,
        bool $needsReturnVars = false,
        bool $hasReturn = true,
        array $byRefParams = [],
        bool $hasSelfOut = false,
        bool $isByRefReturn = false,
        Node\Stmt\Function_|Node\Stmt\ClassMethod|null $functionNode = null,
        ?array $classContext = null,
        ?TypeNode $returnTypeNode = null,
        string $currentFile = '',
        array $trustedParamTypes = [],
        bool $useDynamicMethodKey = false,
        bool $canGuardReturnCache = true,
        ?string $currentNamespace = null
    ): array {
        self::tagPrecedingAssignments($stmts);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class (
            $thisArg,
            $isNativeVoid,
            $needsReturnVars,
            $hasReturn,
            $byRefParams,
            $hasSelfOut,
            $isByRefReturn,
            $functionNode,
            $classContext,
            $returnTypeNode,
            $currentFile,
            $trustedParamTypes,
            $useDynamicMethodKey,
            $canGuardReturnCache,
            $currentNamespace
        ) extends NodeVisitorAbstract {
            /**
             * @param array<string> $byRefParams
             * @param array{name?: ?string, hasInheritance?: bool, hasRealInheritance?: bool, hasPropertyWithDoc?: bool, isReadonly?: bool, isFinal?: bool, hasTemplates?: bool}|null $classContext
             * @param array<string, TypeNode> $trustedParamTypes
             */
            public function __construct(
                private Node\Expr $thisArg,
                private bool $isNativeVoid,
                private bool $needsReturnVars,
                private bool $hasReturn,
                private array $byRefParams,
                private bool $hasSelfOut,
                private bool $isByRefReturn,
                private Node\Stmt\Function_|Node\Stmt\ClassMethod|null $functionNode,
                private ?array $classContext,
                private ?TypeNode $returnTypeNode,
                private string $currentFile,
                private array $trustedParamTypes,
                private bool $useDynamicMethodKey,
                private bool $canGuardReturnCache,
                private ?string $currentNamespace
            ) {
            }

            public function enterNode(Node $n): int|array|null
            {
                if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if (
                    $n instanceof Node\Stmt\If_
                    || $n instanceof Node\Stmt\Else_
                    || $n instanceof Node\Stmt\ElseIf_
                    || $n instanceof Node\Stmt\While_
                    || $n instanceof Node\Stmt\Do_
                    || $n instanceof Node\Stmt\For_
                    || $n instanceof Node\Stmt\Foreach_
                    || $n instanceof Node\Stmt\TryCatch
                    || $n instanceof Node\Stmt\Catch_
                    || $n instanceof Node\Stmt\Finally_
                    || $n instanceof Node\Stmt\Case_
                    || $n instanceof Node\Stmt\Block
                ) {
                    FunctionContractInjector::tagPrecedingAssignments($n->stmts);
                } elseif ($n instanceof Node\Stmt\Declare_ && $n->stmts !== null) {
                    FunctionContractInjector::tagPrecedingAssignments($n->stmts);
                }

                if ($n instanceof Node\Stmt\Return_) {
                    if ($n->getAttribute('typephp_var_wrapped') === true) {
                        return null;
                    }

                    $exitStmts = [];
                    if ($this->hasSelfOut) {
                        $exitStmts[] = FunctionContractInjector::buildSelfOutCheckStmt($this->thisArg, $this->needsReturnVars, $this->useDynamicMethodKey);
                    }

                    $paramOutStmts = $this->byRefParams !== []
                        ? FunctionContractInjector::buildParamOutCheckStmts($this->byRefParams, $this->thisArg, $this->needsReturnVars)
                        : [];

                    if ($paramOutStmts !== []) {
                        $exitStmts = [...$exitStmts, ...$paramOutStmts];
                    }

                    if (! $this->hasReturn) {
                        return $exitStmts !== [] ? [...$exitStmts, $n] : null;
                    }

                    $exprToWrap = $n->expr ?? new Node\Expr\ConstFetch(new Node\Name('null'));

                    $assignedExpr = $n->getAttribute('typephp_preceding_assign_expr');
                    $candidateExpr = ($assignedExpr instanceof Node\Expr) ? $assignedExpr : $exprToWrap;

                    if (
                        ! $this->isByRefReturn
                        && ! $this->isNativeVoid
                        && $this->functionNode !== null
                        && $this->returnTypeNode !== null
                        && EliminationSafetyPolicy::isReturnEliminationSafe($this->functionNode, $this->classContext, $this->returnTypeNode)
                        && StaticTypeEvaluator::satisfies($candidateExpr, $this->returnTypeNode, $this->currentFile, $this->trustedParamTypes, $this->currentNamespace)
                    ) {
                        return $exitStmts !== [] ? [...$exitStmts, $n] : null;
                    }

                    if ($this->isNativeVoid) {
                        $checkCall = FunctionContractInjector::buildReturnCheckCall($exprToWrap, $this->thisArg, $this->needsReturnVars);
                        $voidGuardStmts = FunctionContractInjector::buildVoidReturnGuard($checkCall);

                        return [...$exitStmts, ...$voidGuardStmts];
                    }

                    if ($this->isByRefReturn) {
                        $checkCall = FunctionContractInjector::buildReturnCheckCall($exprToWrap, $this->thisArg, $this->needsReturnVars);
                        $byRefGuardStmts = FunctionContractInjector::buildByRefReturnGuard($checkCall, $n, $n->getStartLine());

                        return [...$exitStmts, ...$byRefGuardStmts];
                    }

                    if ($this->canGuardReturnCache) {
                        $n->expr = FunctionContractInjector::buildGuardedTernaryReturnExpr(
                            $exprToWrap,
                            $this->thisArg,
                            $this->needsReturnVars,
                            $this->useDynamicMethodKey,
                            $n->getStartLine()
                        );
                    } else {
                        $checkCall = FunctionContractInjector::buildReturnCheckCall($exprToWrap, $this->thisArg, $this->needsReturnVars);
                        $n->expr = FunctionContractInjector::buildTernaryReturnExpr($checkCall, $n->getStartLine());
                    }

                    return $exitStmts !== [] ? [...$exitStmts, $n] : null;
                }

                return null;
            }
        });

        /** @var array<Node\Stmt> $newStmts */
        $newStmts = $traverser->traverse($stmts);

        $lastStmt = end($newStmts);
        if (! $lastStmt instanceof Node\Stmt\Return_ && ! ($lastStmt instanceof Node\Stmt\Expression && $lastStmt->expr instanceof Node\Expr\Throw_)) {
            $exitStmts = [];
            if ($hasSelfOut) {
                $exitStmts[] = self::buildSelfOutCheckStmt($thisArg, $needsReturnVars, $useDynamicMethodKey);
            }

            $paramOutStmts = $byRefParams !== []
                ? self::buildParamOutCheckStmts($byRefParams, $thisArg, $needsReturnVars)
                : [];

            if ($paramOutStmts !== []) {
                $exitStmts = [...$exitStmts, ...$paramOutStmts];
            }

            if (! $hasReturn) {
                if ($exitStmts !== []) {
                    $newStmts = [...$newStmts, ...$exitStmts];
                }
            } else {
                $checkCall = self::buildReturnCheckCall(new Node\Expr\ConstFetch(new Node\Name('null')), $thisArg, $needsReturnVars);

                if ($isNativeVoid) {
                    $newStmts = [...$newStmts, ...$exitStmts, ...self::buildVoidReturnGuard($checkCall)];
                } elseif ($isByRefReturn) {
                    $fallbackLine = $lastStmt instanceof Node\Stmt ? $lastStmt->getStartLine() : null;
                    $retStmt = new Node\Stmt\Return_(null);
                    $retStmt->setAttribute('typephp_injected', true);
                    $byRefGuardStmts = self::buildByRefReturnGuard($checkCall, $retStmt, $fallbackLine);
                    $newStmts = [...$newStmts, ...$exitStmts, ...$byRefGuardStmts];
                } else {
                    $fallbackLine = $lastStmt instanceof Node\Stmt ? $lastStmt->getStartLine() : null;
                    $ternaryExpr = $canGuardReturnCache
                        ? self::buildGuardedTernaryReturnExpr(
                            new Node\Expr\ConstFetch(new Node\Name('null')),
                            $thisArg,
                            $needsReturnVars,
                            $useDynamicMethodKey,
                            $fallbackLine
                        )
                        : self::buildTernaryReturnExpr($checkCall, $fallbackLine);

                    $retStmt = new Node\Stmt\Return_($ternaryExpr);
                    $retStmt->setAttribute('typephp_injected', true);
                    $newStmts = [...$newStmts, ...$exitStmts, $retStmt];
                }
            }
        }

        return $newStmts;
    }

    /**
     * @param array<Node\Stmt> $stmts
     */
    public static function tagPrecedingAssignments(array $stmts): void
    {
        $count = \count($stmts);
        for ($i = 1; $i < $count; $i++) {
            $curr = $stmts[$i];
            $prev = $stmts[$i - 1];

            if (
                ! ($prev instanceof Node\Stmt\Expression)
                || ! ($prev->expr instanceof Node\Expr\Assign)
                || ! ($prev->expr->var instanceof Node\Expr\Variable)
                || ! \is_string($prev->expr->var->name)
            ) {
                continue;
            }

            $assignedVarName = $prev->expr->var->name;

            if (
                $curr instanceof Node\Stmt\Return_
                && $curr->expr instanceof Node\Expr\Variable
                && $curr->expr->name === $assignedVarName
            ) {
                $curr->setAttribute('typephp_preceding_assign_expr', $prev->expr->expr);
            } elseif (
                $curr instanceof Node\Stmt\Expression
                && $curr->expr instanceof Node\Expr\Yield_
                && $curr->expr->value instanceof Node\Expr\Variable
                && $curr->expr->value->name === $assignedVarName
                && ($curr->expr->key === null || ! ($curr->expr->key instanceof Node\Expr\Assign))
            ) {
                $curr->expr->setAttribute('typephp_preceding_assign_expr', $prev->expr->expr);
            }
        }
    }
}
