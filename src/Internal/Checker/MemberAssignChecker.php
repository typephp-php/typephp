<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\StructureMutator;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

/**
 * @internal Evaluates array key/dimension and object shape property member assignments.
 */
final class MemberAssignChecker
{
    /**
     * Evaluates member mutation assignment against the declared type contract.
     *
     * @param list<array{0: 'dim'|'prop', 1: mixed}> $chain
     */
    public static function check(
        mixed $root,
        array $chain,
        mixed $value,
        string $typeString,
        string $varName,
        string $file,
        TypeValidatorRegistry $registry,
        ?string $caller = null,
        mixed $thisOrClass = null
    ): mixed {
        if (Config::isArrayValidationHybrid() && ! str_contains($typeString, '{') && \count($chain) === 1 && $chain[0][0] === 'dim') {
            $directErr = InlineChecker::validateCollectionElementMutation(
                $value,
                $chain,
                $typeString,
                $varName,
                $file,
                $registry
            );

            if ($directErr instanceof ErrorMessage) {
                return $directErr;
            }

            return $value;
        }

        $copy = StructureMutator::mutateCopy($root, $chain, $value);

        $res = InlineChecker::checkVariable(
            $copy,
            $typeString,
            $varName,
            $file,
            $registry,
            $caller,
            $thisOrClass
        );

        if ($res instanceof ErrorMessage) {
            return $res;
        }

        return $value;
    }
}
