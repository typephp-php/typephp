<?php

declare(strict_types=1);

namespace TypePHP\Internal\Cli;

use TypePHP\TypePHP;

/**
 * @internal Executes TypePHP CLI commands.
 */
final class RunCommand implements CommandInterface
{
    private const VALID_PHP_EXTENSIONS = ['php', 'phtml', 'php5', 'php7', 'php8', 'phps'];

    public function execute(array $args, $outputStream = STDOUT, $errorStream = STDERR): int
    {
        $c = [CliFormatter::class, 'color'];

        $target = null;
        $givenTargetCandidate = null;

        foreach ($args as $arg) {
            if (! str_starts_with($arg, '--') && ! str_starts_with($arg, '-')) {
                $givenTargetCandidate = $arg;
                $ext = strtolower(pathinfo($arg, PATHINFO_EXTENSION));

                if ($ext !== '' && ! \in_array($ext, self::VALID_PHP_EXTENSIONS, strict: true)) {
                    fwrite($errorStream, "\n  " . $c(' TYPEPHP ', 'badge_red') . ' ' . $c('Error', 'bold') . "\n\n");
                    fwrite($errorStream, '  ' . $c('✗', 'red') . ' Target file ' . $c('"' . $arg . '"', 'bold') . " is not a PHP script file. TypePHP can only execute PHP files.\n\n");

                    return 1;
                }

                if (file_exists($arg)) {
                    $target = $arg;
                }

                break;
            }
        }

        if ($givenTargetCandidate !== null && $target === null) {
            fwrite($errorStream, "\n  " . $c(' TYPEPHP ', 'badge_red') . ' ' . $c('Error', 'bold') . "\n\n");
            fwrite($errorStream, '  ' . $c('✗', 'red') . ' Target script file ' . $c('"' . $givenTargetCandidate . '"', 'bold') . " does not exist or is not readable.\n\n");

            return 1;
        }

        if ($target === null) {
            (new HelpCommand())->execute($args, $outputStream, $errorStream);

            return 1;
        }

        try {
            TypePHP::boot();
            $realTarget = realpath($target);
            if ($realTarget !== false) {
                require $realTarget;
            }
        } catch (\Throwable $e) {
            $class = \get_class($e);
            $file = $e->getFile();
            $line = $e->getLine();
            $msg = $e->getMessage();

            fwrite($errorStream, "PHP Fatal error:  Uncaught {$class}: {$msg} in {$file} on line {$line}\n");
            fwrite($errorStream, "Stack trace:\n");
            fwrite($errorStream, "#0 {main}\n");
            fwrite($errorStream, "  thrown in {$file} on line {$line}\n");

            return 255;
        }

        return 0;
    }
}
