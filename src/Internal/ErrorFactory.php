<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use ReflectionClass;
use Throwable;
use TypeError;

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
     * For parameter, callback, iterator, and generator errors, it filters out internal library frames
     * and sets the file and line to accurately blame the caller site.
     */
    public static function prepareException(TypeError $e, ?int $line = null): TypeError
    {
        $targetFile = null;
        $targetLine = $line;

        $message = $e->getMessage();
        $isCallSiteError = str_contains($message, 'Argument $')
            || str_contains($message, 'argument #')
            || str_contains($message, 'Callback ')
            || str_contains($message, 'Iterator $')
            || str_contains($message, 'Return iterator')
            || str_contains($message, 'Generator sent value');

        if ($isCallSiteError) {
            $trace = $e->getTrace();

            foreach ($trace as $frame) {
                if (isset($frame['file'], $frame['line'])) {
                    $file = str_replace('\\', '/', $frame['file']);

                    $isInternal = str_contains($file, 'src/Internal/')
                        || str_contains($file, 'src/Wrapper/')
                        || str_contains($file, 'src/Validator/')
                        || str_contains($file, 'src/Resolver/')
                        || str_contains($file, 'src/Contract/');

                    if (! $isInternal) {
                        $targetFile = $frame['file'];
                        if ($targetLine === null) {
                            $targetLine = $frame['line'];
                        }

                        break;
                    }
                }
            }
        }

        try {
            $ref = new ReflectionClass(\Error::class);

            if ($targetFile !== null) {
                $propFile = $ref->getProperty('file');
                $propFile->setValue($e, $targetFile);
            }

            if ($targetLine !== null) {
                $propLine = $ref->getProperty('line');
                $propLine->setValue($e, $targetLine);
            }
        } catch (Throwable $err) {
            // Silently fallback if reflection mutation fails
        }

        return $e;
    }
}
