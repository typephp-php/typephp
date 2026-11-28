<?php

declare(strict_types=1);

namespace TypePHP\Internal\Resolver;

use Closure;
use ReflectionClass;
use ReflectionFunction;
use Throwable;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\PathMatcher;

/**
 * @internal Resolves whether a type check failure should be bypassed because it occurred at a vendor-to-vendor boundary.
 */
final class CallerBoundaryResolver
{
    /**
     * @var array<string, bool> functionName => isCalleeVendor
     */
    private static array $calleeVendorCache = [];

    /**
     * @var array<string, bool> normalizedFilePath => isCallerVendor
     */
    private static array $callerVendorCache = [];

    /**
     * Fast-path cache for functions guaranteed not to require boundary bypass
     * (either callee is non-vendor, or vendor_boundary_only is disabled).
     *
     * @var array<string, true>
     */
    public static array $nonBypassCalleeCache = [];

    /**
     * @var array<string, bool> rawFilePath => isVendorFile
     */
    private static array $vendorFileCache = [];

    public static function reset(): void
    {
        self::$calleeVendorCache = [];
        self::$callerVendorCache = [];
        self::$nonBypassCalleeCache = [];
        self::$vendorFileCache = [];
    }

    /**
     * Checks if a source file path is located inside a vendor directory (memoized).
     */
    public static function isVendorFile(string $file): bool
    {
        if ($file === '') {
            return false;
        }

        return self::$vendorFileCache[$file] ??= PathMatcher::isVendorPath(PathMatcher::normalizePath($file));
    }

    /**
     * Determines whether a type check failure on a function/method should be bypassed.
     * Only bypasses if the callee is located in a vendor path AND the caller is also from vendor.
     */
    public static function shouldBypass(string $function): bool
    {
        if (isset(self::$nonBypassCalleeCache[$function])) {
            return false;
        }

        if (! Config::isVendorBoundaryOnlyEnabled() || ! self::isCalleeVendor($function)) {
            self::$nonBypassCalleeCache[$function] = true;

            return false;
        }

        return self::isCallerVendor();
    }

    /**
     * Determines whether a type check failure inside a wrapped callback/closure should be bypassed.
     */
    public static function shouldBypassCallback(mixed $callable, string $prefix = ''): bool
    {
        if (! Config::isVendorBoundaryOnlyEnabled()) {
            return false;
        }

        if ($callable instanceof Closure) {
            try {
                $ref = new ReflectionFunction($callable);
                $file = $ref->getFileName();
                if ($file !== false) {
                    $normalized = PathMatcher::normalizePath($file);

                    return PathMatcher::isVendorPath($normalized);
                }
            } catch (Throwable $e) {
            }
        }

        return self::isCallerVendor();
    }

    /**
     * Checks if the function or method being executed belongs to a vendor file.
     */
    public static function isCalleeVendor(string $function): bool
    {
        if (isset(self::$calleeVendorCache[$function])) {
            return self::$calleeVendorCache[$function];
        }

        $fileName = null;

        try {
            if (str_contains($function, '::')) {
                [$class, $member] = explode('::', $function, 2);
                if (class_exists($class) || interface_exists($class) || trait_exists($class)) {
                    /** @var class-string<object> $class */
                    $ref = new ReflectionClass($class);
                    if (str_starts_with($member, '$')) {
                        $propName = substr($member, 1);
                        $fileName = $ref->hasProperty($propName)
                            ? $ref->getProperty($propName)->getDeclaringClass()->getFileName()
                            : $ref->getFileName();
                    } elseif ($member !== '' && $ref->hasMethod($member)) {
                        $fileName = $ref->getMethod($member)->getFileName();
                    } else {
                        $fileName = $ref->getFileName();
                    }
                }
            } elseif (\function_exists($function)) {
                $ref = new ReflectionFunction($function);
                $fileName = $ref->getFileName();
            }
        } catch (Throwable $e) {
        }

        if ($fileName === null || $fileName === false) {
            return self::$calleeVendorCache[$function] = false;
        }

        $normalized = PathMatcher::normalizePath($fileName);

        return self::$calleeVendorCache[$function] = PathMatcher::isVendorPath($normalized);
    }

    /**
     * Inspects the call stack to see if the caller originated from vendor code.
     */
    public static function isCallerVendor(): bool
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

        for ($i = 1; $i < \count($trace); $i++) {
            $frame = $trace[$i];
            $class = $frame['class'] ?? '';
            $function = $frame['function'];
            $file = $frame['file'] ?? '';
            if ($class !== '' && (str_starts_with($class, 'TypePHP\\Internal\\') || $class === 'TypePHP\\TypePHP')) {
                continue;
            }

            if ($class === '' && \in_array($function, ['call_user_func', 'call_user_func_array'], true)) {
                continue;
            }

            if ($file === '') {
                continue;
            }

            $normalizedFile = PathMatcher::normalizePath($file);

            if (PathMatcher::isLibraryInternal($normalizedFile)) {
                continue;
            }

            if (
                str_contains($normalizedFile, '/vendor/phpunit/')
                || str_contains($normalizedFile, '/vendor/pestphp/')
            ) {
                continue;
            }

            if (isset(self::$callerVendorCache[$normalizedFile])) {
                return self::$callerVendorCache[$normalizedFile];
            }

            $isVendor = PathMatcher::isVendorPath($normalizedFile);

            return self::$callerVendorCache[$normalizedFile] = $isVendor;
        }

        return false;
    }
}
