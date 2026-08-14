<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

class InvokableFormatterService
{
    public function __invoke(int $id): string
    {
        if ($id <= 0) {
            return ''; // Violates non-empty-string
        }

        return "invoked_{$id}";
    }
}
