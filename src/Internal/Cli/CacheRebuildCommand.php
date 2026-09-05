<?php

declare(strict_types=1);

namespace TypePHP\Internal\Cli;

/**
 * @internal Rebuilds the TypePHP cache.
 */
final class CacheRebuildCommand implements CommandInterface
{
    public function execute(array $args, $outputStream = STDOUT, $errorStream = STDERR): int
    {
        (new CacheClearCommand())->execute($args, $outputStream, $errorStream);
        (new CacheWarmCommand())->execute($args, $outputStream, $errorStream);

        return 0;
    }
}
