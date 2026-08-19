<?php

declare(strict_types=1);

namespace TypePHP\Command;

final class CommandRunner
{
    private const KNOWN_COMMANDS = [
        'config:init',
        'cache:clear',
        'cache:warm',
        'cache:rebuild',
        'help',
    ];

    /**
     * Parses CLI arguments and routes execution to the corresponding command class.
     *
     * @param array<int, string> $args
     * @param resource $outputStream
     * @param resource $errorStream
     */
    public static function run(array $args, $outputStream = STDOUT, $errorStream = STDERR): int
    {
        $c = [CliFormatter::class, 'color'];

        $showHelp = \in_array('help', $args, strict: true)
            || \in_array('typephp:help', $args, strict: true)
            || \in_array('--help', $args, strict: true)
            || \in_array('-h', $args, strict: true)
            || $args === [];

        if ($showHelp) {
            return (new HelpCommand())->execute($args, $outputStream, $errorStream);
        }

        $firstArg = $args[0] ?? '';

        if ($firstArg === 'config:init' || $firstArg === 'init') {
            return (new ConfigInitCommand())->execute($args, $outputStream, $errorStream);
        }

        if ($firstArg === 'cache:rebuild') {
            return (new CacheRebuildCommand())->execute($args, $outputStream, $errorStream);
        }

        if ($firstArg === 'cache:clear') {
            return (new CacheClearCommand())->execute($args, $outputStream, $errorStream);
        }

        if ($firstArg === 'cache:warm') {
            return (new CacheWarmCommand())->execute($args, $outputStream, $errorStream);
        }

        $hasFileExtension = str_contains(basename($firstArg), '.');
        $isFileTarget = file_exists($firstArg) || $hasFileExtension;

        if (! $isFileTarget) {
            fwrite($errorStream, "\n  " . $c(' TYPEPHP ', 'badge_red') . ' ' . $c('Error', 'bold') . "\n\n");
            fwrite($errorStream, '  ' . $c('✗', 'red') . ' Command ' . $c('"' . $firstArg . '"', 'bold') . " is not defined.\n\n");
            fwrite($errorStream, '  ' . $c('Did you mean one of these?', 'yellow') . "\n");
            foreach (self::KNOWN_COMMANDS as $cmd) {
                fwrite($errorStream, '    ' . $c('•', 'cyan') . ' ' . $cmd . "\n");
            }
            fwrite($errorStream, "\n");

            return 1;
        }

        return (new RunCommand())->execute($args, $outputStream, $errorStream);
    }
}
