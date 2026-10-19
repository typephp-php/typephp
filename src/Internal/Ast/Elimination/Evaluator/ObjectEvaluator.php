<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast\Elimination\Evaluator;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Resolver\SpecialTypeResolver;

/**
 * Statically evaluates object instantiations and identity returns ($this, new static, new ClassName).
 *
 * @internal
 */
final class ObjectEvaluator
{
    /**
     * O(1) hash map of relative class keywords that cannot be resolved statically.
     *
     * @var array<string, true>
     */
    private const RELATIVE_CLASS_KEYWORDS = [
        'self' => true,
        'static' => true,
        'parent' => true,
        '$this' => true,
    ];

    /**
     * O(1) hash map of identifier types satisfied by `$this`.
     *
     * @var array<string, true>
     */
    private const THIS_COMPATIBLE_IDENTIFIERS = [
        '$this' => true,
        'self' => true,
    ];

    /**
     * Determines whether an AST object expression deterministically satisfies an object contract.
     *
     * Flow of logic:
     * 1. Rejects GenericTypeNode targets (e.g. Collection<Dog>) to ensure WeakMap template bindings are preserved.
     * 2. Evaluates `$this` against `$this` or `self`.
     * 3. Evaluates `new static()` against `static`.
     * 4. Evaluates `new self()` against `self`.
     * 5. Evaluates `new ClassName()` against `ClassName` using static FQCN equality (zero reflection, zero autoloading).
     */
    public static function satisfies(Expr $expr, TypeNode $typeNode, string $currentFile = ''): bool
    {
        if ($typeNode instanceof GenericTypeNode) {
            return false;
        }

        if ($expr instanceof Variable && $expr->name === 'this') {
            return self::satisfiesThis($typeNode);
        }

        if ($expr instanceof New_ && $expr->class instanceof Name) {
            return self::satisfiesNew($expr->class, $typeNode, $currentFile);
        }

        return false;
    }

    /**
     * Verifies that `$this` satisfies `@return $this` or `@return self`.
     */
    private static function satisfiesThis(TypeNode $typeNode): bool
    {
        if ($typeNode instanceof ThisTypeNode) {
            return true;
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            return isset(self::THIS_COMPATIBLE_IDENTIFIERS[strtolower($typeNode->name)]);
        }

        return false;
    }

    /**
     * Evaluates `new ClassName(...)` against expected object type contracts.
     */
    private static function satisfiesNew(Name $classNameNode, TypeNode $typeNode, string $currentFile): bool
    {
        if (! ($typeNode instanceof IdentifierTypeNode)) {
            return false;
        }

        $rawNewClass = $classNameNode->toString();
        $lowerNewClass = strtolower($rawNewClass);
        $lowerTargetType = strtolower($typeNode->name);

        if ($lowerNewClass === 'static' && $lowerTargetType === 'static') {
            return true;
        }

        if ($lowerNewClass === 'self' && $lowerTargetType === 'self') {
            return true;
        }

        if (isset(self::RELATIVE_CLASS_KEYWORDS[$lowerTargetType])) {
            return false;
        }

        if (isset(self::RELATIVE_CLASS_KEYWORDS[$lowerNewClass])) {
            return false;
        }

        if ($rawNewClass === $typeNode->name) {
            return true;
        }

        if ($currentFile !== '') {
            $resolvedNewClass = SpecialTypeResolver::resolveFqcnForFile($rawNewClass, $currentFile, false);
            $resolvedTypeClass = SpecialTypeResolver::resolveFqcnForFile($typeNode->name, $currentFile, false);

            return $resolvedNewClass !== '' && $resolvedNewClass === $resolvedTypeClass;
        }

        return $rawNewClass === $typeNode->name;
    }
}
