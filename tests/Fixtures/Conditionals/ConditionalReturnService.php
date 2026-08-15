<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Conditionals;

use TypePHP\Tests\Fixtures\Domain\Animal;

class ConditionalReturnService
{
    /**
     * Deep 4-branch parameter conditional return
     *
     * @param string $format
     * @param mixed $value
     *
     * @return ($format is 'int' ? positive-int : ($format is 'float' ? positive-float : ($format is 'bool' ? bool : ($format is 'list' ? list<positive-int> : non-empty-string))))
     */
    public function formatByParameter(string $format, mixed $value): mixed
    {
        return $value;
    }

    /**
     * Mixed parameter and generic template conditional return
     *
     * @template T of Animal
     *
     * @param bool $wrapInList
     * @param T $animal
     * @param mixed $output
     *
     * @return ($wrapInList is true ? list<T> : T)
     */
    public function wrapOrReturn(bool $wrapInList, Animal $animal, mixed $output): mixed
    {
        return $output;
    }

    /**
     * Parameter negation conditional return ($flag is not true)
     *
     * @param bool $flag
     * @param mixed $value
     *
     * @return ($flag is not true ? non-empty-string : positive-int)
     */
    public function formatByNegation(bool $flag, mixed $value): mixed
    {
        return $value;
    }
}