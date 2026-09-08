<?php

declare(strict_types=1);

namespace TypePHP\Internal\Util;

use ReflectionClass;
use ReflectionFunction;
use Throwable;

/**
 * @internal High-performance manager for resolving file-level and caller-level @typephp-ignore tags.
 */
final class IgnoreManager
{
    /**
     * In-memory cache for caller method ignore decisions (ClassName::method => bool).
     *
     * @var array<string, bool>
     */
    private static array $callerCache = [];

    /**
     * In-memory registry of files marked with @typephp-ignore-file.
     *
     * @var array<string, bool>
     */
    private static array $fileCache = [];

    /**
     * Resets all ignore caches.
     */
    public static function reset(): void
    {
        self::$callerCache = [];
        self::$fileCache = [];
    }

    /**
     * Registers a file as ignored at the StreamWrapper level during file load.
     */
    public static function registerIgnoredFile(string $filePath): void
    {
        if ($filePath === '') {
            return;
        }

        $normalized = str_replace('\\', '/', $filePath);
        self::$fileCache[$normalized] = true;
    }

    /**
     * Fast O(1) check if a file has @typephp-ignore-file in memory.
     */
    public static function isFileIgnored(string $filePath): bool
    {
        if ($filePath === '' || self::$fileCache === []) {
            return false;
        }

        $normalized = str_replace('\\', '/', $filePath);

        return self::$fileCache[$normalized] ?? false;
    }

    /**
     * Determines whether the calling method, function, or file has @typephp-ignore annotations.
     * Supports optional explicit caller overrides for direct inspection.
     */
    public static function isCallerIgnored(?string $callerClass = null, ?string $callerFunction = null): bool
    {
        if (! Config::isRespectIgnoreTagsEnabled()) {
            return false;
        }

        if ($callerClass !== null && $callerFunction !== null) {
            $key = $callerClass . '::' . $callerFunction;
            if (isset(self::$callerCache[$key])) {
                return self::$callerCache[$key];
            }

            return self::$callerCache[$key] = self::checkMethodIgnored($callerClass, $callerFunction);
        }

        if ($callerFunction !== null) {
            if (isset(self::$callerCache[$callerFunction])) {
                return self::$callerCache[$callerFunction];
            }

            return self::$callerCache[$callerFunction] = self::checkFunctionIgnored($callerFunction);
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 7);

        for ($i = 1; $i < \count($trace); $i++) {
            $frame = $trace[$i];
            $class = $frame['class'] ?? '';
            $function = $frame['function'] ?? '';
            $file = $frame['file'] ?? '';

            if ($class !== '' && (str_starts_with($class, 'TypePHP\\Internal\\') || $class === 'TypePHP\\TypePHP')) {
                continue;
            }

            if ($class === '' && \in_array($function, ['call_user_func', 'call_user_func_array'], true)) {
                continue;
            }

            if ($file !== '' && self::isFileIgnored($file)) {
                return true;
            }

            if ($class !== '' && $function !== '') {
                $key = $class . '::' . $function;
                if (isset(self::$callerCache[$key])) {
                    if (self::$callerCache[$key]) {
                        return true;
                    }

                    continue;
                }

                if (self::checkMethodIgnored($class, $function)) {
                    return self::$callerCache[$key] = true;
                }

                self::$callerCache[$key] = false;
            } elseif ($function !== '' && ! str_contains($function, '{closure}')) {
                if (isset(self::$callerCache[$function])) {
                    if (self::$callerCache[$function]) {
                        return true;
                    }

                    continue;
                }

                if (self::checkFunctionIgnored($function)) {
                    return self::$callerCache[$function] = true;
                }

                self::$callerCache[$function] = false;
            }
        }

        return false;
    }

    private static function checkMethodIgnored(string $class, string $method): bool
    {
        if (StubManager::hasMethodStub($class, $method)) {
            $stubDoc = StubManager::getMethodDoc($class, $method);
            if ($stubDoc !== null && (str_contains($stubDoc, '@typephp-ignore') || str_contains($stubDoc, '@typephp-disable'))) {
                return true;
            }
        }

        if (StubManager::hasClassStub($class)) {
            $classStubDoc = StubManager::getClassDoc($class);
            if ($classStubDoc !== null && (str_contains($classStubDoc, '@typephp-ignore') || str_contains($classStubDoc, '@typephp-disable'))) {
                return true;
            }
        }

        if (! class_exists($class) && ! trait_exists($class) && ! interface_exists($class) && ! enum_exists($class)) {
            return false;
        }

        try {
            /** @var class-string<object> $class */
            $refClass = new ReflectionClass($class);

            if ($refClass->hasMethod($method)) {
                $refMethod = $refClass->getMethod($method);
                $doc = $refMethod->getDocComment();
                if ($doc !== false && $doc !== null && (
                    str_contains($doc, '@typephp-ignore')
                    || str_contains($doc, '@typephp-disable')
                )) {
                    return true;
                }
            }

            $classDoc = $refClass->getDocComment();
            if ($classDoc !== false && $classDoc !== null && (
                str_contains($classDoc, '@typephp-ignore')
                || str_contains($classDoc, '@typephp-disable')
            )) {
                return true;
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }

    private static function checkFunctionIgnored(string $function): bool
    {
        if (StubManager::hasFunctionStub($function)) {
            $stubDoc = StubManager::getFunctionDoc($function);
            if ($stubDoc !== null && (str_contains($stubDoc, '@typephp-ignore') || str_contains($stubDoc, '@typephp-disable'))) {
                return true;
            }
        }

        if (! \function_exists($function)) {
            return false;
        }

        try {
            $refFunc = new ReflectionFunction($function);
            $doc = $refFunc->getDocComment();
            if ($doc !== false && $doc !== null && (
                str_contains($doc, '@typephp-ignore')
                || str_contains($doc, '@typephp-disable')
            )) {
                return true;
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }
}