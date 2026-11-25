<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast;

use Composer\Autoload\ClassLoader;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasImportTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use TypePHP\Internal\Ast\Elimination\StaticTypeEvaluator;
use TypePHP\Internal\Docblock\DocblockExtractor;
use TypePHP\Internal\Docblock\DocblockNormalizer;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\ClassNameValidator;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\StubManager;

/**
 * @internal AST Node Visitor that injects contract checks, scope tracking, property hook validation, and parameter/return wrappers into functions and methods.
 */
final class ContractVisitor extends NodeVisitorAbstract
{
    private const ASSIGN_OP_MAP = [
        Node\Expr\AssignOp\Plus::class => Node\Expr\BinaryOp\Plus::class,
        Node\Expr\AssignOp\Minus::class => Node\Expr\BinaryOp\Minus::class,
        Node\Expr\AssignOp\Mul::class => Node\Expr\BinaryOp\Mul::class,
        Node\Expr\AssignOp\Div::class => Node\Expr\BinaryOp\Div::class,
        Node\Expr\AssignOp\Mod::class => Node\Expr\BinaryOp\Mod::class,
        Node\Expr\AssignOp\Concat::class => Node\Expr\BinaryOp\Concat::class,
        Node\Expr\AssignOp\Pow::class => Node\Expr\BinaryOp\Pow::class,
        Node\Expr\AssignOp\BitwiseAnd::class => Node\Expr\BinaryOp\BitwiseAnd::class,
        Node\Expr\AssignOp\BitwiseOr::class => Node\Expr\BinaryOp\BitwiseOr::class,
        Node\Expr\AssignOp\BitwiseXor::class => Node\Expr\BinaryOp\BitwiseXor::class,
        Node\Expr\AssignOp\ShiftLeft::class => Node\Expr\BinaryOp\ShiftLeft::class,
        Node\Expr\AssignOp\ShiftRight::class => Node\Expr\BinaryOp\ShiftRight::class,
        Node\Expr\AssignOp\Coalesce::class => Node\Expr\BinaryOp\Coalesce::class,
    ];

    private const BY_REF_BUILTIN_FUNCTIONS = [
        'preg_match' => [2 => true],
        'preg_match_all' => [2 => true],
        'sort' => [0 => true],
        'rsort' => [0 => true],
        'asort' => [0 => true],
        'arsort' => [0 => true],
        'ksort' => [0 => true],
        'krsort' => [0 => true],
        'usort' => [0 => true],
        'uasort' => [0 => true],
        'uksort' => [0 => true],
        'natcasesort' => [0 => true],
        'natsort' => [0 => true],
        'shuffle' => [0 => true],
        'array_multisort' => [0 => true, 1 => true, 2 => true],
        'array_push' => [0 => true],
        'array_pop' => [0 => true],
        'array_shift' => [0 => true],
        'array_unshift' => [0 => true],
        'array_splice' => [0 => true],
        'array_walk' => [0 => true],
        'array_walk_recursive' => [0 => true],
        'reset' => [0 => true],
        'end' => [0 => true],
        'next' => [0 => true],
        'prev' => [0 => true],
        'each' => [0 => true],
        'parse_str' => [1 => true],
        'openssl_random_pseudo_bytes' => [1 => true],
    ];

    /**
     * @var array<string, array<string|int, string>>
     */
    private static array $parsedShapeCache = [];

    /**
     * @var array<string, TypeNode|false>
     */
    private static array $parsedUnsetTypeCache = [];

    /**
     * In-memory cache of class-level aliases resolved from AST without invoking PHP autoloading.
     *
     * @var array<string, array<string, TypeNode>>
     */
    private static array $astClassAliasesCache = [];

    public static function reset(): void
    {
        self::$astClassAliasesCache = [];
    }

    /**
     * @var array<string, array<string, string>>
     */
    private array $localClassPropertyTypes = [];

    /**
     * @var array<string, array<string, TypeNode>>
     */
    private array $localClassAliases = [];

    private ScopeManager $scopeManager;

    private string $currentNamespace = '';

    /**
     * @var list<array{name: ?string, isAnonymous: bool, hasInheritance: bool, hasRealInheritance: bool, hasPropertyWithDoc: bool, isReadonly: bool, isFinal: bool, hasTemplates: bool, isTrait?: bool, aliases?: array<string, TypeNode>, templates?: array<string, TemplateTagValueNode>}>
     */
    private array $classStack = [];

    /**
     * @var list<array{name: string, isStatic: bool}>
     */
    private array $methodStack = [];

    /**
     * @var list<string>
     */
    private array $functionStack = [];

    /**
     * @var list<bool>
     */
    private array $thisAvailableStack = [];

    /**
     * Tracks whether the enclosing function or method returns by reference.
     *
     * @var list<bool>
     */
    private array $byRefStack = [];

    /**
     * Tracks whether the try-body scope of an active TryCatch node is currently open.
     *
     * @var list<bool>
     */
    private array $tryCatchScopeStack = [];

    /**
     * Tracks whether the if-body scope of an active If_ node is currently open.
     *
     * @var list<bool>
     */
    private array $ifScopeStack = [];

    /**
     * @var array<string, list<string>>
     */
    private array $localClassTemplates = [];

    private int $hoistTempCounter = 0;

    public function __construct(private string $currentFile = '')
    {
        $this->scopeManager = new ScopeManager();
    }

    /**
     * Traverses and transforms AST nodes during entry.
     *
     * @return Node|array<Node>|null
     */
    public function enterNode(Node $node): Node|array|null
    {
        $this->trackDeclarationEntry($node);

        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->byRefStack[] = $node->byRef;
        }

        if ($node instanceof Node\Expr\ArrowFunction && $node->byRef) {
            $this->markWriteContext($node->expr);
        }

        if ($node instanceof Node\Expr\CallLike && ! $node->isFirstClassCallable()) {
            foreach ($node->getArgs() as $arg) {
                if ($arg->byRef) {
                    $this->markWriteContext($arg->value);
                }
            }

            // Built-in functions with by-reference parameters
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                $funcLower = strtolower($node->name->getLast());
                if (isset(self::BY_REF_BUILTIN_FUNCTIONS[$funcLower])) {
                    $targetIndices = self::BY_REF_BUILTIN_FUNCTIONS[$funcLower];
                    foreach ($node->getArgs() as $idx => $arg) {
                        if (isset($targetIndices[$idx])) {
                            $this->markWriteContext($arg->value);
                        }
                    }
                }
            } elseif ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) {
                // If a static property is passed as an argument to any method call, mark write context
                // so it cannot be broken by rewriting into a function call if the method takes by-ref.
                foreach ($node->getArgs() as $arg) {
                    if ($arg->value instanceof Node\Expr\StaticPropertyFetch) {
                        $this->markWriteContext($arg->value);
                    }
                }
            }
        }

        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            $this->scopeManager->pushScope(isIsolated: true);
            $this->registerParamInferredTypes($node->params);
        } elseif ($node instanceof Node\Expr\Closure) {
            $capturedVars = [];
            foreach ($node->uses as $u) {
                if ($u->var instanceof Node\Expr\Variable && \is_string($u->var->name)) {
                    $varName = $u->var->name;
                    $outerType = $this->scopeManager->getVarTypeFromScope($varName);
                    if ($outerType !== null) {
                        $capturedVars[$varName] = $outerType;
                    }
                }
            }
            $this->scopeManager->pushScope(isIsolated: true, initialBindings: $capturedVars);
            $this->registerParamInferredTypes($node->params);
        } elseif ($this->isSoftScopeBoundary($node)) {
            $this->scopeManager->pushScope(isIsolated: false);
        } elseif ($node instanceof Node\Stmt\If_) {
            $this->scopeManager->pushScope(isIsolated: false);
            $this->ifScopeStack[] = true;
        } elseif ($node instanceof Node\Stmt\ElseIf_ || $node instanceof Node\Stmt\Else_) {
            if ($this->ifScopeStack !== [] && end($this->ifScopeStack)) {
                $this->scopeManager->popScope();
                array_pop($this->ifScopeStack);
                $this->ifScopeStack[] = false;
            }
            $this->scopeManager->pushScope(isIsolated: false);
        } elseif ($node instanceof Node\Stmt\TryCatch) {
            $this->scopeManager->pushScope(isIsolated: false);
            $this->tryCatchScopeStack[] = true;
        } elseif ($node instanceof Node\Stmt\Catch_ || $node instanceof Node\Stmt\Finally_) {
            if ($this->tryCatchScopeStack !== [] && end($this->tryCatchScopeStack)) {
                $this->scopeManager->popScope();
                array_pop($this->tryCatchScopeStack);
                $this->tryCatchScopeStack[] = false;
            }
            $this->scopeManager->pushScope(isIsolated: false);
        }

        if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp) {
            $this->markWriteContext($node->var);
        } elseif ($node instanceof Node\Expr\AssignRef) {
            $this->markWriteContext($node->var);
            $this->markWriteContext($node->expr);
        } elseif ($node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec) {
            $this->markWriteContext($node->var);
        } elseif ($node instanceof Node\Stmt\Unset_) {
            foreach ($node->vars as $v) {
                $this->markWriteContext($v);
            }
        } elseif ($node instanceof Node\ArrayItem && $node->byRef) {
            $this->markWriteContext($node->value);
        } elseif ($node instanceof Node\Expr\Isset_) {
            foreach ($node->vars as $v) {
                $this->markWriteContext($v);
            }
        } elseif ($node instanceof Node\Expr\Empty_) {
            $this->markWriteContext($node->expr);
        }

        if ($node instanceof Node\Stmt\Class_) {
            $this->processClassPropertyDefaults($node);
        }

        if ($node instanceof Node\Stmt\Property) {
            PropertyHookInjector::process($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Return_) {
            if ($node->expr !== null && $this->byRefStack !== [] && end($this->byRefStack)) {
                $this->markWriteContext($node->expr);
            }

            $this->handleReturn($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Expression) {
            $node->expr->setAttribute('typephp_is_standalone', true);

            return $this->handleExpression($node);
        }

        if ($node instanceof Node\Expr\ArrowFunction) {
            $node->expr->setAttribute('typephp_is_standalone', true);
        }

        if ($node instanceof Node\Stmt\Foreach_) {
            $doc = $node->getDocComment();
            if ($doc !== null && str_contains($doc->getText(), '@var')) {
                $this->scopeManager->extractVarDocblock($doc->getText(), $node->valueVar);
            }

            if ($node->keyVar !== null) {
                $this->markWriteContext($node->keyVar);
            }
            $this->markWriteContext($node->valueVar);

            $this->handleForeachTypePropagation($node);

            return null;
        }

        if ($node instanceof Node\Expr\Assign) {
            if ($node->getAttribute('typephp_wrapped') === true) {
                return null;
            }

            return $this->handleAssign($node);
        } elseif ($node instanceof Node\Expr\AssignRef) {
            $this->handleAssignRef($node);
        }

        return null;
    }

    /**
     * Pops the current lexical scope stack frame or replaces transformed expressions upon leaving a node.
     *
     * @return Node|array<Node>|null
     */
    public function leaveNode(Node $node): Node|array|null
    {
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            $classContext = $this->classStack !== [] ? end($this->classStack) : null;
            FunctionContractInjector::inject($node, $classContext, $this->currentFile, $this->currentNamespace);
        }

        $this->trackDeclarationExit($node);

        if ($node instanceof Node\Stmt\Unset_) {
            return $this->handleUnset($node);
        }

        if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
            $className = $this->resolveQualifiedName($node->name);
            if ($className !== null) {
                $constChecks = $this->collectUnprovenClassConstantChecks($node, $className);
                if ($constChecks !== []) {
                    return [$node, ...$constChecks];
                }
            }
        }

        if ($node instanceof Node\Stmt\Const_) {
            $globalConstChecks = $this->collectUnprovenGlobalConstantChecks($node);
            if ($globalConstChecks !== []) {
                return [$node, ...$globalConstChecks];
            }
        }

        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            array_pop($this->byRefStack);
        }

        if ($node instanceof Node\Expr\Clone_) {
            return $this->wrapClone($node);
        }

        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && strtolower($node->name->getLast()) === 'clone') {
            $replacement = $this->wrapCloneFunction($node);
            if ($replacement !== null) {
                return $replacement;
            }
        }

        if ($node instanceof Node\Expr\AssignOp) {
            $replacement = $this->handleAssignOp($node);
            if ($replacement !== null) {
                return $replacement;
            }
        }

        if ($node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec) {
            if (($node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PostDec) && $node->getAttribute('typephp_is_standalone') !== true) {
                return null;
            }

            $replacement = $this->handleIncDec($node);
            if ($replacement !== null) {
                return $replacement;
            }
        }

        if ($node instanceof Node\Expr\StaticPropertyFetch && $node->name instanceof Node\VarLikeIdentifier) {
            $propName = $node->name->toString();

            if ($node->getAttribute('typephp_checked') !== true && $node->getAttribute('typephp_write_context') !== true) {
                $node->setAttribute('typephp_checked', true);
                $classArg = $this->resolveStaticClassArg($node->class);

                return new Node\Expr\StaticCall(
                    new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker'),
                    'checkStaticProperty',
                    [
                        new Node\Arg($classArg),
                        new Node\Arg(new Node\Scalar\String_($propName)),
                        new Node\Arg($node),
                        new Node\Arg(new Node\Scalar\MagicConst\File()),
                        new Node\Arg(new Node\Scalar\LNumber($node->getStartLine())),
                    ]
                );
            }
        }

        if ($node instanceof Node\Stmt\Foreach_ && $node->byRef) {
            $boundaryCheck = $this->createForeachBoundaryCheck($node);
            if ($this->isScopeBoundary($node)) {
                $this->scopeManager->popScope();
            }

            return $boundaryCheck !== null ? [$node, $boundaryCheck] : null;
        }

        if ($node instanceof Node\Stmt\ElseIf_ || $node instanceof Node\Stmt\Else_) {
            $this->scopeManager->popScope();
        } elseif ($node instanceof Node\Stmt\If_) {
            $wasActive = $this->ifScopeStack !== [] ? array_pop($this->ifScopeStack) : false;
            if ($wasActive) {
                $this->scopeManager->popScope();
            }
        } elseif ($node instanceof Node\Stmt\Catch_ || $node instanceof Node\Stmt\Finally_) {
            $this->scopeManager->popScope();
        } elseif ($node instanceof Node\Stmt\TryCatch) {
            $wasActive = $this->tryCatchScopeStack !== [] ? array_pop($this->tryCatchScopeStack) : false;
            if ($wasActive) {
                $this->scopeManager->popScope();
            }
        }

        if ($this->isScopeBoundary($node)) {
            $this->scopeManager->popScope();
        }

        return null;
    }

    /**
     * @return list<Node\Stmt>
     */
    private function collectUnprovenClassConstantChecks(Node\Stmt\Class_ $node, string $className): array
    {
        $injectedStmts = [];

        foreach ($node->stmts as $stmt) {
            if (! ($stmt instanceof Node\Stmt\ClassConst)) {
                continue;
            }

            $doc = $stmt->getDocComment();
            if ($doc === null || ! str_contains($doc->getText(), '@var')) {
                continue;
            }

            if (Config::isRespectIgnoreTagsEnabled() && str_contains($doc->getText(), '@typephp-ignore')) {
                continue;
            }

            $varTag = DocblockExtractor::extractVarTagFromDoc($doc->getText());
            if ($varTag === null) {
                continue;
            }

            $typeString = $varTag[0];

            try {
                [$typeParser, $lexer] = DocblockExtractor::getTypeParserComponents();
                $tokens = new TokenIterator($lexer->tokenize(DocblockNormalizer::normalize($typeString)));
                $typeNode = $typeParser->parse($tokens);

                foreach ($stmt->consts as $const) {
                    $constName = $const->name->toString();
                    $fullTarget = $className . '::' . $constName;

                    if ($const->value instanceof Node\Expr\Closure && $typeNode instanceof CallableTypeNode) {
                        $this->instrumentConstantClosure($const->value, $typeNode, $fullTarget);

                        continue;
                    }

                    if (Config::isEliminateStaticallyProvenChecksEnabled() && StaticTypeEvaluator::satisfies($const->value, $typeNode, $this->currentFile, [], $this->currentNamespace)) {
                        continue;
                    }

                    $checkCall = $stmt->isPublic()
                        ? NodeBuilder::createConstantCheckCall(
                            $fullTarget,
                            new Node\Expr\ClassConstFetch(new Node\Name\FullyQualified($className), $constName),
                            $typeString,
                            $const->getStartLine()
                        )
                        : NodeBuilder::createClassConstantCheckCall(
                            $className,
                            $constName,
                            $typeString,
                            $const->getStartLine()
                        );

                    $checkStmt = new Node\Stmt\Expression(NodeBuilder::createTernaryThrowExpr($checkCall, $const->getStartLine()));
                    $checkStmt->setAttribute('typephp_injected', true);
                    $injectedStmts[] = $checkStmt;
                }
            } catch (\Throwable) {
            }
        }

        return $injectedStmts;
    }

    /**
     * @return list<Node\Stmt>
     */
    private function collectUnprovenGlobalConstantChecks(Node\Stmt\Const_ $node): array
    {
        $doc = $node->getDocComment();
        if ($doc === null || ! str_contains($doc->getText(), '@var')) {
            return [];
        }

        if (Config::isRespectIgnoreTagsEnabled() && str_contains($doc->getText(), '@typephp-ignore')) {
            return [];
        }

        $varTag = DocblockExtractor::extractVarTagFromDoc($doc->getText());
        if ($varTag === null) {
            return [];
        }

        $typeString = $varTag[0];
        $injectedStmts = [];

        try {
            [$typeParser, $lexer] = DocblockExtractor::getTypeParserComponents();
            $tokens = new TokenIterator($lexer->tokenize(DocblockNormalizer::normalize($typeString)));
            $typeNode = $typeParser->parse($tokens);

            foreach ($node->consts as $const) {
                $constName = $const->name->toString();
                $qualifiedConstName = $this->currentNamespace !== ''
                    ? $this->currentNamespace . '\\' . $constName
                    : $constName;

                if ($const->value instanceof Node\Expr\Closure && $typeNode instanceof CallableTypeNode) {
                    $this->instrumentConstantClosure($const->value, $typeNode, $qualifiedConstName);

                    continue;
                }

                if (Config::isEliminateStaticallyProvenChecksEnabled() && StaticTypeEvaluator::satisfies($const->value, $typeNode, $this->currentFile, [], $this->currentNamespace)) {
                    continue;
                }

                $constFetch = new Node\Expr\ConstFetch(new Node\Name\FullyQualified($qualifiedConstName));

                $checkCall = NodeBuilder::createConstantCheckCall(
                    $qualifiedConstName,
                    $constFetch,
                    $typeString,
                    $const->getStartLine()
                );

                $checkStmt = new Node\Stmt\Expression(NodeBuilder::createTernaryThrowExpr($checkCall, $const->getStartLine()));
                $checkStmt->setAttribute('typephp_injected', true);
                $injectedStmts[] = $checkStmt;
            }
        } catch (\Throwable) {
        }

        return $injectedStmts;
    }

    private function instrumentConstantClosure(
        Node\Expr\Closure $closure,
        CallableTypeNode $callableTypeNode,
        string $caller
    ): void {
        $paramChecks = [];
        $callableParams = $callableTypeNode->parameters;
        $paramsByName = [];

        foreach ($callableParams as $idx => $pNode) {
            $pName = $pNode->parameterName !== null ? ltrim($pNode->parameterName, '$') : null;
            if ($pName !== null) {
                $paramsByName[$pName] = $pNode;
            } elseif (isset($closure->params[$idx])) {
                $closureParam = $closure->params[$idx];
                if ($closureParam->var instanceof Node\Expr\Variable && \is_string($closureParam->var->name)) {
                    $paramsByName[$closureParam->var->name] = $pNode;
                }
            }
        }

        foreach ($closure->params as $param) {
            if ($param->var instanceof Node\Expr\Variable && \is_string($param->var->name)) {
                $pName = $param->var->name;
                $pContract = $paramsByName[$pName] ?? null;

                if ($pContract !== null) {
                    $typeStr = (string) $pContract->type;
                    if (strtolower(trim($typeStr)) === 'mixed') {
                        continue;
                    }

                    $line = $param->getStartLine();
                    $checkCall = NodeBuilder::createConstantClosureParamCheckCall(
                        $caller,
                        $pName,
                        new Node\Expr\Variable($pName),
                        $typeStr,
                        $line
                    );
                    $checkStmt = new Node\Stmt\Expression(NodeBuilder::createTernaryThrowExpr($checkCall, $line));
                    $checkStmt->setAttribute('typephp_injected', true);
                    $paramChecks[] = $checkStmt;
                }
            }
        }

        if ($paramChecks !== []) {
            $closure->stmts = [...$paramChecks, ...$closure->stmts];
        }

        $returnTypeNode = $callableTypeNode->returnType;
        $returnTypeStr = (string) $returnTypeNode;
        $isReturnUnconstrained = \in_array(strtolower(trim($returnTypeStr)), ['void', 'mixed'], true);

        if (! $isReturnUnconstrained) {
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new class (
                $caller,
                $returnTypeNode,
                $returnTypeStr,
                $this->currentFile,
                $this->currentNamespace
            ) extends NodeVisitorAbstract {
                public function __construct(
                    private string $caller,
                    private TypeNode $returnTypeNode,
                    private string $returnTypeStr,
                    private string $currentFile,
                    private string $currentNamespace
                ) {
                }

                public function enterNode(Node $n): int|Node|null
                {
                    if (
                        $n instanceof Node\Expr\Closure
                        || $n instanceof Node\Expr\ArrowFunction
                        || $n instanceof Node\Stmt\Function_
                        || $n instanceof Node\Stmt\ClassMethod
                        || $n instanceof Node\Stmt\Class_
                    ) {
                        return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                    }

                    if ($n instanceof Node\Stmt\Return_) {
                        $exprToWrap = $n->expr ?? new Node\Expr\ConstFetch(new Node\Name('null'));

                        if (
                            Config::isEliminateStaticallyProvenChecksEnabled()
                            && StaticTypeEvaluator::satisfies($exprToWrap, $this->returnTypeNode, $this->currentFile, [], $this->currentNamespace)
                        ) {
                            return null;
                        }

                        $line = $n->getStartLine();
                        $checkCall = NodeBuilder::createConstantClosureReturnCheckCall(
                            $this->caller,
                            $exprToWrap,
                            $this->returnTypeStr,
                            $line
                        );

                        $n->expr = FunctionContractInjector::buildTernaryReturnExpr($checkCall, $line);

                        return $n;
                    }

                    return null;
                }
            });

            /** @var array<Node\Stmt> $newStmts */
            $newStmts = $traverser->traverse($closure->stmts);
            $closure->stmts = $newStmts;
        }
    }

    private function createForeachBoundaryCheck(Node\Stmt\Foreach_ $node): ?Node\Stmt\Expression
    {
        $targetVarExpr = null;
        $varName = null;
        $typeString = null;

        if ($node->expr instanceof Node\Expr\Variable && \is_string($node->expr->name)) {
            $varName = $node->expr->name;
            $typeString = $this->scopeManager->getVarTypeFromScope($varName);
            $targetVarExpr = $node->expr;
        } elseif (($chainInfo = $this->extractMemberChain($node->expr)) !== null) {
            $root = $chainInfo['root'];
            if ($root instanceof Node\Expr\Variable && \is_string($root->name)) {
                $varName = $root->name;
                $typeString = $this->scopeManager->getVarTypeFromScope($varName);
                $targetVarExpr = $root;
            }
        }

        if ($targetVarExpr === null || $varName === null || $typeString === null) {
            return null;
        }

        if (Config::isEliminateStaticallyProvenChecksEnabled() && self::isLoopBodyFreeOfOpaqueMutations($node)) {
            return null;
        }

        $line = $node->getEndLine() > 0 ? $node->getEndLine() : $node->getStartLine();
        $checkStmt = new Node\Stmt\Expression(
            new Node\Expr\Assign(
                $targetVarExpr,
                $this->wrapVariableCheck($targetVarExpr, $typeString, $varName, $line)
            )
        );
        $checkStmt->setAttribute('typephp_injected', true);

        return $checkStmt;
    }

    private static function isLoopBodyFreeOfOpaqueMutations(Node\Stmt\Foreach_ $node): bool
    {
        $visitor = new class () extends NodeVisitorAbstract {
            public bool $hasOpaqueCalls = false;

            public function enterNode(Node $n): ?int
            {
                if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction) {
                    $this->hasOpaqueCalls = true;

                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($n instanceof Node\Expr\CallLike || $n instanceof Node\Expr\AssignRef) {
                    $this->hasOpaqueCalls = true;

                    return NodeTraverser::STOP_TRAVERSAL;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($node->stmts);

        return ! $visitor->hasOpaqueCalls;
    }

    private function wrapCloneFunction(Node\Expr\FuncCall $node): ?Node\Expr
    {
        if ($node->getAttribute('typephp_wrapped') === true) {
            return null;
        }

        $firstArg = $node->args[0] ?? null;
        if (! $firstArg instanceof Node\Arg) {
            return null;
        }

        $node->setAttribute('typephp_wrapped', true);

        $originalExpr = $firstArg->value;

        $secondArg = $node->args[1] ?? null;
        $propertiesExpr = $secondArg instanceof Node\Arg
            ? $secondArg->value
            : new Node\Expr\ConstFetch(new Node\Name('null'));

        $node->args[0] = new Node\Arg(
            new Node\Expr\FuncCall(
                new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::prepareClone'),
                [new Node\Arg($originalExpr)],
            ),
        );

        return new Node\Expr\StaticCall(
            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker'),
            'cloneWith',
            [
                new Node\Arg($node),
                new Node\Arg($originalExpr),
                new Node\Arg($propertiesExpr),
                new Node\Arg(new Node\Scalar\MagicConst\File()),
                new Node\Arg(new Node\Scalar\LNumber($node->getStartLine())),
            ],
        );
    }

    private function markWriteContext(Node $node): void
    {
        while ($node instanceof Node\Expr\ArrayDimFetch || $node instanceof Node\Expr\PropertyFetch) {
            $node->setAttribute('typephp_write_context', true);
            $node = $node->var;
        }

        $node->setAttribute('typephp_write_context', true);

        if ($node instanceof Node\Expr\Array_ || $node instanceof Node\Expr\List_) {
            foreach ($node->items as $item) {
                if ($item !== null) {
                    $this->markWriteContext($item->value);
                }
            }
        }
    }

    private function processClassPropertyDefaults(Node\Stmt\Class_ $node): void
    {
        if (! Config::isInlinePropertiesEnabled()) {
            return;
        }

        $defaultProps = [];
        $hasConstructor = false;
        $classTemplates = null;

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && strtolower($stmt->name->toString()) === '__construct') {
                $hasConstructor = true;
            } elseif ($stmt instanceof Node\Stmt\Property && ! $stmt->isStatic()) {
                if (isset($stmt->hooks) && \is_array($stmt->hooks) && $stmt->hooks !== []) {
                    continue;
                }

                $doc = $stmt->getDocComment();
                if ($doc !== null && str_contains($doc->getText(), '@var') && ! str_contains($doc->getText(), '@typephp-ignore')) {
                    $hasNonNullDefault = false;
                    foreach ($stmt->props as $p) {
                        $isExplicitNull = $p->default instanceof Node\Expr\ConstFetch && strtolower($p->default->name->toString()) === 'null';
                        if ($p->default !== null && ! $isExplicitNull) {
                            $hasNonNullDefault = true;

                            break;
                        }
                    }

                    if (! $hasNonNullDefault) {
                        continue;
                    }

                    $classTemplates ??= $this->resolveClassTemplatesForDefaults($node);

                    $propTypeNode = null;
                    $varTag = DocblockExtractor::extractVarTagFromDoc($doc->getText());
                    if ($varTag !== null) {
                        try {
                            [$typeParser, $lexer] = DocblockExtractor::getTypeParserComponents();
                            $tokens = new TokenIterator($lexer->tokenize(DocblockNormalizer::normalize($varTag[0])));
                            $propTypeNode = $typeParser->parse($tokens);

                            if ($classTemplates !== [] && DocblockParser::typeReferencesTemplate($propTypeNode, $classTemplates)) {
                                continue;
                            }
                        } catch (\Throwable) {
                        }
                    }

                    foreach ($stmt->props as $p) {
                        $isExplicitNull = $p->default instanceof Node\Expr\ConstFetch && strtolower($p->default->name->toString()) === 'null';
                        if ($p->default !== null && ! $isExplicitNull) {
                            if ($propTypeNode !== null && StaticTypeEvaluator::satisfies($p->default, $propTypeNode, $this->currentFile, [], $this->currentNamespace)) {
                                continue;
                            }

                            $defaultProps[] = [
                                'name' => $p->name->toString(),
                                'line' => $p->getStartLine(),
                            ];
                        }
                    }
                }
            }
        }

        if ($defaultProps === []) {
            return;
        }

        if (! $hasConstructor) {
            if ($node->extends !== null || $node->isAbstract()) {
                return;
            }

            $ctorStmts = [];

            foreach ($defaultProps as $dp) {
                $checkCall = NodeBuilder::createPropertyCheckCall(
                    new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $dp['name']),
                    new Node\Expr\Variable('this'),
                    $dp['name']
                );
                $stmt = new Node\Stmt\Expression(NodeBuilder::createTernaryThrowExpr($checkCall, $dp['line']));
                $stmt->setAttribute('typephp_injected', true);
                $ctorStmts[] = $stmt;
            }

            $ctor = new Node\Stmt\ClassMethod('__construct', [
                'flags' => Node\Stmt\Class_::MODIFIER_PUBLIC,
                'params' => [],
                'stmts' => $ctorStmts,
            ]);
            $ctor->setAttribute('typephp_injected', true);
            $node->stmts[] = $ctor;
        } else {
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassMethod && strtolower($stmt->name->toString()) === '__construct') {
                    $injected = [];
                    foreach ($defaultProps as $dp) {
                        $checkCall = NodeBuilder::createPropertyCheckCall(
                            new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $dp['name']),
                            new Node\Expr\Variable('this'),
                            $dp['name']
                        );
                        $checkStmt = new Node\Stmt\Expression(NodeBuilder::createTernaryThrowExpr($checkCall, $dp['line']));
                        $checkStmt->setAttribute('typephp_injected', true);
                        $injected[] = $checkStmt;
                    }
                    $stmt->stmts = [...$injected, ...($stmt->stmts ?? [])];

                    break;
                }
            }
        }
    }

    /**
     * @return array<string, TemplateTagValueNode>
     */
    private function resolveClassTemplatesForDefaults(Node\Stmt\Class_ $node): array
    {
        $classTemplates = [];
        $nodeDoc = $node->getDocComment();
        if ($nodeDoc !== null && (str_contains($nodeDoc->getText(), '@template') || str_contains($nodeDoc->getText(), '@phpstan-template') || str_contains($nodeDoc->getText(), '@psalm-template'))) {
            try {
                $phpDocNode = DocblockExtractor::parseDocString($nodeDoc->getText());
                $classTemplates = DocblockExtractor::extractTemplates($phpDocNode);
            } catch (\Throwable) {
            }
        }

        if ($node->name !== null) {
            $className = $this->resolveQualifiedName($node->name);
            if ($className !== null && class_exists($className, false)) {
                try {
                    $loadedTemplates = DocblockParser::parseClassTemplates($className);
                    $classTemplates = [...$classTemplates, ...$loadedTemplates];
                } catch (\Throwable) {
                }
            }
        }

        if ($node->extends !== null) {
            $parentClass = $this->resolveClassNameFromNode($node->extends) ?? $node->extends->toString();

            if (class_exists($parentClass, false) || StubManager::hasClassStub($parentClass)) {
                try {
                    $parentTemplates = DocblockParser::parseClassTemplates($parentClass);
                    $classTemplates = [...$classTemplates, ...$parentTemplates];
                } catch (\Throwable) {
                }
            } else {
                $parentFile = self::findClassFileWithoutAutoload($parentClass);
                if ($parentFile !== null) {
                    $parentSource = @file_get_contents($parentFile);
                    if ($parentSource !== false && str_contains($parentSource, 'template')) {
                        try {
                            $parentStmts = StreamWrapper::getParser()->parse($parentSource);
                            if ($parentStmts !== null) {
                                $parentDoc = self::findClassDocInStmts($parentStmts, $parentClass);
                                if ($parentDoc !== null) {
                                    $phpDocNode = DocblockExtractor::parseDocString($parentDoc);
                                    $classTemplates = [...$classTemplates, ...DocblockExtractor::extractTemplates($phpDocNode)];
                                }
                            }
                        } catch (\Throwable) {
                        }
                    }
                }
            }
        }

        return $classTemplates;
    }

    private function trackDeclarationEntry(Node $node): void
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name !== null ? $node->name->toString() : '';
        } elseif (
            $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_
            || $node instanceof Node\Stmt\Enum_
        ) {
            $this->enterClassLike($node);
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $this->methodStack[] = ['name' => $node->name->toString(), 'isStatic' => $node->isStatic()];
            $this->thisAvailableStack[] = ! $node->isStatic();
        } elseif ($node instanceof Node\Stmt\Function_) {
            $this->functionStack[] = $this->resolveQualifiedName($node->name) ?? '';
            $this->thisAvailableStack[] = false;
        } elseif ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->thisAvailableStack[] = ! $node->static;
        }
    }

    private function trackDeclarationExit(Node $node): void
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = '';
        } elseif (
            $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_
            || $node instanceof Node\Stmt\Enum_
        ) {
            array_pop($this->classStack);
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            array_pop($this->methodStack);
            array_pop($this->thisAvailableStack);
        } elseif ($node instanceof Node\Stmt\Function_) {
            array_pop($this->functionStack);
            array_pop($this->thisAvailableStack);
        } elseif ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            array_pop($this->thisAvailableStack);
        }
    }

    private function enterClassLike(Node\Stmt\Class_|Node\Stmt\Interface_|Node\Stmt\Trait_|Node\Stmt\Enum_ $node): void
    {
        $typeName = $this->resolveQualifiedName($node->name);
        $shortName = $node->name !== null ? $node->name->toString() : null;
        $hasInheritance = true;
        $hasRealInheritance = false;
        $hasPropertyWithDoc = false;
        $isReadonly = false;
        $isFinal = false;
        $isTrait = $node instanceof Node\Stmt\Trait_;

        $doc = $node->getDocComment();
        if ($doc === null && $typeName !== null && StubManager::hasClassStub($typeName)) {
            $stubDocText = StubManager::getClassDoc($typeName);
            if ($stubDocText !== null) {
                $doc = new \PhpParser\Comment\Doc($stubDocText);
            }
        }

        $templates = [];
        if ($doc !== null && (
            str_contains($doc->getText(), '@template')
            || str_contains($doc->getText(), '@phpstan-template')
            || str_contains($doc->getText(), '@psalm-template')
        )) {
            try {
                $phpDocNode = DocblockExtractor::parseDocString($doc->getText());
                $templates = DocblockExtractor::extractTemplates($phpDocNode);
            } catch (\Throwable) {
            }
        }
        $hasTemplates = $templates !== [];

        if ($hasTemplates) {
            $templateNames = array_keys($templates);
            if ($typeName !== null) {
                $this->localClassTemplates[$typeName] = $templateNames;
            }
            if ($shortName !== null) {
                $this->localClassTemplates[$shortName] = $templateNames;
            }
        }

        if ($node instanceof Node\Stmt\Class_) {
            $hasExtends = $node->extends !== null;
            $hasImplements = $node->implements !== [];
            $hasTraits = false;
            $isReadonly = ($node->flags & Node\Stmt\Class_::MODIFIER_READONLY) !== 0;
            $isFinal = ($node->flags & Node\Stmt\Class_::MODIFIER_FINAL) !== 0;

            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\TraitUse) {
                    $hasTraits = true;
                } elseif ($stmt instanceof Node\Stmt\Property && $stmt->getDocComment() !== null) {
                    $hasPropertyWithDoc = true;
                } elseif ($stmt instanceof Node\Stmt\ClassMethod && strtolower($stmt->name->toString()) === '__construct') {
                    $ctorDoc = $stmt->getDocComment();
                    $hasParamInCtor = $ctorDoc !== null && str_contains($ctorDoc->getText(), '@param');
                    foreach ($stmt->params as $param) {
                        if ($param->isPromoted() && ($param->getDocComment() !== null || $hasParamInCtor)) {
                            $hasPropertyWithDoc = true;

                            break;
                        }
                    }
                }
            }

            $hasClassDoc = $doc !== null && (
                $hasTemplates
                || str_contains($doc->getText(), '@phpstan-')
                || str_contains($doc->getText(), '@psalm-')
                || str_contains($doc->getText(), '@method')
                || str_contains($doc->getText(), '@property')
            );

            $hasRealInheritance = $hasExtends || $hasImplements || $hasTraits;
            $hasInheritance = $hasRealInheritance || $hasClassDoc;
        } elseif ($node instanceof Node\Stmt\Enum_) {
            $hasRealInheritance = $node->implements !== [];
            $hasInheritance = $hasRealInheritance;
        } elseif ($node instanceof Node\Stmt\Trait_) {
            $hasRealInheritance = true;
            $hasInheritance = true;
        }

        if ($typeName !== null && ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_)) {
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\Property) {
                    $pDoc = $stmt->getDocComment();
                    if ($pDoc !== null && str_contains($pDoc->getText(), '@var')) {
                        $varTag = DocblockExtractor::extractVarTagFromDoc($pDoc->getText());
                        if ($varTag !== null) {
                            foreach ($stmt->props as $p) {
                                $propName = $p->name->toString();
                                $this->localClassPropertyTypes[$typeName][$propName] = $varTag[0];
                                if ($shortName !== null) {
                                    $this->localClassPropertyTypes[$shortName][$propName] = $varTag[0];
                                }
                            }
                        }
                    }
                } elseif ($stmt instanceof Node\Stmt\ClassMethod && strtolower($stmt->name->toString()) === '__construct') {
                    $methodDoc = $stmt->getDocComment();
                    $methodParamTags = null;
                    if ($methodDoc !== null && str_contains($methodDoc->getText(), '@param')) {
                        try {
                            $methodPhpDoc = DocblockExtractor::parseDocString($methodDoc->getText());
                            $methodParamTags = DocblockExtractor::getParamTags($methodPhpDoc);
                        } catch (\Throwable) {
                        }
                    }

                    foreach ($stmt->params as $p) {
                        if ($p->isPromoted() && $p->var instanceof Node\Expr\Variable && \is_string($p->var->name)) {
                            $paramName = $p->var->name;
                            $pType = null;
                            $paramDoc = $p->getDocComment();
                            if ($paramDoc !== null) {
                                $pType = DocblockExtractor::extractTypeFromPropertyDoc($paramDoc->getText(), $paramName);
                            }
                            if ($pType === null && $methodParamTags !== null && isset($methodParamTags[$paramName])) {
                                $pType = $methodParamTags[$paramName]->type;
                            }
                            if ($pType !== null) {
                                $this->localClassPropertyTypes[$typeName][$paramName] = (string) $pType;
                                if ($shortName !== null) {
                                    $this->localClassPropertyTypes[$shortName][$paramName] = (string) $pType;
                                }
                            }
                        }
                    }
                }
            }
        }

        $classAliases = $this->extractClassLikeAliases($node, $doc, $typeName);

        $this->classStack[] = [
            'name' => $typeName,
            'isAnonymous' => ($node instanceof Node\Stmt\Class_ && $node->name === null),
            'hasInheritance' => $hasInheritance,
            'hasRealInheritance' => $hasRealInheritance,
            'hasPropertyWithDoc' => $hasPropertyWithDoc,
            'isReadonly' => $isReadonly,
            'isFinal' => $isFinal,
            'hasTemplates' => $hasTemplates,
            'isTrait' => $isTrait,
            'aliases' => $classAliases,
            'templates' => $templates,
        ];
    }

    /**
     * Extracts class-level and inherited @phpstan-type / @psalm-type / @phpstan-import-type aliases at AST transform time
     * without invoking PHP's autoloader/compiler.
     *
     * @return array<string, TypeNode>
     */
    private function extractClassLikeAliases(
        Node\Stmt\Class_|Node\Stmt\Interface_|Node\Stmt\Trait_|Node\Stmt\Enum_ $node,
        ?\PhpParser\Comment\Doc $doc,
        ?string $typeName
    ): array {
        /** @var array<string, TypeNode> $aliases */
        $aliases = [];
        $visited = $typeName !== null ? [$typeName => true] : [];

        if ($doc !== null && (str_contains($doc->getText(), '-type') || str_contains($doc->getText(), 'import-type'))) {
            try {
                $phpDocNode = DocblockExtractor::parseDocString($doc->getText());
                $this->collectAliasesFromPhpDocInFile($phpDocNode, $aliases, $this->currentFile, $this->currentNamespace, $visited);
            } catch (\Throwable) {
            }
        }

        /** @var list<Node\Name> $parentNodes */
        $parentNodes = [];
        if ($node instanceof Node\Stmt\Class_) {
            if ($node->extends !== null) {
                $parentNodes[] = $node->extends;
            }
            foreach ($node->implements as $iface) {
                $parentNodes[] = $iface;
            }
        } elseif ($node instanceof Node\Stmt\Interface_) {
            foreach ($node->extends as $iface) {
                $parentNodes[] = $iface;
            }
        } elseif ($node instanceof Node\Stmt\Enum_) {
            foreach ($node->implements as $iface) {
                $parentNodes[] = $iface;
            }
        }

        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_ || $node instanceof Node\Stmt\Enum_) {
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\TraitUse) {
                    foreach ($stmt->traits as $traitName) {
                        $parentNodes[] = $traitName;
                    }
                }
            }
        }

        foreach ($parentNodes as $parentNode) {
            $parentFqcn = $this->resolveClassNameFromNode($parentNode) ?? $parentNode->toString();
            $parentAliases = self::resolveClassAliasesNoAutoload($parentFqcn, $this->localClassAliases, $visited);

            foreach ($parentAliases as $aName => $aType) {
                if (! isset($aliases[$aName])) {
                    $aliases[$aName] = $aType;
                }
            }
        }

        foreach ($aliases as $aName => $aType) {
            $aliases[$aName] = DocblockParser::substituteAliases($aType, $aliases);
        }

        if ($typeName !== null && $aliases !== []) {
            $this->localClassAliases[$typeName] = $aliases;
        }

        return $aliases;
    }

    /**
     * @param array<string, TypeNode> $aliases
     * @param array<string, true> $visited
     */
    private function collectAliasesFromPhpDocInFile(
        PhpDocNode $phpDocNode,
        array &$aliases,
        string $filePath,
        string $namespace,
        array $visited
    ): void {
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
                if (isset($aliases[$localName])) {
                    continue;
                }

                $sourceFqcn = $this->resolveClassNameFromNode(new Node\Name((string) $importTag->importedFrom))
                    ?? (string) $importTag->importedFrom;

                $sourceAliases = self::resolveClassAliasesNoAutoload($sourceFqcn, $this->localClassAliases, $visited);
                if (isset($sourceAliases[$importTag->importedAlias])) {
                    $aliases[$localName] = $sourceAliases[$importTag->importedAlias];
                }
            }
        }
    }

    /**
     * Resolves class-level aliases for an FQCN without invoking PHP autoloading/compilation.
     *
     * @param array<string, array<string, TypeNode>> $localClassAliases
     * @param array<string, true> $visited
     *
     * @return array<string, TypeNode>
     */
    public static function resolveClassAliasesNoAutoload(
        string $fqcn,
        array $localClassAliases = [],
        array $visited = []
    ): array {
        $fqcn = ltrim($fqcn, '\\');
        if ($fqcn === '' || isset($visited[$fqcn])) {
            return [];
        }

        if (isset($localClassAliases[$fqcn])) {
            return $localClassAliases[$fqcn];
        }

        if (isset(self::$astClassAliasesCache[$fqcn])) {
            return self::$astClassAliasesCache[$fqcn];
        }

        if (
            class_exists($fqcn, false)
            || interface_exists($fqcn, false)
            || trait_exists($fqcn, false)
            || enum_exists($fqcn, false)
        ) {
            return self::$astClassAliasesCache[$fqcn] = DocblockParser::parseClassAliases($fqcn);
        }

        $visited[$fqcn] = true;

        $stubDoc = StubManager::getClassDoc($fqcn);
        if ($stubDoc !== null) {
            if (! str_contains($stubDoc, '-type') && ! str_contains($stubDoc, 'import-type')) {
                return self::$astClassAliasesCache[$fqcn] = [];
            }

            try {
                $phpDocNode = DocblockExtractor::parseDocString($stubDoc);
                $aliases = [];
                self::collectRawAliasesFromAstDoc($phpDocNode, $aliases, '', '', [], $localClassAliases, $visited);

                return self::$astClassAliasesCache[$fqcn] = $aliases;
            } catch (\Throwable) {
                return self::$astClassAliasesCache[$fqcn] = [];
            }
        }

        $filePath = self::findClassFileWithoutAutoload($fqcn);
        if ($filePath === null) {
            return self::$astClassAliasesCache[$fqcn] = [];
        }

        $source = @file_get_contents($filePath);
        if (
            $source === false
            || (
                ! str_contains($source, '-type')
                && ! str_contains($source, 'import-type')
                && ! str_contains($source, 'extends')
                && ! str_contains($source, 'implements')
                && ! str_contains($source, 'use ')
            )
        ) {
            return self::$astClassAliasesCache[$fqcn] = [];
        }

        try {
            $stmts = StreamWrapper::getParser()->parse($source);
            if ($stmts === null) {
                return self::$astClassAliasesCache[$fqcn] = [];
            }

            $aliases = self::extractTargetClassAliasesFromStmts($stmts, $fqcn, $filePath, $localClassAliases, $visited);
        } catch (\Throwable) {
            $aliases = [];
        }

        return self::$astClassAliasesCache[$fqcn] = $aliases;
    }

    /**
     * Locates a class, interface, trait, or enum source file via Composer's ClassLoader without executing it.
     */
    public static function findClassFileWithoutAutoload(string $fqcn): ?string
    {
        $fqcn = ltrim($fqcn, '\\');
        if (! ClassNameValidator::isValid($fqcn)) {
            return null;
        }

        $autoloaders = spl_autoload_functions();
        foreach ($autoloaders as $autoloader) {
            if (\is_array($autoloader) && $autoloader[0] instanceof ClassLoader) {
                $file = $autoloader[0]->findFile($fqcn);
                if (\is_string($file) && $file !== '' && is_file($file)) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * @param array<Node\Stmt> $stmts
     * @param array<string, array<string, TypeNode>> $localClassAliases
     * @param array<string, true> $visited
     *
     * @return array<string, TypeNode>
     */
    private static function extractTargetClassAliasesFromStmts(
        array $stmts,
        string $targetFqcn,
        string $filePath,
        array $localClassAliases,
        array $visited
    ): array {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $ns = $stmt->name !== null ? $stmt->name->toString() : '';
                $imports = self::collectNamespaceImports($stmt->stmts);
                $found = self::scanClassLikesForAliases($stmt->stmts, $ns, $imports, $targetFqcn, $filePath, $localClassAliases, $visited);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        $topImports = self::collectNamespaceImports($stmts);

        return self::scanClassLikesForAliases($stmts, '', $topImports, $targetFqcn, $filePath, $localClassAliases, $visited) ?? [];
    }

    /**
     * @param array<Node\Stmt> $stmts
     * @param array<string, string> $imports
     * @param array<string, array<string, TypeNode>> $localClassAliases
     * @param array<string, true> $visited
     *
     * @return array<string, TypeNode>|null
     */
    private static function scanClassLikesForAliases(
        array $stmts,
        string $namespace,
        array $imports,
        string $targetFqcn,
        string $filePath,
        array $localClassAliases,
        array $visited
    ): ?array {
        foreach ($stmts as $stmt) {
            if (
                ! (
                    $stmt instanceof Node\Stmt\Class_
                    || $stmt instanceof Node\Stmt\Interface_
                    || $stmt instanceof Node\Stmt\Trait_
                    || $stmt instanceof Node\Stmt\Enum_
                )
                || $stmt->name === null
            ) {
                continue;
            }

            $fqcn = $namespace !== '' ? $namespace . '\\' . $stmt->name->toString() : $stmt->name->toString();
            if ($fqcn !== $targetFqcn) {
                continue;
            }

            /** @var array<string, TypeNode> $aliases */
            $aliases = [];
            $doc = $stmt->getDocComment();
            if ($doc !== null && (str_contains($doc->getText(), '-type') || str_contains($doc->getText(), 'import-type'))) {
                try {
                    $phpDocNode = DocblockExtractor::parseDocString($doc->getText());
                    self::collectRawAliasesFromAstDoc($phpDocNode, $aliases, $filePath, $namespace, $imports, $localClassAliases, $visited);
                } catch (\Throwable) {
                }
            }

            /** @var list<Node\Name> $parentNodes */
            $parentNodes = [];
            if ($stmt instanceof Node\Stmt\Class_) {
                if ($stmt->extends !== null) {
                    $parentNodes[] = $stmt->extends;
                }
                foreach ($stmt->implements as $iface) {
                    $parentNodes[] = $iface;
                }
            } elseif ($stmt instanceof Node\Stmt\Interface_) {
                foreach ($stmt->extends as $iface) {
                    $parentNodes[] = $iface;
                }
            } elseif ($stmt instanceof Node\Stmt\Enum_) {
                foreach ($stmt->implements as $iface) {
                    $parentNodes[] = $iface;
                }
            }

            if ($stmt instanceof Node\Stmt\Class_ || $stmt instanceof Node\Stmt\Trait_ || $stmt instanceof Node\Stmt\Enum_) {
                foreach ($stmt->stmts as $member) {
                    if ($member instanceof Node\Stmt\TraitUse) {
                        foreach ($member->traits as $traitName) {
                            $parentNodes[] = $traitName;
                        }
                    }
                }
            }

            foreach ($parentNodes as $parentNode) {
                $parentFqcn = self::resolveAstName($parentNode, $namespace, $imports);
                foreach (self::resolveClassAliasesNoAutoload($parentFqcn, $localClassAliases, $visited) as $aName => $aType) {
                    if (! isset($aliases[$aName])) {
                        $aliases[$aName] = $aType;
                    }
                }
            }

            foreach ($aliases as $aName => $aType) {
                $substituted = DocblockParser::substituteAliases($aType, $aliases);
                $aliases[$aName] = $filePath !== ''
                    ? SpecialTypeResolver::resolveForFile($substituted, $filePath, $namespace, false)
                    : $substituted;
            }

            return $aliases;
        }

        return null;
    }

    /**
     * @param array<string, TypeNode> $aliases
     * @param array<string, string> $imports
     * @param array<string, array<string, TypeNode>> $localClassAliases
     * @param array<string, true> $visited
     */
    private static function collectRawAliasesFromAstDoc(
        PhpDocNode $phpDocNode,
        array &$aliases,
        string $filePath,
        string $namespace,
        array $imports,
        array $localClassAliases,
        array $visited
    ): void {
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
                if (isset($aliases[$localName])) {
                    continue;
                }

                $rawSource = ltrim((string) $importTag->importedFrom, '\\');
                $sourceFqcn = str_starts_with((string) $importTag->importedFrom, '\\')
                    ? $rawSource
                    : ($imports[$rawSource] ?? ($namespace !== '' && ! str_contains($rawSource, '\\') ? $namespace . '\\' . $rawSource : $rawSource));

                $sourceAliases = self::resolveClassAliasesNoAutoload($sourceFqcn, $localClassAliases, $visited);
                if (isset($sourceAliases[$importTag->importedAlias])) {
                    $aliases[$localName] = $sourceAliases[$importTag->importedAlias];
                }
            }
        }

        if ($filePath !== '') {
            foreach ($aliases as $aName => $aType) {
                $aliases[$aName] = SpecialTypeResolver::resolveForFile($aType, $filePath, $namespace, false);
            }
        }
    }

    /**
     * @param array<Node\Stmt> $stmts
     *
     * @return array<string, string>
     */
    private static function collectNamespaceImports(array $stmts): array
    {
        $imports = [];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Use_) {
                if ($stmt->type !== Node\Stmt\Use_::TYPE_NORMAL) {
                    continue;
                }
                foreach ($stmt->uses as $use) {
                    $imports[$use->getAlias()->toString()] = $use->name->toString();
                }
            } elseif ($stmt instanceof Node\Stmt\GroupUse) {
                $prefix = $stmt->prefix->toString();
                foreach ($stmt->uses as $use) {
                    $effectiveType = $use->type !== Node\Stmt\Use_::TYPE_UNKNOWN
                        ? $use->type
                        : $stmt->type;

                    if ($effectiveType !== Node\Stmt\Use_::TYPE_NORMAL) {
                        continue;
                    }
                    $imports[$use->getAlias()->toString()] = $prefix . '\\' . $use->name->toString();
                }
            }
        }

        return $imports;
    }

    /**
     * @param array<string, string> $imports
     */
    private static function resolveAstName(Node\Name $nameNode, string $namespace, array $imports): string
    {
        $raw = $nameNode->toString();
        if ($nameNode->isFullyQualified()) {
            return $raw;
        }

        if (isset($imports[$raw])) {
            return $imports[$raw];
        }

        if (str_contains($raw, '\\')) {
            [$first, $rest] = explode('\\', $raw, 2);
            if (isset($imports[$first])) {
                return $imports[$first] . '\\' . $rest;
            }
        }

        return $namespace !== '' ? $namespace . '\\' . $raw : $raw;
    }

    /**
     * @param array<Node\Stmt> $stmts
     */
    private static function findClassDocInStmts(array $stmts, string $targetFqcn): ?string
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $ns = $stmt->name !== null ? $stmt->name->toString() : '';
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof Node\Stmt\Class_ && $inner->name !== null) {
                        $fqcn = $ns !== '' ? $ns . '\\' . $inner->name->toString() : $inner->name->toString();
                        if ($fqcn === $targetFqcn) {
                            return $inner->getDocComment()?->getText();
                        }
                    }
                }
            } elseif ($stmt instanceof Node\Stmt\Class_ && $stmt->name !== null) {
                if ($stmt->name->toString() === $targetFqcn) {
                    return $stmt->getDocComment()?->getText();
                }
            }
        }

        return null;
    }

    private function handleReturn(Node\Stmt\Return_ $node): void
    {
        if ($node->expr === null) {
            return;
        }

        $doc = $node->getDocComment();
        if ($doc === null || ! str_contains($doc->getText(), '@var')) {
            return;
        }

        $extracted = DocblockExtractor::extractVarTagFromDoc($doc->getText());
        if ($extracted === null) {
            return;
        }

        [$typeString, $varName] = $extracted;
        $isApplicable = ($varName === '')
            || ($node->expr instanceof Node\Expr\Variable && $node->expr->name === $varName);

        if ($isApplicable) {
            $node->expr = $this->wrapVariableCheck(
                $node->expr,
                $typeString,
                $varName !== '' ? $varName : 'return',
                $node->getStartLine()
            );
            $node->setAttribute('typephp_var_wrapped', true);
            $node->setAttribute('typephp_var_type', $typeString);
        }
    }

    /**
     * @return array<Node>|null
     */
    private function handleExpression(Node\Stmt\Expression $node): ?array
    {
        $doc = $node->getDocComment();
        if ($doc !== null && str_contains($doc->getText(), '@var')) {
            $this->scopeManager->extractVarDocblock($doc->getText(), $node->expr);
        }

        if (! ($node->expr instanceof Node\Expr\Assign) || ! $this->isDestructuring($node->expr->var)) {
            return null;
        }

        /** @var Node\Expr\List_|Node\Expr\Array_ $destructuringVar */
        $destructuringVar = $node->expr->var;

        $rhs = $node->expr->expr;
        if ($rhs instanceof Node\Expr\Variable && \is_string($rhs->name)) {
            $sourceType = $this->scopeManager->getVarTypeFromScope($rhs->name);
            if ($sourceType !== null && str_contains($sourceType, '{')) {
                $this->propagateShapeTypesToDestructuring($destructuringVar, $sourceType);
            }
        }

        $destructuredVars = $this->extractDestructuringVariables($destructuringVar);
        $checkStmts = [];

        foreach ($destructuredVars as $dVar) {
            $varName = $dVar['varName'];
            $typeString = $this->scopeManager->getVarTypeFromScope($varName);

            if ($typeString !== null) {
                $checkStmt = new Node\Stmt\Expression(
                    new Node\Expr\Assign(
                        $dVar['expr'],
                        $this->wrapVariableCheck($dVar['expr'], $typeString, $varName, $dVar['expr']->getStartLine())
                    )
                );
                $checkStmt->setAttribute('typephp_injected', true);
                $checkStmts[] = $checkStmt;
            }
        }

        return $checkStmts !== [] ? [$node, ...$checkStmts] : null;
    }

    /**
     * Propagates field types from an array shape into destructured local variables with O(1) AST memoization.
     */
    private function propagateShapeTypesToDestructuring(Node\Expr\List_|Node\Expr\Array_ $destructuringVar, string $shapeTypeString): void
    {
        try {
            $shapeMap = self::$parsedShapeCache[$shapeTypeString] ?? null;

            if ($shapeMap === null) {
                $normalized = DocblockNormalizer::normalize($shapeTypeString);
                [$typeParser, $lexer] = DocblockExtractor::getTypeParserComponents();
                $tokens = new TokenIterator($lexer->tokenize($normalized));
                $parsed = $typeParser->parse($tokens);

                if (! ($parsed instanceof ArrayShapeNode)) {
                    return;
                }

                $shapeMap = [];
                $autoIdx = 0;
                foreach ($parsed->items as $item) {
                    $key = match (true) {
                        $item->keyName instanceof ConstExprStringNode => $item->keyName->value,
                        $item->keyName instanceof ConstExprIntegerNode => (int) $item->keyName->value,
                        $item->keyName instanceof IdentifierTypeNode => $item->keyName->name,
                        $item->keyName !== null => (string) $item->keyName,
                        default => $autoIdx,
                    };

                    if (\is_int($key)) {
                        $autoIdx = max($autoIdx, $key + 1);
                    }

                    $shapeMap[$key] = (string) $item->valueType;
                }

                self::$parsedShapeCache[$shapeTypeString] = $shapeMap;
            }

            $currentIdx = 0;
            foreach ($destructuringVar->items as $item) {
                if ($item === null) {
                    $currentIdx++;

                    continue;
                }

                $destructKey = null;
                if ($item->key instanceof Node\Scalar\String_) {
                    $destructKey = $item->key->value;
                } elseif ($item->key instanceof Node\Scalar\LNumber) {
                    $destructKey = $item->key->value;
                    $currentIdx = max($currentIdx, $destructKey + 1);
                } elseif ($item->key === null) {
                    $destructKey = $currentIdx;
                    $currentIdx++;
                }

                if ($destructKey !== null && isset($shapeMap[$destructKey])) {
                    $targetVar = $item->value;
                    $fieldType = $shapeMap[$destructKey];

                    if ($targetVar instanceof Node\Expr\Variable && \is_string($targetVar->name)) {
                        $this->scopeManager->registerVarType($targetVar->name, $fieldType);
                    } elseif ($targetVar instanceof Node\Expr\List_ || $targetVar instanceof Node\Expr\Array_) {
                        $this->propagateShapeTypesToDestructuring($targetVar, $fieldType);
                    }
                }
            }
        } catch (\Throwable $e) {
        }
    }

    private function handleAssign(Node\Expr\Assign $node): ?Node
    {
        if ($node->var instanceof Node\Expr\Variable && \is_string($node->var->name)) {
            $varName = $node->var->name;

            if ($node->expr instanceof Node\Expr\New_ && $node->expr->class instanceof Node\Name) {
                $resolvedClass = $this->resolveClassNameFromNode($node->expr->class);
                if ($resolvedClass !== null) {
                    $this->scopeManager->registerInferredVarType($varName, $resolvedClass);
                }
            } elseif ($node->expr instanceof Node\Expr\Clone_ && $node->expr->expr instanceof Node\Expr\Variable && \is_string($node->expr->expr->name)) {
                $sourceVarName = $node->expr->expr->name;
                $sourceType = $this->scopeManager->getVarTypeFromScope($sourceVarName)
                    ?? $this->scopeManager->getInferredVarType($sourceVarName);

                if ($sourceType !== null) {
                    $this->scopeManager->registerInferredVarType($varName, $sourceType);
                }
            } elseif (! ($node->expr instanceof Node\Expr\AssignRef)) {
                $this->scopeManager->unregisterInferredVarType($varName);
            }

            $typeString = $this->scopeManager->getVarTypeFromScope($varName);

            if ($typeString !== null) {
                $expr = $node->expr;
                $parsedType = null;

                try {
                    [$typeParser, $lexer] = DocblockExtractor::getTypeParserComponents();
                    $tokens = new TokenIterator($lexer->tokenize(DocblockNormalizer::normalize($typeString)));
                    $parsedType = $typeParser->parse($tokens);
                } catch (\Throwable) {
                }

                if ($expr instanceof Node\Expr\New_ && $parsedType !== null) {
                    $pendingGenericTypeString = $this->extractPendingGenericTypeString($parsedType, $expr);

                    if ($pendingGenericTypeString !== null) {
                        $constructorArgs = $expr->args;
                        $factoryArrow = new Node\Expr\ArrowFunction([
                            'params' => [new Node\Param(new Node\Expr\Variable('_typephpCtorArgs'), null, null, false, true)],
                            'expr' => new Node\Expr\New_(
                                $expr->class,
                                [new Node\Arg(new Node\Expr\Variable('_typephpCtorArgs'), false, true)]
                            ),
                        ]);

                        $wrappedNew = new Node\Expr\StaticCall(
                            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker'),
                            'withPendingGeneric',
                            [
                                new Node\Arg(new Node\Scalar\String_($pendingGenericTypeString)),
                                new Node\Arg($factoryArrow),
                                new Node\Arg(new Node\Scalar\MagicConst\File()),
                                ...$constructorArgs,
                            ]
                        );

                        $node->expr = $this->wrapVariableCheck($wrappedNew, $typeString, $varName, $node->var->getStartLine());

                        return null;
                    }
                }

                if ($parsedType !== null && StaticTypeEvaluator::satisfies($expr, $parsedType, $this->currentFile, [], $this->currentNamespace)) {
                    return null;
                }

                $node->expr = $this->wrapVariableCheck($expr, $typeString, $varName, $node->var->getStartLine());

                return null;
            }
        }

        if ($this->targetHasSideEffects($node->var)) {
            $preAssignments = [];
            $cleanTarget = $this->hoistImpureTarget($node->var, $preAssignments);
            $wrapped = $this->wrapTargetMutation($cleanTarget, $node->expr, $node->var->getStartLine());

            if ($wrapped !== null) {
                $this->markWriteContext($cleanTarget);
                $newAssign = new Node\Expr\Assign($cleanTarget, $wrapped);
                $newAssign->setAttribute('typephp_wrapped', true);

                return $this->wrapWithPreEvaluations($preAssignments, $newAssign);
            }

            return null;
        }

        $wrapped = $this->wrapTargetMutation($node->var, $node->expr, $node->var->getStartLine());
        if ($wrapped !== null) {
            $node->expr = $wrapped;
        }

        return null;
    }

    private function extractPendingGenericTypeString(TypeNode $typeNode, Node\Expr\New_ $newExpr): ?string
    {
        $candidates = $this->collectObjectGenericNodes($typeNode);
        if ($candidates === []) {
            return null;
        }

        if (\count($candidates) === 1) {
            return (string) $candidates[0];
        }

        if ($newExpr->class instanceof Node\Name) {
            $instantiatedFqcn = $this->resolveClassNameFromNode($newExpr->class);
            if ($instantiatedFqcn !== null) {
                $instantiatedFqcn = ltrim($instantiatedFqcn, '\\');
                $matching = [];

                foreach ($candidates as $candidate) {
                    $candFqcn = $this->resolveClassNameFromNode(new Node\Name((string) $candidate->type));
                    if ($candFqcn !== null && ltrim($candFqcn, '\\') === $instantiatedFqcn) {
                        $matching[] = $candidate;
                    }
                }

                if (\count($matching) === 1) {
                    return (string) $matching[0];
                }
            }
        }

        return null;
    }

    /**
     * @return list<GenericTypeNode>
     */
    private function collectObjectGenericNodes(TypeNode $node): array
    {
        while ($node instanceof NullableTypeNode) {
            $node = $node->type;
        }

        if ($node instanceof GenericTypeNode) {
            $baseName = (string) $node->type;
            if (! SpecialTypeResolver::isBuiltInTypeKeyword($baseName) && ClassNameValidator::isValid($baseName)) {
                return [$node];
            }

            return [];
        }

        if ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) {
            $collected = [];
            foreach ($node->types as $member) {
                foreach ($this->collectObjectGenericNodes($member) as $found) {
                    $collected[] = $found;
                }
            }

            return $collected;
        }

        return [];
    }

    private function handleAssignRef(Node\Expr\AssignRef $node): void
    {
        if (! ($node->var instanceof Node\Expr\Variable) || ! \is_string($node->var->name)) {
            return;
        }

        $aliasName = $node->var->name;
        $deducedType = null;

        if ($node->expr instanceof Node\Expr\Variable && \is_string($node->expr->name)) {
            $deducedType = $this->scopeManager->getVarTypeFromScope($node->expr->name)
                ?? $this->scopeManager->getInferredVarType($node->expr->name);
        } elseif (($chainInfo = $this->extractMemberChain($node->expr)) !== null) {
            $root = $chainInfo['root'];
            $chain = $chainInfo['chain'];
            $currType = null;

            if ($root instanceof Node\Expr\Variable && \is_string($root->name)) {
                if ($root->name === 'this' && $this->classStack !== []) {
                    $classInfo = end($this->classStack);
                    $currType = $classInfo['name'] ?? null;
                } else {
                    $currType = $this->scopeManager->getVarTypeFromScope($root->name)
                        ?? $this->scopeManager->getInferredVarType($root->name);
                }
            } elseif ($root instanceof Node\Name) {
                $currType = $this->resolveClassNameFromNode($root);
            }

            if (\is_string($currType) && $currType !== '') {
                $deducedType = $this->deduceMemberChainType($currType, $chain);
            }
        }

        if (\is_string($deducedType) && $deducedType !== '') {
            $this->scopeManager->registerVarType($aliasName, $deducedType, overwrite: true);
        } else {
            $this->scopeManager->unregisterVarType($aliasName);
        }
    }

    private function handleForeachTypePropagation(Node\Stmt\Foreach_ $node): void
    {
        $containerType = null;

        if ($node->expr instanceof Node\Expr\Variable && \is_string($node->expr->name)) {
            $containerType = $this->scopeManager->getVarTypeFromScope($node->expr->name)
                ?? $this->scopeManager->getInferredVarType($node->expr->name);
        } elseif (($chainInfo = $this->extractMemberChain($node->expr)) !== null) {
            $root = $chainInfo['root'];
            $chain = $chainInfo['chain'];
            $currType = null;

            if ($root instanceof Node\Expr\Variable && \is_string($root->name)) {
                if ($root->name === 'this' && $this->classStack !== []) {
                    $classInfo = end($this->classStack);
                    $currType = $classInfo['name'] ?? null;
                } else {
                    $currType = $this->scopeManager->getVarTypeFromScope($root->name)
                        ?? $this->scopeManager->getInferredVarType($root->name);
                }
            } elseif ($root instanceof Node\Name) {
                $currType = $this->resolveClassNameFromNode($root);
            }

            if (\is_string($currType) && $currType !== '') {
                $containerType = $this->deduceMemberChainType($currType, $chain);
            }
        }

        if ($containerType === null) {
            return;
        }

        $elementType = TypeDeducer::deduceElementType($containerType);

        if ($elementType !== null) {
            if ($node->valueVar instanceof Node\Expr\Variable && \is_string($node->valueVar->name)) {
                $this->scopeManager->registerVarType($node->valueVar->name, $elementType, overwrite: false);
            } elseif ($this->isDestructuring($node->valueVar) && str_contains($elementType, '{')) {
                /** @var Node\Expr\List_|Node\Expr\Array_ $destructuringVar */
                $destructuringVar = $node->valueVar;
                $this->propagateShapeTypesToDestructuring($destructuringVar, $elementType);
            }
        }

        if ($node->keyVar instanceof Node\Expr\Variable && \is_string($node->keyVar->name)) {
            $keyType = TypeDeducer::deduceKeyType($containerType);
            $this->scopeManager->registerVarType($node->keyVar->name, $keyType, overwrite: false);
        }
    }

    private function handleAssignOp(Node\Expr\AssignOp $node): ?Node\Expr
    {
        $binaryOpClass = self::ASSIGN_OP_MAP[$node::class] ?? null;
        if ($binaryOpClass === null) {
            return null;
        }

        if ($this->targetHasSideEffects($node->var)) {
            $preAssignments = [];
            $cleanTarget = $this->hoistImpureTarget($node->var, $preAssignments);
            $binaryExpr = new $binaryOpClass(clone $cleanTarget, $node->expr);
            $wrapped = $this->wrapTargetMutation(clone $cleanTarget, $binaryExpr, $node->var->getStartLine());

            if ($wrapped === null) {
                return null;
            }

            if (self::rhsNeedsParensInBinaryOp($node->expr)) {
                $node->expr->setAttribute('typephp_needs_parens', true);
            }

            $this->markWriteContext($cleanTarget);

            return $this->wrapWithPreEvaluations($preAssignments, new Node\Expr\Assign($cleanTarget, $wrapped));
        }

        $binaryExpr = new $binaryOpClass($node->var, $node->expr);
        $wrapped = $this->wrapTargetMutation($node->var, $binaryExpr, $node->var->getStartLine());

        if ($wrapped === null) {
            return null;
        }

        if (self::rhsNeedsParensInBinaryOp($node->expr)) {
            $node->expr->setAttribute('typephp_needs_parens', true);
        }

        return new Node\Expr\Assign($node->var, $wrapped);
    }

    private static function rhsNeedsParensInBinaryOp(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\BinaryOp
            || $expr instanceof Node\Expr\Ternary
            || $expr instanceof Node\Expr\Assign
            || $expr instanceof Node\Expr\AssignOp
            || $expr instanceof Node\Expr\AssignRef
            || $expr instanceof Node\Expr\Instanceof_
            || $expr instanceof Node\Expr\Cast
            || $expr instanceof Node\Expr\UnaryMinus
            || $expr instanceof Node\Expr\UnaryPlus
            || $expr instanceof Node\Expr\BooleanNot
            || $expr instanceof Node\Expr\BitwiseNot
            || $expr instanceof Node\Expr\ErrorSuppress
            || $expr instanceof Node\Expr\Throw_
            || $expr instanceof Node\Expr\Yield_
            || $expr instanceof Node\Expr\YieldFrom
            || $expr instanceof Node\Expr\Print_
            || $expr instanceof Node\Expr\Include_
            || $expr instanceof Node\Expr\ArrowFunction;
    }

    private function handleIncDec(Node\Expr\PreInc|Node\Expr\PostInc|Node\Expr\PreDec|Node\Expr\PostDec $node): ?Node\Expr
    {
        $isInc = $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc;
        $binaryClass = $isInc ? Node\Expr\BinaryOp\Plus::class : Node\Expr\BinaryOp\Minus::class;

        if ($this->targetHasSideEffects($node->var)) {
            $preAssignments = [];
            $cleanTarget = $this->hoistImpureTarget($node->var, $preAssignments);
            $binaryExpr = new $binaryClass(clone $cleanTarget, new Node\Scalar\LNumber(1));
            $wrapped = $this->wrapTargetMutation(clone $cleanTarget, $binaryExpr, $node->var->getStartLine());

            if ($wrapped === null) {
                return null;
            }

            $this->markWriteContext($cleanTarget);

            return $this->wrapWithPreEvaluations($preAssignments, new Node\Expr\Assign($cleanTarget, $wrapped));
        }

        $binaryExpr = new $binaryClass($node->var, new Node\Scalar\LNumber(1));

        $wrapped = $this->wrapTargetMutation($node->var, $binaryExpr, $node->var->getStartLine());

        return $wrapped !== null ? new Node\Expr\Assign($node->var, $wrapped) : null;
    }

    private function targetHasSideEffects(Node $node): bool
    {
        return (new NodeFinder())->findFirst($node, static function (Node $n): bool {
            return $n instanceof Node\Expr\CallLike
                || $n instanceof Node\Expr\PreInc
                || $n instanceof Node\Expr\PostInc
                || $n instanceof Node\Expr\PreDec
                || $n instanceof Node\Expr\PostDec
                || $n instanceof Node\Expr\Assign
                || $n instanceof Node\Expr\AssignOp
                || $n instanceof Node\Expr\AssignRef
                || $n instanceof Node\Expr\Yield_
                || $n instanceof Node\Expr\YieldFrom
                || $n instanceof Node\Expr\Throw_
                || $n instanceof Node\Expr\Eval_
                || $n instanceof Node\Expr\Include_
                || $n instanceof Node\Expr\ShellExec
                || $n instanceof Node\Expr\Exit_;
        }) !== null;
    }

    /**
     * @param list<Node\Expr\Assign> $preAssignments
     */
    private function hoistImpureTarget(Node\Expr $target, array &$preAssignments): Node\Expr
    {
        if ($target instanceof Node\Expr\PropertyFetch) {
            $newVar = $this->targetHasSideEffects($target->var)
                ? $this->hoistImpureTarget($target->var, $preAssignments)
                : $target->var;

            $newName = $target->name;
            if ($newName instanceof Node\Expr && $this->targetHasSideEffects($newName)) {
                $newName = $this->createHoistedTemp($newName, $preAssignments);
            }

            return new Node\Expr\PropertyFetch($newVar, $newName);
        }

        if ($target instanceof Node\Expr\StaticPropertyFetch) {
            $newClass = $target->class;
            if ($newClass instanceof Node\Expr && $this->targetHasSideEffects($newClass)) {
                $newClass = $this->hoistImpureTarget($newClass, $preAssignments);
            }

            $newName = $target->name;
            if ($newName instanceof Node\Expr && $this->targetHasSideEffects($newName)) {
                $newName = $this->createHoistedTemp($newName, $preAssignments);
            }

            return new Node\Expr\StaticPropertyFetch($newClass, $newName);
        }

        if ($target instanceof Node\Expr\ArrayDimFetch) {
            $newVar = $this->targetHasSideEffects($target->var)
                ? $this->hoistImpureTarget($target->var, $preAssignments)
                : $target->var;

            $newDim = $target->dim;
            if ($newDim !== null && $this->targetHasSideEffects($newDim)) {
                $newDim = $this->createHoistedTemp($newDim, $preAssignments);
            }

            return new Node\Expr\ArrayDimFetch($newVar, $newDim);
        }

        if ($this->targetHasSideEffects($target)) {
            return $this->createHoistedTemp($target, $preAssignments);
        }

        return $target;
    }

    /**
     * @param list<Node\Expr\Assign> $preAssignments
     */
    private function createHoistedTemp(Node\Expr $expr, array &$preAssignments): Node\Expr\Variable
    {
        $tempVar = new Node\Expr\Variable('__typephpTgt' . $this->hoistTempCounter++);
        $preAssignments[] = new Node\Expr\Assign($tempVar, $expr);

        return new Node\Expr\Variable($tempVar->name);
    }

    /**
     * @param list<Node\Expr\Assign> $preAssignments
     */
    private function wrapWithPreEvaluations(array $preAssignments, Node\Expr $expr): Node\Expr
    {
        if ($preAssignments === []) {
            return $expr;
        }

        $falseNode = static fn (): Node\Expr\ConstFetch => new Node\Expr\ConstFetch(new Node\Name('false'));

        $cond = new Node\Expr\Ternary($preAssignments[0], $falseNode(), $falseNode());
        $count = \count($preAssignments);
        for ($i = 1; $i < $count; $i++) {
            $step = new Node\Expr\Ternary($preAssignments[$i], $falseNode(), $falseNode());
            $cond = new Node\Expr\BinaryOp\BooleanOr($cond, $step);
        }

        return new Node\Expr\Ternary(
            $cond,
            new Node\Expr\ConstFetch(new Node\Name('null')),
            $expr
        );
    }

    /**
     * Unifies wrapping of mutations across assignments, compound assignments, and increments/decrements.
     */
    private function wrapTargetMutation(Node\Expr $target, Node\Expr $valueExpr, int $line): ?Node\Expr
    {
        if ($target instanceof Node\Expr\Variable && \is_string($target->name)) {
            $varName = $target->name;
            $typeString = $this->scopeManager->getVarTypeFromScope($varName);

            if ($typeString !== null) {
                return $this->wrapVariableCheck($valueExpr, $typeString, $varName, $line);
            }
        } elseif (($chainInfo = $this->extractMemberChain($target)) !== null) {
            $root = $chainInfo['root'];
            $chain = $chainInfo['chain'];

            if ($root instanceof Node\Expr\Variable && \is_string($root->name)) {
                $varName = $root->name;
                $typeString = $this->scopeManager->getVarTypeFromScope($varName);

                if ($typeString !== null && $this->shouldHandleMemberAssignCheck($typeString, $chain)) {
                    return $this->wrapMemberAssignCheck($valueExpr, $root, $chain, $typeString, $varName, $line);
                }
            }

            if (($propDimInfo = $this->extractPropertyDimChain($target)) !== null) {
                return $this->wrapPropertyMemberAssignCheck(
                    $valueExpr,
                    $propDimInfo['targetExpr'],
                    $propDimInfo['propName'],
                    $propDimInfo['chain'],
                    $line
                );
            }

            if ($target instanceof Node\Expr\PropertyFetch && $target->name instanceof Node\Identifier) {
                return $this->wrapPropertyCheck($valueExpr, $target->var, $target->name->toString(), $line);
            }

            if ($target instanceof Node\Expr\StaticPropertyFetch && $target->name instanceof Node\VarLikeIdentifier) {
                $classArg = $this->resolveStaticClassArg($target->class);

                return $this->wrapPropertyCheck($valueExpr, $classArg, $target->name->toString(), $line);
            }
        } elseif ($target instanceof Node\Expr\PropertyFetch && $target->name instanceof Node\Identifier) {
            return $this->wrapPropertyCheck($valueExpr, $target->var, $target->name->toString(), $line);
        } elseif ($target instanceof Node\Expr\StaticPropertyFetch && $target->name instanceof Node\VarLikeIdentifier) {
            $classArg = $this->resolveStaticClassArg($target->class);

            return $this->wrapPropertyCheck($valueExpr, $classArg, $target->name->toString(), $line);
        }

        return null;
    }

    /**
     * Deduces the type resulting from traversing an accessor chain.
     *
     * @param list<array{0: 'dim'|'prop', 1: Node\Expr|string|null}> $chain
     */
    private function deduceMemberChainType(string $rootType, array $chain): ?string
    {
        $deducedType = $rootType;

        foreach ($chain as $step) {
            if ($deducedType === '') {
                return null;
            }

            if ($step[0] === 'dim') {
                $dimExpr = $step[1] instanceof Node\Expr ? $step[1] : null;
                $deducedType = TypeDeducer::deduceDimType($deducedType, $dimExpr);
                if ($deducedType === null) {
                    return null;
                }
            } elseif ($step[0] === 'prop' && \is_string($step[1])) {
                $propName = $step[1];
                if (isset($this->localClassPropertyTypes[$deducedType][$propName])) {
                    $deducedType = $this->localClassPropertyTypes[$deducedType][$propName];
                } else {
                    $deducedType = TypeDeducer::deducePropertyType(
                        $deducedType,
                        $propName,
                        $this->localClassPropertyTypes,
                        $this->localClassTemplates,
                        $this->currentFile
                    );
                }
                if ($deducedType === null) {
                    return null;
                }
            } else {
                return null;
            }
        }

        return $deducedType;
    }

    /**
     * Resolves a static class target into an expression for runtime inspection.
     */
    private function resolveStaticClassArg(Node\Name|Node\Expr $class): Node\Expr
    {
        return $class instanceof Node\Name
            ? new Node\Expr\ClassConstFetch($class, 'class')
            : $class;
    }

    /**
     * Resolves a ClassName AST node into an FQCN string using file imports and namespace fallbacks.
     */
    private function resolveClassNameFromNode(Node\Name $classNode): ?string
    {
        if ($classNode->isFullyQualified()) {
            return $classNode->toString();
        }

        $rawClass = $classNode->toString();
        $lowerClass = strtolower($rawClass);

        if (($lowerClass === 'self' || $lowerClass === 'static') && $this->classStack !== []) {
            $classInfo = end($this->classStack);

            return $classInfo['name'] ?? null;
        }

        $resolved = $this->currentFile !== ''
            ? SpecialTypeResolver::resolveFqcnForFile($rawClass, $this->currentFile, false, $this->currentNamespace)
            : $rawClass;

        if ($resolved === $rawClass && $this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $rawClass;
        }

        return $resolved !== '' ? $resolved : null;
    }

    /**
     * Registers typed function or method parameters in the inferred type stack.
     *
     * @param array<Node\Param> $params
     */
    private function registerParamInferredTypes(array $params): void
    {
        foreach ($params as $param) {
            if ($param->var instanceof Node\Expr\Variable && \is_string($param->var->name) && $param->type instanceof Node\Name) {
                $pClass = $this->resolveClassNameFromNode($param->type);
                if ($pClass !== null) {
                    $this->scopeManager->registerInferredVarType($param->var->name, $pClass);
                }
            }
        }
    }

    /**
     * Converts a member accessor chain into an AST Array_ of tuples (kind, dimExpr).
     *
     * @param list<array{0: 'dim'|'prop', 1: Node\Expr|string|null}> $chain
     */
    private function buildChainAst(array $chain): Node\Expr\Array_
    {
        $chainItems = [];
        foreach ($chain as $step) {
            $kind = new Node\Scalar\String_($step[0]);
            if ($step[0] === 'dim') {
                $dimExpr = $step[1] instanceof Node\Expr ? clone $step[1] : new Node\Expr\ConstFetch(new Node\Name('null'));
            } elseif (\is_string($step[1])) {
                $dimExpr = new Node\Scalar\String_($step[1]);
            } elseif ($step[1] instanceof Node\Expr) {
                $dimExpr = clone $step[1];
            } else {
                $dimExpr = new Node\Scalar\String_('');
            }

            $chainItems[] = new Node\ArrayItem(
                new Node\Expr\Array_([
                    new Node\ArrayItem($kind),
                    new Node\ArrayItem($dimExpr),
                ])
            );
        }

        return new Node\Expr\Array_($chainItems);
    }

    /**
     * Unpacks any nested chain of ArrayDimFetch and PropertyFetch down to its root expression.
     *
     * @return array{root: Node\Expr|Node\Name, chain: list<array{0: 'dim'|'prop', 1: Node\Expr|string|null}>}|null
     */
    private function extractMemberChain(Node\Expr $target): ?array
    {
        $curr = $target;
        $chain = [];

        while ($curr instanceof Node\Expr\ArrayDimFetch || $curr instanceof Node\Expr\PropertyFetch) {
            if ($curr instanceof Node\Expr\ArrayDimFetch) {
                array_unshift($chain, ['dim', $curr->dim]);
                $curr = $curr->var;
            } elseif ($curr instanceof Node\Expr\PropertyFetch) {
                if ($curr->name instanceof Node\Identifier) {
                    array_unshift($chain, ['prop', $curr->name->toString()]);
                    $curr = $curr->var;
                } else {
                    return null;
                }
            }
        }

        if ($curr instanceof Node\Expr\StaticPropertyFetch && $curr->name instanceof Node\VarLikeIdentifier) {
            array_unshift($chain, ['prop', $curr->name->toString()]);
            $curr = $curr->class;
        }

        if ($chain === []) {
            return null;
        }

        return [
            'root' => $curr,
            'chain' => $chain,
        ];
    }

    /**
     * @param list<array{0: 'dim'|'prop', 1: Node\Expr|string|null}> $chain
     */
    private function shouldHandleMemberAssignCheck(string $typeString, array $chain): bool
    {
        if (($chain[0][0] ?? null) === 'prop') {
            return str_contains($typeString, '{');
        }

        foreach ($chain as $step) {
            if ($step[0] === 'dim') {
                return true;
            }
        }

        return str_contains($typeString, '{');
    }

    /**
     * Unpacks array dimension fetches rooted at an instance or static property.
     *
     * @return array{targetExpr: Node\Expr, propName: string, chain: list<array{0: 'dim'|'prop', 1: Node\Expr|string|null}>}|null
     */
    private function extractPropertyDimChain(Node\Expr $target): ?array
    {
        if (! ($target instanceof Node\Expr\ArrayDimFetch)) {
            return null;
        }

        $curr = $target;
        $chain = [];

        while ($curr instanceof Node\Expr\ArrayDimFetch) {
            array_unshift($chain, ['dim', $curr->dim]);
            $curr = $curr->var;
        }

        if ($curr instanceof Node\Expr\PropertyFetch && $curr->name instanceof Node\Identifier) {
            return [
                'targetExpr' => $curr->var,
                'propName' => $curr->name->toString(),
                'chain' => $chain,
            ];
        }

        if ($curr instanceof Node\Expr\StaticPropertyFetch && $curr->name instanceof Node\VarLikeIdentifier) {
            $classArg = $this->resolveStaticClassArg($curr->class);

            return [
                'targetExpr' => $classArg,
                'propName' => $curr->name->toString(),
                'chain' => $chain,
            ];
        }

        return null;
    }

    /**
     * @param list<array{0: 'dim'|'prop', 1: Node\Expr|string|null}> $chain
     */
    private function wrapPropertyMemberAssignCheck(
        Node\Expr $valueExpr,
        Node\Expr $targetExpr,
        string $propName,
        array $chain,
        int $line
    ): Node\Expr\Ternary {
        if (\count($chain) === 1 && $chain[0][0] === 'dim') {
            $dimExpr = $chain[0][1] instanceof Node\Expr
                ? $chain[0][1]
                : new Node\Expr\ConstFetch(new Node\Name('null'));
            $checkCall = NodeBuilder::createPropertyDimAssignCheckCall($targetExpr, $propName, $dimExpr, $valueExpr);

            return NodeBuilder::createTernaryThrowExpr($checkCall, $line);
        }

        $chainAst = $this->buildChainAst($chain);
        $checkCall = NodeBuilder::createPropertyMemberAssignCheckCall($targetExpr, $propName, $chainAst, $valueExpr);

        return NodeBuilder::createTernaryThrowExpr($checkCall, $line);
    }

    /**
     * @param list<array{0: 'dim'|'prop', 1: Node\Expr|string|null}> $chain
     */
    private function wrapMemberAssignCheck(
        Node\Expr $valueExpr,
        Node\Expr $rootExpr,
        array $chain,
        string $typeString,
        string $varName,
        int $line
    ): Node\Expr\Ternary {
        $chainAst = $this->buildChainAst($chain);

        $checkCall = NodeBuilder::createMemberAssignCheckCall(
            $rootExpr,
            $chainAst,
            $valueExpr,
            $typeString,
            $varName,
            $this->getCurrentCallerExpr(),
            $this->getCurrentThisExpr()
        );

        return NodeBuilder::createTernaryThrowExpr($checkCall, $line);
    }

    private function wrapVariableCheck(Node\Expr $expr, string $typeString, string $varName, int $line): Node\Expr\Ternary
    {
        $checkCall = NodeBuilder::createVariableCheckCall(
            $expr,
            $typeString,
            $varName,
            $this->getCurrentCallerExpr(),
            $this->getCurrentThisExpr()
        );

        return NodeBuilder::createTernaryThrowExpr($checkCall, $line);
    }

    private function wrapPropertyCheck(Node\Expr $valueExpr, Node\Expr $targetExpr, string $propName, int $line): Node\Expr\Ternary
    {
        $checkCall = NodeBuilder::createPropertyCheckCall($valueExpr, $targetExpr, $propName);

        return NodeBuilder::createTernaryThrowExpr($checkCall, $line);
    }

    private function wrapClone(Node\Expr\Clone_ $node): ?Node\Expr\FuncCall
    {
        if ($node->getAttribute('typephp_wrapped') === true) {
            return null;
        }

        $node->setAttribute('typephp_wrapped', true);

        return new Node\Expr\FuncCall(
            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::cloneInstance'),
            [
                new Node\Arg(
                    new Node\Expr\Clone_(
                        new Node\Expr\FuncCall(
                            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::prepareClone'),
                            [new Node\Arg($node->expr)]
                        )
                    )
                ),
                new Node\Arg($node->expr),
            ]
        );
    }

    private function isSoftScopeBoundary(Node $node): bool
    {
        return $node instanceof Node\Expr\ArrowFunction
            || $node instanceof Node\Stmt\Block
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Do_
            || $node instanceof Node\Stmt\Case_
            || $node instanceof Node\MatchArm;
    }

    private function isScopeBoundary(Node $node): bool
    {
        return $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Expr\Closure
            || $this->isSoftScopeBoundary($node);
    }

    private function isDestructuring(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\List_
            || ($expr instanceof Node\Expr\Array_ && $expr->getAttribute('kind') === Node\Expr\Array_::KIND_SHORT);
    }

    private function resolveQualifiedName(?Node\Identifier $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return $this->currentNamespace !== ''
            ? $this->currentNamespace . '\\' . $name->toString()
            : $name->toString();
    }

    private function getCurrentCallerExpr(): Node\Expr
    {
        if ($this->methodStack !== [] && $this->classStack !== []) {
            $classInfo = end($this->classStack);
            $methodInfo = end($this->methodStack);

            if (! $classInfo['isAnonymous'] && $classInfo['name'] !== null) {
                return new Node\Scalar\String_($classInfo['name'] . '::' . $methodInfo['name']);
            }

            return new Node\Expr\BinaryOp\Concat(
                new Node\Scalar\MagicConst\Class_(),
                new Node\Scalar\String_('::' . $methodInfo['name'])
            );
        }

        if ($this->functionStack !== []) {
            return new Node\Scalar\String_(end($this->functionStack));
        }

        return new Node\Scalar\String_('');
    }

    private function getCurrentThisExpr(): Node\Expr
    {
        $hasThis = $this->classStack !== []
            && $this->thisAvailableStack !== []
            && end($this->thisAvailableStack);

        return $hasThis
            ? new Node\Expr\Variable('this')
            : new Node\Expr\ConstFetch(new Node\Name('null'));
    }

    /**
     * Recursively extracts target variables assigned inside a list() or [] destructuring node.
     *
     * @return list<array{varName: string, expr: Node\Expr\Variable}>
     */
    private function extractDestructuringVariables(Node\Expr\List_|Node\Expr\Array_ $listNode): array
    {
        $vars = [];
        foreach ($listNode->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->value instanceof Node\Expr\Variable && \is_string($item->value->name)) {
                $vars[] = [
                    'varName' => $item->value->name,
                    'expr' => $item->value,
                ];
            } elseif ($item->value instanceof Node\Expr\List_ || $item->value instanceof Node\Expr\Array_) {
                $vars = [...$vars, ...$this->extractDestructuringVariables($item->value)];
            }
        }

        return $vars;
    }

    /**
     * Hoists impure targets, groups unset chains by root, injects pre-validation checks,
     * and unregisters any whole variables destroyed by unset().
     *
     * @return list<Node\Stmt>|null
     */
    private function handleUnset(Node\Stmt\Unset_ $node): ?array
    {
        /** @var list<Node\Stmt> $preStmts */
        $preStmts = [];
        /** @var list<string> $varsToUnregister */
        $varsToUnregister = [];
        /** @var array<string, array{rootExpr: Node\Expr, typeString: string, varName: string, chains: list<list<array{0: 'dim'|'prop', 1: Node\Expr|string|null}>>}> $varUnsetGroups */
        $varUnsetGroups = [];
        /** @var array<string, array{targetExpr: Node\Expr, propName: string, chains: list<list<array{0: 'dim'|'prop', 1: Node\Expr|string|null}>>}> $propUnsetGroups */
        $propUnsetGroups = [];

        foreach ($node->vars as $idx => $v) {
            if ($v instanceof Node\Expr\Variable) {
                if (\is_string($v->name)) {
                    $varsToUnregister[] = $v->name;
                }

                continue;
            }

            if ($this->targetHasSideEffects($v)) {
                $preAssignments = [];
                $cleanTarget = $this->hoistImpureTarget($v, $preAssignments);
                $this->markWriteContext($cleanTarget);
                $node->vars[$idx] = $cleanTarget;
                $v = $cleanTarget;

                foreach ($preAssignments as $assign) {
                    $preStmt = new Node\Stmt\Expression($assign);
                    $preStmt->setAttribute('typephp_injected', true);
                    $preStmts[] = $preStmt;
                }
            }

            $chainInfo = $this->extractMemberChain($v);
            if ($chainInfo !== null) {
                $root = $chainInfo['root'];
                $chain = $chainInfo['chain'];

                if ($root instanceof Node\Expr\Variable && \is_string($root->name)) {
                    $varName = $root->name;
                    $typeString = $this->scopeManager->getVarTypeFromScope($varName);

                    if ($typeString !== null && $this->shouldHandleMemberUnsetCheck($typeString, $chain)) {
                        if (! isset($varUnsetGroups[$varName])) {
                            $varUnsetGroups[$varName] = [
                                'rootExpr' => clone $root,
                                'typeString' => $typeString,
                                'varName' => $varName,
                                'chains' => [],
                            ];
                        }
                        $varUnsetGroups[$varName]['chains'][] = $chain;

                        continue;
                    }
                }
            }

            $propDimInfo = $this->extractPropertyDimChain($v);
            if ($propDimInfo !== null) {
                $tExpr = $propDimInfo['targetExpr'];
                $pName = $propDimInfo['propName'];

                if ($tExpr instanceof Node\Expr\Variable && \is_string($tExpr->name)) {
                    $groupKey = 'v:' . $tExpr->name . ':' . $pName;
                } elseif ($tExpr instanceof Node\Expr\ClassConstFetch && $tExpr->class instanceof Node\Name) {
                    $groupKey = 'c:' . $tExpr->class->toString() . ':' . $pName;
                } else {
                    $groupKey = 'u:' . $idx;
                }

                if (! isset($propUnsetGroups[$groupKey])) {
                    $propUnsetGroups[$groupKey] = [
                        'targetExpr' => clone $tExpr,
                        'propName' => $pName,
                        'chains' => [],
                    ];
                }
                $propUnsetGroups[$groupKey]['chains'][] = $propDimInfo['chain'];
            }
        }

        /** @var list<Node\Stmt> $checkStmts */
        $checkStmts = [];
        $line = $node->getStartLine();

        foreach ($varUnsetGroups as $group) {
            $checkCall = NodeBuilder::createMemberUnsetCheckCall(
                $group['rootExpr'],
                $this->buildChainsAst($group['chains']),
                $group['typeString'],
                $group['varName'],
                $line,
                $this->getCurrentCallerExpr(),
                $this->getCurrentThisExpr()
            );
            $checkStmts[] = NodeBuilder::createIfErrorThrowStmt($checkCall, $line);
        }

        foreach ($propUnsetGroups as $group) {
            $checkCall = NodeBuilder::createPropertyMemberUnsetCheckCall(
                $group['targetExpr'],
                $group['propName'],
                $this->buildChainsAst($group['chains']),
                $line
            );
            $checkStmts[] = NodeBuilder::createIfErrorThrowStmt($checkCall, $line);
        }

        foreach ($varsToUnregister as $varName) {
            $this->scopeManager->unregisterVarType($varName);
        }

        if ($preStmts === [] && $checkStmts === []) {
            return null;
        }

        return [...$preStmts, ...$checkStmts, $node];
    }

    /**
     * Converts a list of member accessor chains into an AST Array_ of chains.
     *
     * @param list<list<array{0: 'dim'|'prop', 1: Node\Expr|string|null}>> $chains
     */
    private function buildChainsAst(array $chains): Node\Expr\Array_
    {
        $items = [];
        foreach ($chains as $chain) {
            $items[] = new Node\ArrayItem($this->buildChainAst($chain));
        }

        return new Node\Expr\Array_($items);
    }

    /**
     * @param list<array{0: 'dim'|'prop', 1: Node\Expr|string|null}> $chain
     */
    private function shouldHandleMemberUnsetCheck(string $typeString, array $chain): bool
    {
        if (($chain[0][0] ?? null) === 'prop') {
            return str_contains($typeString, '{');
        }

        $hasDim = false;
        foreach ($chain as $step) {
            if ($step[0] === 'dim') {
                $hasDim = true;

                break;
            }
        }

        if (! $hasDim) {
            return str_contains($typeString, '{');
        }

        if (Config::isEliminateStaticallyProvenChecksEnabled()) {
            $typeNode = $this->parseUnsetTypeCached($typeString);
            if ($typeNode !== null && StaticTypeEvaluator::isUnsetSafe($typeNode, $chain)) {
                return false;
            }
        }

        return true;
    }

    private function parseUnsetTypeCached(string $typeString): ?TypeNode
    {
        if (isset(self::$parsedUnsetTypeCache[$typeString])) {
            $cached = self::$parsedUnsetTypeCache[$typeString];

            return $cached !== false ? $cached : null;
        }

        try {
            [$typeParser, $lexer] = DocblockExtractor::getTypeParserComponents();
            $tokens = new TokenIterator($lexer->tokenize(DocblockNormalizer::normalize($typeString)));
            $parsed = $typeParser->parse($tokens);
            self::$parsedUnsetTypeCache[$typeString] = $parsed;

            return $parsed;
        } catch (\Throwable) {
            self::$parsedUnsetTypeCache[$typeString] = false;

            return null;
        }
    }
}
