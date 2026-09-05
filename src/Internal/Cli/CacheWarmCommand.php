<?php

declare(strict_types=1);

namespace TypePHP\Internal\Cli;

use TypePHP\Internal\CacheManager;

/**
 * @internal Warms up the TypePHP cache.
 */
final class CacheWarmCommand implements CommandInterface
{
    public function execute(array $args, $outputStream = STDOUT, $errorStream = STDERR): int
    {
        $c = [CliFormatter::class, 'color'];

        fwrite($outputStream, '  ' . $c(' TYPEPHP ', 'badge') . ' ' . $c('Cache Warm-Up', 'bold') . "\n\n  ");
        $dotsInLine = 0;

        $result = CacheManager::warmUp(function (string $status) use (&$dotsInLine, $c, $outputStream) {
            fwrite($outputStream, $status === 'cached' ? $c('.', 'green') : $c('s', 'gray'));
            $dotsInLine++;
            if ($dotsInLine >= 80) {
                fwrite($outputStream, "\n  ");
                $dotsInLine = 0;
            }
        });

        fwrite($outputStream, "\n\n  " . $c('✓ Cache warm-up complete', 'green') . "\n");
        fwrite($outputStream, '    ' . $c('•', 'cyan') . ' Scanned:     ' . $c((string) $result['total'], 'bold') . " file(s)\n");
        fwrite($outputStream, '    ' . $c('•', 'cyan') . ' Transformed: ' . $c((string) $result['cached'], 'bold') . " file(s)\n");
        fwrite($outputStream, '    ' . $c('•', 'cyan') . ' Skipped:     ' . $c((string) $result['skipped'], 'bold') . " file(s)\n\n");

        return 0;
    }
}
