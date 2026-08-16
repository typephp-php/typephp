<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Collections;

/**
 * @phpstan-template-covariant T
 */
interface CovariantProducerInterface
{
    /**
     * @return T
     */
    public function get(): mixed;
}
