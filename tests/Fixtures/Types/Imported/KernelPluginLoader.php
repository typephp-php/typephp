<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types\Imported;

/**
 * @phpstan-type PluginInfo array{name: string, active: bool}
 * @phpstan-type SharedConfig array{retries: int}
 */
abstract class KernelPluginLoader
{
    /**
     * @var list<PluginInfo>
     */
    public array $pluginInfos = [];
}
