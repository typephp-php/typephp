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
        if ($node->getAttribute('typephp_needs_parens') === true) {
            return '(' . parent::p($node, PrettyPrinterAbstract::MAX_PRECEDENCE, PrettyPrinterAbstract::MAX_PRECEDENCE, $parentFormatPreserved) . ')';
        }

        $output = parent::p($node, $precedence, $lhsPrecedence, $parentFormatPreserved);

        if ($node instanceof Node\Stmt && $node->getAttribute('typephp_injected') === true) {
            return $this->formatInjectedStatement($output);
        }

        return $output;
    }

    /**
     * Converts single-line comments and squashes whitespace on injected statements
     * without altering string literals or attribute tokens.
     */
    private function formatInjectedStatement(string $output): string
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return '/*__TYPEPHP_INJECTED_START__*/' . '/*__TYPEPHP_INJECTED_END__*/';
        }

        $tokens = \PhpToken::tokenize('<?php ' . $trimmed);
        $formatted = '';

        foreach ($tokens as $index => $token) {
            if ($index === 0 && $token->id === T_OPEN_TAG) {
                continue;
            }

            if ($token->id === T_WHITESPACE) {
                $formatted .= ' ';

                continue;
            }

            if ($token->id === T_COMMENT) {
                if (str_starts_with($token->text, '//') || str_starts_with($token->text, '#')) {
                    $commentBody = rtrim($token->text, "\r\n");
                    $cleaned = str_starts_with($commentBody, '//')
                        ? substr($commentBody, 2)
                        : substr($commentBody, 1);
                    $cleaned = str_replace('*/', '* /', $cleaned);
                    $formatted .= '/*' . $cleaned . ' */';
                } else {
                    $formatted .= preg_replace('/\s+/', ' ', $token->text) ?? $token->text;
                }

                continue;
            }

            if ($token->id === T_DOC_COMMENT) {
                $formatted .= preg_replace('/\s+/', ' ', $token->text) ?? $token->text;

                continue;
            }

            $formatted .= $token->text;
        }

        return '/*__TYPEPHP_INJECTED_START__*/' . trim($formatted) . '/*__TYPEPHP_INJECTED_END__*/';
    }
}
