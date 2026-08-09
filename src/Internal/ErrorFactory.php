<?php

declare(strict_types=1);

namespace TypePHP\Internal;

/**
 * @internal Factory creating ErrorMessage value objects and preparing TypeError instances with exact caller traces.
 */
final class ErrorFactory
{
    /**
     * Creates an ErrorMessage value object containing formatted type failure details.
     */
    public static function createError(string $message): ErrorMessage
    {
        if (str_contains($message, 'Return value')) {
            $message = str_replace(['null given', ' given'], ['none returned', ' returned'], $message);
        }

        return new ErrorMessage($message);
    }

    /**
     * Prepares a TypeError exception before throwing.
     * For parameter and callback argument errors, it filters out internal library frames
     * and sets the file and line to accurately blame the caller site.
     */
    public static function prepareException(\TypeError $e, ?int $line = null): \TypeError
    {
        $ref = new \ReflectionObject($e);

        if ($line !== null && $ref->hasProperty('line')) {
            $propLine = $ref->getProperty('line');
            $propLine->setValue($e, $line);
        }

        $message = $e->getMessage();
        $isCallSiteError = str_contains($message, 'Argument $')
            || str_contains($message, 'argument #')
            || str_contains($message, 'Callback argument');

        if ($isCallSiteError) {
            $trace = $e->getTrace();

            foreach ($trace as $frame) {
                if (isset($frame['file'], $frame['line'])) {
                    $file = str_replace('\\', '/', $frame['file']);

                    if (! str_contains($file, 'Internal/ErrorFactory.php') && ! str_contains($file, 'Wrapper/CallableWrapper.php')) {
                        if ($ref->hasProperty('file')) {
                            $propFile = $ref->getProperty('file');
                            $propFile->setValue($e, $frame['file']);
                        }

                        if ($line === null && $ref->hasProperty('line')) {
                            $propLine = $ref->getProperty('line');
                            $propLine->setValue($e, $frame['line']);
                        }

                        break;
                    }
                }
            }
        }

        return $e;
    }
}
