<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

use stdClass;

abstract class BaseEntityFactory
{
    /**
     * @return static
     */
    public static function create(): static
    {
        return new static();
    }

    /**
     * @return static
     */
    public static function createWrongInstance(): object
    {
        return new stdClass();
    }

    /**
     * Returns an instance of a sibling class rather than the called late-static class
     *
     * @return static
     */
    public static function createSibling(): object
    {
        return new AdminEntityFactory();
    }

    /**
     * @param positive-int $count
     *
     * @return list<static>
     */
    public static function createBatch(int $count): array
    {
        $batch = [];
        for ($i = 0; $i < $count; $i++) {
            $batch[] = new static();
        }

        return $batch;
    }

    /**
     * @return list<static>
     */
    public static function createBadBatch(): array
    {
        return [new static(), new stdClass()];
    }

    /**
     * Instance method returning static
     *
     * @return static
     */
    public function withSetting(string $key): static
    {
        return $this;
    }

    /**
     * Instance method returning wrong sibling instance
     *
     * @return static
     */
    public function withBadSetting(): object
    {
        return new AdminEntityFactory();
    }
}