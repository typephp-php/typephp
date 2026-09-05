<?php

declare(strict_types=1);

namespace TypePHP\Internal\Cli;

/**
 * @internal Contract defining a CLI command action.
 */
interface CommandInterface
{
    /**
     * Executes the command and returns an exit code (0 for success, 1 for error).
     *
     * @param array<int, string> $args
     * @param resource $outputStream
     * @param resource $errorStream
     */
    public function execute(array $args, $outputStream = STDOUT, $errorStream = STDERR): int;
}
