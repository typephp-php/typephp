<?php

declare(strict_types=1);

namespace TypePHP\Internal\Cli;

final class HelpCommand implements CommandInterface
{
    public function execute(array $args, $outputStream = STDOUT, $errorStream = STDERR): int
    {
        $c = [CliFormatter::class, 'color'];

        fwrite($outputStream, "\n  " . $c(' TYPEPHP ', 'badge_green') . ' ' . $c('Runtime Type Checker', 'bold') . "\n\n");
        fwrite($outputStream, '  ' . $c('USAGE', 'yellow') . "\n");
        fwrite($outputStream, "    vendor/bin/typephp <script.php>\n\n");
        fwrite($outputStream, '  ' . $c('COMMANDS', 'yellow') . "\n");
        fwrite($outputStream, '    ' . $c('config:init', 'green') . "    Generate default typephp.php configuration file\n");
        fwrite($outputStream, '    ' . $c('cache:clear', 'green') . "    Clear all cached transformed files\n");
        fwrite($outputStream, '    ' . $c('cache:warm', 'green') . "     Pre-transform and warm up cache for included files\n");
        fwrite($outputStream, '    ' . $c('cache:rebuild', 'green') . "  Clear and immediately warm up cache\n");
        fwrite($outputStream, '    ' . $c('help', 'green') . "         Display this help menu\n\n");
        fwrite($outputStream, '  ' . $c('EXAMPLES', 'yellow') . "\n");
        fwrite($outputStream, "    vendor/bin/typephp config:init\n");
        fwrite($outputStream, "    vendor/bin/typephp index.php\n");
        fwrite($outputStream, "    vendor/bin/typephp cache:rebuild\n\n");

        return 0;
    }
}
