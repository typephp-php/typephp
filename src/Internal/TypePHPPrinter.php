<?php

declare(strict_types=1);

namespace TypePHP\Internal;

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
     * Overrides base node printing to intercept injected statements, squash their
     * formatting, and tag them with unique markers for post-processing.
     */
    protected function p(
        Node $node,
        int $precedence = PrettyPrinterAbstract::MAX_PRECEDENCE,
        int $lhsPrecedence = PrettyPrinterAbstract::MAX_PRECEDENCE,
        bool $parentFormatPreserved = false
    ): string {
        $output = parent::p($node, $precedence, $lhsPrecedence, $parentFormatPreserved);

        if ($node instanceof Node\Stmt && $node->getAttribute('typephp_injected') === true) {
            $output = preg_replace('/\s+/', ' ', trim($output)) ?? $output;

            return '/*__TYPEPHP_INJECTED_START__*/' . $output . '/*__TYPEPHP_INJECTED_END__*/';
        }

        return $output;
    }
}
