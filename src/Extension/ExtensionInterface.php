<?php

declare(strict_types=1);

namespace TypePHP\Extension;

/**
 * Interface for third-party extensions providing automatic configuration overrides (include paths and stub files).
 */
interface ExtensionInterface
{
    /**
     * Returns configuration array (include and stubs) to merge into TypePHP.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array;
}
