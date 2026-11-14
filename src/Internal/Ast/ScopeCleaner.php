<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Generics\TemplateManager;

/**
 * @internal ensures proper scope cleanup for variable tracking.
 */
final class ScopeCleaner
{
    /**
     * Captured template bindings specific to this execution scope.
     *
     * @var array<string, TypeNode>
     */
    public array $boundTemplates = [];

    public function __construct(private string $function)
    {
    }

    public function __destruct()
    {
        TemplateManager::popCallFrame($this->function);
    }
}
