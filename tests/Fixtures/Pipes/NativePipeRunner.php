<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Pipes;

class NativePipeRunner
{
    public function runPipeline(int $initialId, PipePipelineService $service): string
    {
        return $initialId
            |> $service->doubleId(...)
            |> $service->stringify(...)
            |> $service->prefixTag(...);
    }

    public function runStandalonePipeline(int $id): string
    {
        return $id
            |> $this->stepOne(...)
            |> $this->stepTwo(...);
    }

    /**
     * @param positive-int $id
     *
     * @return positive-int
     */
    public function stepOne(int $id): int
    {
        return $id + 10;
    }

    /**
     * @param positive-int $id
     *
     * @return non-empty-string
     */
    public function stepTwo(int $id): string
    {
        return "piped_user_{$id}";
    }
}