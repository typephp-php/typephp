<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Pipes;

class PipePipelineService
{
    /**
     * @param positive-int $id
     *
     * @return positive-int
     */
    public function doubleId(int $id): int
    {
        return $id * 2;
    }

    /**
     * @param positive-int $id
     *
     * @return non-empty-string
     */
    public function stringify(int $id): string
    {
        return "RECORD_{$id}";
    }

    /**
     * @param non-empty-string $tag
     *
     * @return non-empty-string
     */
    public function prefixTag(string $tag): string
    {
        return "[TAG] {$tag}";
    }
}