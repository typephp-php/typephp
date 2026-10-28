<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\PrettyPrinterAbstract;

/**
 * @internal
 *
 * Custom AST Printer that squashes injected TypePHP validation blocks into a single line
 * without adding trailing newlines to guarantee zero line-number drift.
 */
final class TypePHPPrinter extends Standard
{
    /**
     * Enables native format-preserving insertion into empty method and function statement lists.
     */
    protected function initializeEmptyListInsertionMap(): void
    {
        parent::initializeEmptyListInsertionMap();

        $this->emptyListInsertionMap[Node\Stmt\ClassMethod::class . '->stmts'] = ['{', ' ', ' '];
        $this->emptyListInsertionMap[Node\Stmt\Function_::class . '->stmts'] = ['{', ' ', ' '];
    }

    /**
     * Overrides base node printing to intercept injected statements and squash their formatting.
     */
    protected function p(
        Node $node,
        int $precedence = PrettyPrinterAbstract::MAX_PRECEDENCE,
        int $lhsPrecedence = PrettyPrinterAbstract::MAX_PRECEDENCE,
        bool $parentFormatPreserved = false
    ): string {
        $output = parent::p($node, $precedence, $lhsPrecedence, $parentFormatPreserved);

        if ($node instanceof Node\Stmt && $node->getAttribute('typephp_injected') === true) {
            return $this->formatInjectedStatement($output);
        }

        return $output;
    }

    /**
     * Converts single-line comments and squashes whitespace on injected statements.
     */
    private function formatInjectedStatement(string $output): string
    {
        $output = preg_replace('/(?:\/\/(.*?)|#(.*?))(?=\r?\n|$)/', '/*$1$2 */', $output) ?? $output;
        $output = preg_replace('/\s+/', ' ', trim($output)) ?? $output;

        return '/*__TYPEPHP_INJECTED_START__*/' . $output . '/*__TYPEPHP_INJECTED_END__*/';
    }
}
