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

class Priority3ArrayAccessHolder
{
    /**
     * @var Priority3IntArrayMap
     */
    public Priority3IntArrayMap $map;

    public function __construct()
    {
        $this->map = new Priority3IntArrayMap(['a' => 10]);
    }
}
