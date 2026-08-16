<?php

declare(strict_types=1);

namespace TypePHP\Internal\Visitor;

use PhpParser\Node;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use TypePHP\Contract\DocblockExtractor;
use TypePHP\Internal\DocblockNormalizer;

/**
 * @internal Manages lexical scope stack frames and extracts local @var variable annotations.
 */
final class ScopeManager
{
    /**
     * @var array<int, array<string, string>>
     */
    private array $scopeStack = [[]];

    /**
     * Pushes a new scope frame, inheriting variables from the parent scope.
     */
    public function pushScope(): void
    {
        $currentScope = end($this->scopeStack);
        $this->scopeStack[] = $currentScope !== false ? $currentScope : [];
    }

    /**
     * Pops the top scope frame, restoring variables back to the parent scope.
     */
    public function popScope(): void
    {
        if (\count($this->scopeStack) > 1) {
            array_pop($this->scopeStack);
        }
    }

    /**
     * Extracts all @var tags from a docblock comment and registers them in the current scope frame.
     * Prioritizes @phpstan-var > @psalm-var > @var.
     */
    public function extractVarDocblock(string $docText, ?Node\Expr $expr = null): void
    {
        try {
            $docText = DocblockNormalizer::normalize($docText);
            [$phpDocParser, $lexer] = DocblockExtractor::getParserComponents();

            $tokens = new TokenIterator($lexer->tokenize($docText));
            $phpDocNode = $phpDocParser->parse($tokens);
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
                    $currentScopeIndex = \count($this->scopeStack) - 1;
                    $this->scopeStack[$currentScopeIndex][$varName] = $typeString;
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore malformed docblocks
        }
    }

    public function getVarTypeFromScope(string $varName): ?string
    {
        for ($i = \count($this->scopeStack) - 1; $i >= 0; $i--) {
            if (isset($this->scopeStack[$i][$varName])) {
                return $this->scopeStack[$i][$varName];
            }
        }

        return null;
    }
}
