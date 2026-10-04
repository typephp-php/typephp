<?php

declare(strict_types=1);

namespace TypePHP\Internal\Util;

use stdClass;

/**
 * @internal Navigates and mutates a data structure along an accessor chain on an isolated copy.
 */
final class StructureMutator
{
    /**
     * Applies a prospective mutation along an accessor chain on an isolated copy of root.
     *
     * @param list<array{0: 'dim'|'prop', 1: mixed}> $chain
     */
    public static function mutateCopy(mixed $root, array $chain, mixed $value): mixed
    {
        $copy = \is_object($root) ? clone $root : $root;

        $current = &$copy;
        $count = \count($chain);

        for ($i = 0; $i < $count; $i++) {
            [$kind, $key] = $chain[$i];
            $isLast = ($i === $count - 1);

            if ($kind === 'dim') {
                if ($key === null) {
                    if (! \is_array($current)) {
                        $current = [];
                    }
                    if ($isLast) {
                        $current[] = $value;
                    } else {
                        $current[] = [];
                        $keys = array_keys($current);
                        $lastKey = end($keys);
                        $current = &$current[$lastKey];
                    }
                } else {
                    $arrayKey = \is_int($key) ? $key : (\is_string($key) ? $key : '');
                    if (! \is_array($current)) {
                        $current = [];
                    }

                    if ($isLast) {
                        $current[$arrayKey] = $value;
                    } else {
                        if (! isset($current[$arrayKey]) || (! \is_array($current[$arrayKey]) && ! \is_object($current[$arrayKey]))) {
                            $current[$arrayKey] = [];
                        } elseif (\is_object($current[$arrayKey])) {
                            $current[$arrayKey] = clone $current[$arrayKey];
                        }
                        $current = &$current[$arrayKey];
                    }
                }
            } elseif ($kind === 'prop') {
                $propName = \is_string($key) ? $key : (\is_int($key) ? (string) $key : '');
                if (! \is_object($current)) {
                    $current = new stdClass();
                }

                if ($isLast) {
                    // @phpstan-ignore property.dynamicName
                    $current->$propName = $value;
                } else {
                    // @phpstan-ignore property.dynamicName
                    $propVal = $current->$propName ?? null;
                    if (! \is_object($propVal) && ! \is_array($propVal)) {
                        // @phpstan-ignore property.dynamicName
                        $current->$propName = new stdClass();
                    } elseif (\is_object($propVal)) {
                        // @phpstan-ignore property.dynamicName
                        $current->$propName = clone $propVal;
                    }
                    // @phpstan-ignore property.dynamicName
                    $current = &$current->$propName;
                }
            }
        }
        unset($current);

        return $copy;
    }
}
