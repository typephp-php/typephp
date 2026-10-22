<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast\Elimination;

use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;

/**
 * Enforces LSP safety rules and runtime state invariants to determine
 * whether a check can be safely eliminated during AST transformation.
 *
 * @internal
 */
final class EliminationSafetyPolicy
{
    /**
     * O(1) hash map of generic containers that carry no object state or WeakMap template bindings.
     *
     * @var array<string, true>
     */
    private const PERMISSIBLE_NON_OBJECT_GENERICS = [
        'int' => true,
        'integer' => true,
        'int-mask' => true,
        'int-mask-of' => true,
        'value-of' => true,
        'list' => true,
        'non-empty-list' => true,
        'array' => true,
        'non-empty-array' => true,
        'iterable' => true,
    ];

    /**
     * O(1) hash map of permissible generator return type containers.
     *
     * @var array<string, true>
     */
    private const PERMISSIBLE_GENERATOR_GENERICS = [
        'generator' => true,
        'iterable' => true,
        'traversable' => true,
        'iterator' => true,
    ];

    /**
     * Determines whether a return check can be safely eliminated under LSP rules.
     *
     * Flow of logic:
     * 1. Returns false if no return type is declared.
     * 2. Rejects generic object classes (e.g. Collection<Dog>) to ensure runtime
     *    WeakMap template bindings are preserved. Permits array containers (list<T>, array<K, V>)
     *    and scalar generics (int<min, max>, int-mask) which carry no object state.
     * 3. Authorizes standalone functions, closures, and arrow functions (zero inheritance risk).
     * 4. For class methods:
     *    - Authorizes private methods (cannot override or be overridden).
     *    - Authorizes classes with no inheritance (`hasRealInheritance === false`).
     * 5. Defers all other methods to runtime to prevent bypassing unanalyzed parent contracts.
     *
     * @param array{name?: ?string, hasInheritance?: bool, hasRealInheritance?: bool, hasPropertyWithDoc?: bool, isReadonly?: bool, isFinal?: bool, hasTemplates?: bool}|null $classContext
     */
    public static function isReturnEliminationSafe(
        ClassMethod|Function_|Closure|ArrowFunction $functionNode,
        ?array $classContext,
        ?TypeNode $returnTypeNode
    ): bool {
        if ($returnTypeNode === null) {
            return false;
        }

        if ($returnTypeNode instanceof GenericTypeNode) {
            $baseName = strtolower($returnTypeNode->type->name);
            if (! isset(self::PERMISSIBLE_NON_OBJECT_GENERICS[$baseName])) {
                return false;
            }
        }

        if ($classContext === null || $functionNode instanceof Function_ || $functionNode instanceof Closure || $functionNode instanceof ArrowFunction) {
            return true;
        }

        if ($functionNode->isPrivate()) {
            return true;
        }

        $hasRealInheritance = $classContext['hasRealInheritance'] ?? true;
        if (! $hasRealInheritance) {
            return true;
        }

        return false;
    }

    /**
     * Determines whether generator yield, send, and return checks can be safely eliminated under LSP rules.
     *
     * @param array{name?: ?string, hasInheritance?: bool, hasRealInheritance?: bool, hasPropertyWithDoc?: bool, isReadonly?: bool, isFinal?: bool, hasTemplates?: bool}|null $classContext
     */
    public static function isGeneratorEliminationSafe(
        ClassMethod|Function_|Closure|ArrowFunction $functionNode,
        ?array $classContext,
        ?TypeNode $returnTypeNode
    ): bool {
        if ($returnTypeNode === null) {
            return false;
        }

        if ($returnTypeNode instanceof GenericTypeNode) {
            $baseName = strtolower(ltrim($returnTypeNode->type->name, '\\'));
            if (! isset(self::PERMISSIBLE_GENERATOR_GENERICS[$baseName])) {
                return false;
            }
        } elseif ($returnTypeNode instanceof IdentifierTypeNode) {
            $baseName = strtolower(ltrim($returnTypeNode->name, '\\'));
            if (! isset(self::PERMISSIBLE_GENERATOR_GENERICS[$baseName])) {
                return false;
            }
        } elseif (! ($returnTypeNode instanceof ArrayTypeNode)) {
            return false;
        }

        if ($classContext === null || $functionNode instanceof Function_ || $functionNode instanceof Closure || $functionNode instanceof ArrowFunction) {
            return true;
        }

        if ($classContext['hasTemplates'] ?? false) {
            return false;
        }

        if ($functionNode->isPrivate()) {
            return true;
        }

        $hasRealInheritance = $classContext['hasRealInheritance'] ?? true;
        if (! $hasRealInheritance) {
            return true;
        }

        return false;
    }
}
