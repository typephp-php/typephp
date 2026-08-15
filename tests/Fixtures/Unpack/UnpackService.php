<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Unpack;

class UnpackService
{
    /**
     * @param positive-int $id
     * @param non-empty-string $username
     * @param 'admin'|'editor'|'viewer' $role
     * @param bool $active
     */
    public function configureUser(int $id, string $username, string $role = 'viewer', bool $active = true): array
    {
        return [
            'id' => $id,
            'username' => $username,
            'role' => $role,
            'active' => $active,
        ];
    }

    /**
     * @param positive-int ...$scores
     */
    public function sumScores(int ...$scores): int
    {
        return array_sum($scores);
    }
}