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
    private const INTERNAL_DIR_PATTERNS = [
        'src/Internal/',
        'src/Wrapper/',
        'src/Validator/',
        'src/Resolver/',
        'src/Contract/',
        'src/Command/',
        'bin/typephp',
    ];

    private const CALL_SITE_KEYWORDS = [
        'argument $',
        'argument #',
        'callback ',
        'iterator $',
        'return iterator',
        'generator sent value',
    ];

    /**
     * Creates an ErrorMessage value object containing formatted type failure details.
     */
    public static function createError(string $message): ErrorMessage
    {
        if (str_contains($message, 'Return value')) {
            if (str_ends_with($message, 'null given')) {
                $message = substr($message, 0, -\strlen('null given')) . 'none returned';
            } elseif (str_ends_with($message, ' given')) {
                $message = substr($message, 0, -\strlen(' given')) . ' returned';
            }
        }

        return new ErrorMessage($message);
    }

    /**
     * Prepares a TypeError exception before throwing by filtering internal library frames
     * and repointing the exception to the actual application caller location.
     */
    public static function prepareException(TypeError $e, ?int $line = null): TypeError
    {
        $targetFile = null;
        $targetLine = $line;

        $message = $e->getMessage();
        $isCallSite = self::isCallSiteError(strtolower($message));

        $filteredTrace = self::filterTrace($e->getTrace(), $isCallSite, $targetFile, $targetLine);

        $sanitizedMessage = self::sanitizeMessage($message, $targetFile, $targetLine);

        self::mutateException($e, $sanitizedMessage, $targetFile, $targetLine, $filteredTrace);

        return $e;
    }

    /**
     * Checks if the error message indicates a caller argument or callback error.
     */
    private static function isCallSiteError(string $lowerMessage): bool
    {
        foreach (self::CALL_SITE_KEYWORDS as $keyword) {
            if (str_contains($lowerMessage, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filters out internal TypePHP frames from the raw stack trace.
     *
     * @param list<array<string, mixed>> $trace
     *
     * @return list<array<string, mixed>>
     */
    private static function filterTrace(
        array $trace,
        bool $isCallSite,
        ?string &$targetFile,
        ?int &$targetLine
    ): array {
        $filtered = [];

        foreach ($trace as $frame) {
            $file = isset($frame['file']) && \is_string($frame['file'])
                ? str_replace('\\', '/', $frame['file'])
                : '';

            if (self::isInternalFile($file)) {
                continue;
            }

            // Sanitize internal closure class prefixes from remaining frames
            if (isset($frame['class']) && \is_string($frame['class']) && str_starts_with($frame['class'], 'TypePHP\\')) {
                unset($frame['class'], $frame['type']);
                $frame['function'] = '{closure}';
            }

            $filtered[] = $frame;

            if ($isCallSite && $targetFile === null && isset($frame['file'], $frame['line']) && \is_string($frame['file']) && \is_int($frame['line'])) {
                $targetFile = $frame['file'];
                if ($targetLine === null) {
                    $targetLine = $frame['line'];
                }
            }
        }

        return $filtered;
    }

    /**
     * Determines whether a file path belongs to TypePHP internals.
     */
    private static function isInternalFile(string $normalizedFile): bool
    {
        if ($normalizedFile === '') {
            return false;
        }

        foreach (self::INTERNAL_DIR_PATTERNS as $pattern) {
            if (str_contains($normalizedFile, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strips internal wrapper paths and CLI runner prefixes from PHP's error message.
     */
    private static function sanitizeMessage(string $message, ?string $targetFile, ?int $targetLine): string
    {
        if (str_contains($message, 'CallableWrapper.php')) {
            $cleaned = (string) preg_replace('/, called in .*?CallableWrapper\.php on line \d+/i', '', $message);
            if ($targetFile !== null && $targetLine !== null) {
                $cleaned .= ", called in {$targetFile} on line {$targetLine}";
            }
            $message = $cleaned;
        }

        return str_replace('TypePHP\Command\RunCommand::', '', $message);
    }

    /**
     * Uses reflection on base \Error to mutate private properties safely.
     *
     * @param list<array<string, mixed>> $filteredTrace
     */
    private static function mutateException(
        TypeError $e,
        string $message,
        ?string $targetFile,
        ?int $targetLine,
        array $filteredTrace
    ): void {
        try {
            $ref = new ReflectionClass(\Error::class);

            $propMessage = $ref->getProperty('message');
            $propMessage->setValue($e, $message);

            if ($targetFile !== null) {
                $propFile = $ref->getProperty('file');
                $propFile->setValue($e, $targetFile);
            }

            if ($targetLine !== null) {
                $propLine = $ref->getProperty('line');
                $propLine->setValue($e, $targetLine);
            }

            if (\count($filteredTrace) > 0) {
                $propTrace = $ref->getProperty('trace');
                $propTrace->setValue($e, $filteredTrace);
            }
        } catch (Throwable $err) {
            // Silently fallback if reflection mutation fails
        }
    }
}
