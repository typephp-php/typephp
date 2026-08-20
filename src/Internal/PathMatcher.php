<?php

declare(strict_types=1);

namespace TypePHP\Internal;

/**
 * @internal Centralized utility for path normalization, glob compilation, vendor isolation, and specificity matching.
 */
final class PathMatcher
{
    /**
     * Cache for compiled include regexes per base directory.
     *
     * @var array<string, array<int, array{pattern: string, len: int, regex: string}>>
     */
    private static array $compiledIncludesCache = [];

    /**
     * Cache for compiled exclude regexes per base directory.
     *
     * @var array<string, array<int, array{pattern: string, len: int, regex: string}>>
     */
    private static array $compiledExcludesCache = [];

    /**
     * Cache for include prefix lookup decisions.
     *
     * @var array<string, bool>
     */
    private static array $includePrefixCache = [];

    /**
     * Cached normalized cache directory path.
     */
    private static ?string $cachedCacheDir = null;

    /**
     * Cached TypePHP library src directory path.
     */
    private static ?string $cachedLibSrcDir = null;

    /**
     * Resets compiled pattern and directory caches.
     */
    public static function reset(): void
    {
        self::$compiledIncludesCache = [];
        self::$compiledExcludesCache = [];
        self::$includePrefixCache = [];
        self::$cachedCacheDir = null;
        self::$cachedLibSrcDir = null;
    }

    /**
     * Normalizes directory separators to forward slashes.
     */
    public static function normalizePath(string|false|null $path): string
    {
        if ($path === null || $path === false || $path === '') {
            return '';
        }

        return str_replace('\\', '/', $path);
    }

    /**
     * Determines whether a given path is located within a vendor directory.
     */
    public static function isVendorPath(string $normalizedPath, string $rawPath = ''): bool
    {
        $normalizedRaw = self::normalizePath($rawPath);

        return str_starts_with($normalizedPath, 'vendor/')
            || str_contains($normalizedPath, '/vendor/')
            || ($normalizedRaw !== '' && (str_starts_with($normalizedRaw, 'vendor/') || str_contains($normalizedRaw, '/vendor/')));
    }

    /**
     * Determines whether a given path is within the TypePHP cache directory.
     */
    public static function isCachePath(string $normalizedPath): bool
    {
        if (self::$cachedCacheDir === null) {
            self::$cachedCacheDir = rtrim(self::normalizePath(CacheManager::getCacheDir()), '/') . '/';
        }

        return str_starts_with($normalizedPath, self::$cachedCacheDir);
    }

    /**
     * Determines whether a path belongs to TypePHP's own internal engine source files.
     */
    public static function isLibraryInternal(string $normalizedPath): bool
    {
        if (self::$cachedLibSrcDir === null) {
            $parentDir = realpath(__DIR__ . '/..');
            self::$cachedLibSrcDir = $parentDir !== false ? rtrim(self::normalizePath($parentDir), '/') . '/' : '';
        }

        $libSrcDir = self::$cachedLibSrcDir;
        if ($libSrcDir === '') {
            return false;
        }

        if (str_contains($libSrcDir, '/vendor/')) {
            return str_starts_with($normalizedPath, $libSrcDir);
        }

        if (str_starts_with($normalizedPath, $libSrcDir)) {
            $internalDirs = [
                $libSrcDir . 'Internal/',
                $libSrcDir . 'Contract/',
                $libSrcDir . 'Command/',
                $libSrcDir . 'Validator/',
                $libSrcDir . 'Wrapper/',
                $libSrcDir . 'Resolver/',
                $libSrcDir . 'Extension/',
                $libSrcDir . 'Exception/',
                $libSrcDir . 'TypePHP.php',
                $libSrcDir . 'bootstrap.php',
            ];

            foreach ($internalDirs as $dir) {
                if (str_starts_with($normalizedPath, $dir)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Fast-checks if an include glob list contains any pattern matching a given prefix.
     *
     * @param array<int, string> $includes
     */
    public static function hasIncludeMatchingPrefix(string $prefix, array $includes): bool
    {
        $cacheKey = $prefix . '|' . implode(',', $includes);
        if (isset(self::$includePrefixCache[$cacheKey])) {
            return self::$includePrefixCache[$cacheKey];
        }

        foreach ($includes as $inc) {
            if (\is_string($inc)) {
                $norm = str_replace('\\', '/', trim($inc));
                if (str_starts_with($norm, $prefix) || str_contains($norm, '/' . $prefix)) {
                    return self::$includePrefixCache[$cacheKey] = true;
                }
            }
        }

        return self::$includePrefixCache[$cacheKey] = false;
    }

    /**
     * Determines whether a directory path is a dynamic writable cache/log directory.
     */
    public static function isDynamicWritablePath(string $normalizedPath): bool
    {
        return str_contains($normalizedPath, '/var/cache/') || str_starts_with($normalizedPath, 'var/cache/')
            || str_contains($normalizedPath, '/var/log/') || str_starts_with($normalizedPath, 'var/log/')
            || str_contains($normalizedPath, '/storage/') || str_starts_with($normalizedPath, 'storage/')
            || str_contains($normalizedPath, '/cache/') || str_starts_with($normalizedPath, 'cache/');
    }

    /**
     * High-speed $O(1)$ string pre-filter to determine if a raw path can possibly be included,
     * while respecting user whitelists for vendor, var, and storage directories.
     */
    public static function mayPathBeIncluded(string $normalizedPath): bool
    {
        if (str_contains($normalizedPath, '/node_modules/') || str_starts_with($normalizedPath, 'node_modules/')) {
            return false;
        }

        if (self::isCachePath($normalizedPath)) {
            return false;
        }

        $config = Config::get();
        /** @var array<int, string> $includes */
        $includes = \is_array($config['include'] ?? null) ? $config['include'] : ['**'];

        if (str_contains($normalizedPath, '/vendor/') || str_starts_with($normalizedPath, 'vendor/')) {
            if (! self::hasIncludeMatchingPrefix('vendor/', $includes)) {
                return false;
            }
        }

        if (str_contains($normalizedPath, '/var/') || str_starts_with($normalizedPath, 'var/')) {
            if (! self::hasIncludeMatchingPrefix('var/', $includes)) {
                return false;
            }
        }

        if (str_contains($normalizedPath, '/storage/') || str_starts_with($normalizedPath, 'storage/')) {
            if (! self::hasIncludeMatchingPrefix('storage/', $includes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Converts a glob pattern into an absolute anchored regex pattern.
     */
    public static function compileGlobToRegex(string $glob, string $baseDir): string
    {
        $glob = self::normalizePath(trim($glob));
        $isAbsolute = str_starts_with($glob, '/') || (bool) preg_match('#^[a-zA-Z]:/#', $glob);

        $regex = preg_quote($glob, '#');
        $regex = str_replace(['\*\*', '\*'], ['.*', '[^/]*'], $regex);

        if ($isAbsolute) {
            $pattern = '^' . $regex . '$';
        } elseif ($glob === '*' || $glob === '**' || str_starts_with($glob, '**')) {
            $pattern = '.*' . ($glob === '*' || $glob === '**' ? '' : substr($regex, 4)) . '$';
        } else {
            $pattern = '(^' . preg_quote($baseDir . '/', '#') . '|^)' . $regex . '$';
        }

        return '#' . $pattern . '#i';
    }

    /**
     * Evaluates path specificity against include and exclude globs.
     *
     * @param array<int, string> $includeGlobs
     * @param array<int, string> $excludeGlobs
     */
    public static function isPathIncluded(
        string $normalizedPath,
        array $includeGlobs,
        array $excludeGlobs,
        string $rawPath = '',
        ?string $baseDir = null
    ): bool {
        $baseDir = $baseDir !== null ? self::normalizePath($baseDir) : Config::getProjectRoot();
        $normalizedRaw = self::normalizePath($rawPath);

        $includes = self::getCompiledPatterns($includeGlobs, $baseDir, 'include');
        $excludes = self::getCompiledPatterns($excludeGlobs, $baseDir, 'exclude');

        $isVendor = self::isVendorPath($normalizedPath, $normalizedRaw);
        if ($isVendor) {
            $hasExplicitVendorWhitelist = false;
            foreach ($includes as $compiled) {
                if (str_starts_with($compiled['pattern'], 'vendor/') &&
                    (preg_match($compiled['regex'], $normalizedPath) === 1 || ($normalizedRaw !== '' && preg_match($compiled['regex'], $normalizedRaw) === 1))
                ) {
                    $hasExplicitVendorWhitelist = true;

                    break;
                }
            }

            if (! $hasExplicitVendorWhitelist) {
                return false;
            }
        }

        $longestIncludeMatch = 0;
        foreach ($includes as $compiled) {
            $isExplicitVendorInclude = str_starts_with($compiled['pattern'], 'vendor/');
            $isWildcard = ($compiled['pattern'] === '*' || $compiled['pattern'] === '**');

            if ($isVendor && ! $isExplicitVendorInclude && ! $isWildcard) {
                continue;
            }

            if (preg_match($compiled['regex'], $normalizedPath) === 1 || ($normalizedRaw !== '' && preg_match($compiled['regex'], $normalizedRaw) === 1)) {
                $longestIncludeMatch = max($longestIncludeMatch, $compiled['len']);
            }
        }

        if ($longestIncludeMatch === 0) {
            return false;
        }

        $longestExcludeMatch = 0;
        foreach ($excludes as $compiled) {
            if (preg_match($compiled['regex'], $normalizedPath) === 1 || ($normalizedRaw !== '' && preg_match($compiled['regex'], $normalizedRaw) === 1)) {
                $longestExcludeMatch = max($longestExcludeMatch, $compiled['len']);
            }
        }

        return $longestIncludeMatch > $longestExcludeMatch;
    }

    /**
     * @param array<int, string> $globs
     *
     * @return array<int, array{pattern: string, len: int, regex: string}>
     */
    private static function getCompiledPatterns(array $globs, string $baseDir, string $type): array
    {
        $cacheKey = $baseDir . '|' . implode(',', $globs);

        if ($type === 'include' && isset(self::$compiledIncludesCache[$cacheKey])) {
            return self::$compiledIncludesCache[$cacheKey];
        }

        if ($type === 'exclude' && isset(self::$compiledExcludesCache[$cacheKey])) {
            return self::$compiledExcludesCache[$cacheKey];
        }

        $compiled = [];
        foreach ($globs as $pattern) {
            if (\is_string($pattern)) {
                $trimmed = trim($pattern);
                $compiled[] = [
                    'pattern' => $trimmed,
                    'len' => \strlen($trimmed),
                    'regex' => self::compileGlobToRegex($trimmed, $baseDir),
                ];
            }
        }

        if ($type === 'include') {
            return self::$compiledIncludesCache[$cacheKey] = $compiled;
        }

        return self::$compiledExcludesCache[$cacheKey] = $compiled;
    }
}
