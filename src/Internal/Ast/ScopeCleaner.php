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

    /**
     * Cached resolved yield, send, and return contract tuple for the current generator execution scope.
     *
     * @var array{0: ?TypeNode, 1: ?TypeNode, 2: ?TypeNode, 3: ?TypeNode, 4: int, 5: mixed, 6: int, 7: mixed}|null
     */
    public ?array $resolvedYieldTypes = null;

    public function __construct(private string $function)
    {
    }

    public function __destruct()
    {
        TemplateManager::popCallFrame($this->function);
    }
}
