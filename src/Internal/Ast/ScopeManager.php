<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast;

use PhpParser\Node;
use TypePHP\Internal\Docblock\DocblockExtractor;

/**
 * @internal Manages lexical scope stack frames and extracts local @var variable annotations.
 */
final class ScopeManager
{
    /**
     * In-memory stack of explicit @var type annotations.
     *
     * @var list<array<string, string>>
     */
    private array $scopeStack = [[]];

    /**
     * In-memory stack of inferred variable types (e.g. from $var = new ClassName() or typed parameters).
     * Kept separate from explicit @var contracts to avoid injecting unwanted assignment checks on standard variables.
     *
     * @var list<array<string, string>>
     */
    private array $inferredStack = [[]];

    /**
     * Tracks which scope frames are isolated boundaries (functions, methods, closures).
     *
     * @var list<bool>
     */
    private array $isolatedStack = [false];

    /**
     * Tracks the current scope frame depth.
     */
    private int $depth = 0;

    /**
     * Unregisters a variable from the current lexical scope frame (e.g. on unset).
     */
    public function unregisterVarType(string $varName): void
    {
        unset($this->scopeStack[$this->depth][$varName]);
        unset($this->inferredStack[$this->depth][$varName]);
    }

    /**
     * Programmatically registers a variable's type into the current scope frame.
     * Preserves explicit inline @var declarations in the current frame unless overwrite is true.
     */
    public function registerVarType(string $varName, string $typeString, bool $overwrite = false): void
    {
        if (! $overwrite && isset($this->scopeStack[$this->depth][$varName])) {
            return;
        }

        $this->scopeStack[$this->depth][$varName] = $typeString;
    }

    /**
     * Registers an inferred variable type in the current lexical scope frame.
     */
    public function registerInferredVarType(string $varName, string $typeString): void
    {
        $this->inferredStack[$this->depth][$varName] = $typeString;
    }

    /**
     * Unregisters an inferred variable type from the current lexical scope frame (e.g. on reassignment).
     */
    public function unregisterInferredVarType(string $varName): void
    {
        unset($this->inferredStack[$this->depth][$varName]);
    }

    /**
     * Pushes a new lexical scope frame (O(1)).
     *
     * @param bool $isIsolated Whether this scope is a hard boundary (function, method, closure)
     * @param array<string, string> $initialBindings Initial variable contracts (e.g. captured via 'use')
     */
    public function pushScope(bool $isIsolated = false, array $initialBindings = []): void
    {
        $this->depth++;
        $this->scopeStack[] = $initialBindings;
        $this->inferredStack[] = [];
        $this->isolatedStack[] = $isIsolated;
    }

    /**
     * Pops the top scope frame, restoring the previous lexical scope (O(1)).
     */
    public function popScope(): void
    {
        if ($this->depth > 0) {
            array_pop($this->scopeStack);
            array_pop($this->inferredStack);
            array_pop($this->isolatedStack);
            $this->depth--;
        }
    }

    /**
     * Extracts all @var tags from a docblock comment and registers them in the current scope frame.
     * Prioritizes @phpstan-var > @psalm-var > @var.
     */
    public function extractVarDocblock(string $docText, ?Node\Expr $expr = null): void
    {
        if (! str_contains($docText, 'var')) {
            return;
        }

        try {
            $phpDocNode = DocblockExtractor::parseDocString($docText);
            $varTags = DocblockExtractor::getVarTags($phpDocNode);

            foreach ($varTags as $varTag) {
                $typeString = (string) $varTag->type;
                $varName = ltrim($varTag->variableName, '$');

                if ($varName === '' && $expr instanceof Node\Expr\Assign && $expr->var instanceof Node\Expr\Variable && \is_string($expr->var->name)) {
                    $varName = $expr->var->name;
                } elseif ($varName === '' && $expr instanceof Node\Expr\Variable && \is_string($expr->name)) {
                    $varName = $expr->name;
                }

                if ($varName !== '') {
                    $this->scopeStack[$this->depth][$varName] = $typeString;
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore malformed docblocks
        }
    }

    /**
     * Resolves an explicit variable type by walking upward through the lexical scope chain,
     * stopping at isolated scope boundaries (functions, methods, closures).
     */
    public function getVarTypeFromScope(string $varName): ?string
    {
        for ($i = $this->depth; $i >= 0; $i--) {
            if (isset($this->scopeStack[$i][$varName])) {
                return $this->scopeStack[$i][$varName];
            }

            if ($this->isolatedStack[$i]) {
                break;
            }
        }

        return null;
    }

    /**
     * Resolves an inferred variable type by walking upward through the lexical scope chain,
     * stopping at isolated scope boundaries.
     */
    public function getInferredVarType(string $varName): ?string
    {
        for ($i = $this->depth; $i >= 0; $i--) {
            if (isset($this->inferredStack[$i][$varName])) {
                return $this->inferredStack[$i][$varName];
            }

            if ($this->isolatedStack[$i]) {
                break;
            }
        }

        return null;
    }
}
