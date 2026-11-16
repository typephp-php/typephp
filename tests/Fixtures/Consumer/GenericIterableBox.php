<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Consumer;

/**
 * @template K
 * @template V
 */
final class GenericIterableBox
{
    /**
     * @var array<string|int, V>
     */
    private array $values = [];

    /**
     * @template NK
     * @template NV
     *
     * @param iterable<NK, NV> $source
     *
     * @return self<NK, NV>
     */
    public static function fromIterable(iterable $source): self
    {
        /** @var self<NK, NV> $box */
        $box = new self();
        foreach ($source as $k => $v) {
            $box->values[$k] = $v;
        }

        return $box;
    }

    /**
     * @return array<string|int, V>
     */
    public function values(): array
    {
        return $this->values;
    }
}
