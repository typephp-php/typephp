<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types\Imported;

/**
 * @phpstan-import-type PluginInfo from KernelPluginLoader
 * @phpstan-type SharedConfig array{retries: positive-int, strict: bool}
 */
class DbalKernelPluginLoader extends KernelPluginLoader
{
    /** 
     * Tests child overriding a parent's type alias 
     * (SharedConfig in parent was just array{retries: int})
     * 
     * @var SharedConfig 
     */
    public array $config = ['retries' => 3, 'strict' => true];

    public function load(): void
    {
        $this->pluginInfos = [
            ['name' => 'SwagPayPal', 'active' => true],
        ];
    }

    public function loadBad(): void
    {
        $this->pluginInfos = [
            ['name' => 'SwagPayPal', 'active' => 'yes'],
        ];
    }
}