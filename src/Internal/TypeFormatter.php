<?php

declare(strict_types=1);

namespace TypePHP\Internal;

/**
 * @internal
 */
final class TypeFormatter
{
    public static function formatGivenValue(mixed $value): string
    {
        if (\is_int($value)) {
            if ($value < 0) {
                return "negative int ($value)";
            }
            if ($value === 0) {
                return 'zero int (0)';
            }

            return "int ($value)";
        }

        if (\is_float($value)) {
            return "float ($value)";
        }

        if (\is_string($value)) {
            if ($value === '') {
                return "empty string ('')";
            }
            if (\strlen($value) > 20) {
                return "string '" . substr($value, 0, 17) . "...'";
            }

            return "string '$value'";
        }

        if (\is_array($value)) {
            if (\count($value) === 0) {
                return 'empty array ([])';
            }
            if (array_is_list($value)) {
                return 'list (' . \count($value) . ' items)';
            }

            $keys = array_keys($value);
            $firstStringKey = null;
            $firstNonSequentialIndex = null;

            foreach ($keys as $idx => $key) {
                if (\is_string($key)) {
                    $firstStringKey = $key;

                    break;
                }
                if ($key !== $idx && $firstNonSequentialIndex === null) {
                    $firstNonSequentialIndex = $key;
                }
            }

            if ($firstStringKey !== null) {
                return "associative array (key '$firstStringKey')";
            }

            if ($firstNonSequentialIndex !== null) {
                return "non-sequential array (index $firstNonSequentialIndex)";
            }

            return 'array (' . \count($value) . ' items)';
        }

        if (\is_bool($value)) {
            return $value ? 'bool (true)' : 'bool (false)';
        }

        return get_debug_type($value);
    }
}
