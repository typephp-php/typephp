<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast;

use TypePHP\Internal\Generics\TemplateManager;

/**
 * @internal ensures proper scope cleanup for variable tracking.
 */
final class ScopeCleaner
{
    public function __construct(private string $function)
    {
    }

    public function __destruct()
    {
        TemplateManager::popCallFrame($this->function);
    }
}
