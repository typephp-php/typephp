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
     * @var list<array<string, string>>
     */
    private array $scopeStack = [[]];

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
     * Pushes a new lexical scope frame (O(1)).
     *
     * @param bool $isIsolated Whether this scope is a hard boundary (function, method, closure)
     * @param array<string, string> $initialBindings Initial variable contracts (e.g. captured via 'use')
     */
    public function pushScope(bool $isIsolated = false, array $initialBindings = []): void
    {
        $this->depth++;
        $this->scopeStack[] = $initialBindings;
        $this->isolatedStack[] = $isIsolated;
    }

    /**
     * Pops the top scope frame, restoring the previous lexical scope (O(1)).
     */
    public function popScope(): void
    {
        if ($this->depth > 0) {
            array_pop($this->scopeStack);
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
     * Resolves a variable type by walking upward through the lexical scope chain,
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
}
