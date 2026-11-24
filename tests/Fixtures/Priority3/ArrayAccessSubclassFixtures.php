<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Priority3;

use ArrayObject;

/**
 * @extends ArrayObject<string, int>
 */
class Priority3IntArrayMap extends ArrayObject
{
}

/**
 * @template V
 *
 * @extends ArrayObject<string, V>
 */
class Priority3StringKeyedMap extends ArrayObject
{
}

class Priority3ArrayAccessHolder
{
    /**
     * @var Priority3IntArrayMap
     */
    public Priority3IntArrayMap $map;

    /**
     * @var Priority3StringKeyedMap<int>
     */
    public Priority3StringKeyedMap $genericMap;

    public function __construct()
    {
        $this->map = new Priority3IntArrayMap(['a' => 10]);
        $this->genericMap = new Priority3StringKeyedMap(['a' => 10]);
    }

    public function mutateLocalMap(int|string $key, mixed $value): void
    {
        /** @var Priority3IntArrayMap $localMap */
        $localMap = new Priority3IntArrayMap(['a' => 10]);
        $localMap[$key] = $value;
    }
}
