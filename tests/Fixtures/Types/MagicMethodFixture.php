<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\Producer;

/**
 * @phpstan-type LocalUserShape array{id: positive-int, role: 'admin'|'user'}
 * @phpstan-type PayloadShape array{id: positive-int, tags: list<non-empty-string>}
 * @phpstan-type StatusUnion 'active'|'pending'
 *
 * @method positive-int processId(positive-int $id, non-empty-string $name)
 * @method static list<int> fetchList(int ...$items)
 * @method PayloadShape buildPayload(list<positive-int> $ids, StatusUnion $status)
 * @method Producer<Dog> getProducer(Producer<Dog> $producer)
 * @method bool checkCollection((\Countable&\ArrayAccess)|null $collection)
 * @method LocalUserShape saveUser(LocalUserShape $user)
 */
class MagicMethodFixture
{
    public function __call(string $name, array $arguments): mixed
    {
        if ($name === 'processId') {
            return $arguments[0] ?? null;
        }

        if ($name === 'buildPayload') {
            $ids = $arguments[0] ?? [];

            return [
                'id' => $ids[0] ?? 1,
                'tags' => ['php', 'typephp'],
            ];
        }

        if ($name === 'getProducer') {
            return $arguments[0] ?? null;
        }

        if ($name === 'checkCollection') {
            return true;
        }

        if ($name === 'saveUser') {
            return $arguments[0] ?? null;
        }

        return null;
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        if ($name === 'fetchList') {
            return $arguments;
        }

        return null;
    }
}
