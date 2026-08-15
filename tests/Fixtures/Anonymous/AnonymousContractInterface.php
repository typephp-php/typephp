<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Anonymous;

interface AnonymousContractInterface
{
    /**
     * @param positive-int $id
     * @param non-empty-string $name
     *
     * @return array{id: positive-int, name: non-empty-string}
     */
    public function formatUser(int $id, string $name): array;
}