<?php

declare(strict_types=1);

namespace TypePHP\Internal\Cli;

use TypePHP\Internal\CacheManager;

/**
 * @Command Clears the TypePHP cache.
 */
final class CacheClearCommand implements CommandInterface
{
    public function execute(array $args, $outputStream = STDOUT, $errorStream = STDERR): int
    {
        $c = [CliFormatter::class, 'color'];
        $count = CacheManager::clear();

        fwrite($outputStream, "\n  " . $c(' TYPEPHP ', 'badge') . ' ' . $c('Cache Clear', 'bold') . "\n\n");
        fwrite($outputStream, '  ' . $c('✓', 'green') . ' Cleared ' . $c((string) $count, 'bold') . " cached file(s).\n\n");

        return 0;
    }
}
