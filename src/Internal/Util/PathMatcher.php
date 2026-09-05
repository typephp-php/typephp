<?php

declare(strict_types=1);

namespace TypePHP\Internal\Util;

use TypePHP\Internal\Io\CacheManager;

require_once __DIR__ . '/../Io/CacheManager.php';

/**
 * Centralized utility for path normalization, glob compilation, vendor isolation, and specificity matching.
 *
 * @internal
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
     * Resets compiled pattern, prefix, and directory caches.
     */
    public static function reset(): void
    {
        self::$compiledIncludesCache = [];
        self::$compiledExcludesCache = [];
        self::$includePrefixCache = [];
        self::$cachedCacheDir = null;
        self::$cachedLibSrcDir = null;
        CacheManager::reset();
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
     * Collapses relative directory traversals (..) into canonical paths.
     * Preserves leading root slashes, Windows drive letters, and root boundaries.
     */
    public static function canonicalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (! str_contains($path, '..') && ! str_contains($path, '/.')) {
            return $path;
        }

        $parts = explode('/', $path);
        $absolutes = [];

        foreach ($parts as $part) {
            if ($part === '.' || ($part === '' && \count($absolutes) > 0)) {
                continue;
            }

            if ($part === '') {
                $absolutes[] = '';

                continue;
            }

            if ($part === '..') {
                $last = end($absolutes);
                $isDriveLetter = \is_string($last) && preg_match('/^[a-zA-Z]:$/', $last) === 1;

                if (\count($absolutes) > 0 && $last !== '..' && $last !== '' && ! $isDriveLetter) {
                    array_pop($absolutes);
                } elseif (\count($absolutes) === 0 || $last === '..') {
                    $absolutes[] = '..';
                }
            } else {
                $absolutes[] = $part;
            }
        }

        if ($absolutes === ['']) {
            return '/';
        }

        return implode('/', $absolutes);
    }

    /**
     * Determines whether a given path is located within a vendor directory.
     */
    public static function isVendorPath(string $normalizedPath, string $rawPath = ''): bool
    {
        $canon = self::canonicalizePath($normalizedPath);
        $canonRaw = $rawPath !== '' ? self::canonicalizePath(self::normalizePath($rawPath)) : '';

        return str_starts_with($canon, 'vendor/')
            || str_contains($canon, '/vendor/')
            || ($canonRaw !== '' && (str_starts_with($canonRaw, 'vendor/') || str_contains($canonRaw, '/vendor/')));
    }

    /**
     * Determines whether a given path belongs to an immutable, static source code repositor
     */
    public static function isStaticSourcePath(string $normalizedPath): bool
    {
        $canon = self::canonicalizePath($normalizedPath);

        if (self::isDynamicWritablePath($canon)) {
            return false;
        }

        if (
            str_contains($canon, '/tests/') || str_starts_with($canon, 'tests/')
            || str_contains($canon, '/Fixtures/') || str_contains($canon, '/fixtures/')
            || str_contains($canon, '/tmp/') || str_contains($canon, '/install/')
        ) {
            return false;
        }

        if (str_starts_with($canon, 'vendor/') || str_contains($canon, '/vendor/')) {
            return true;
        }

        if (str_starts_with($canon, 'src/') || str_contains($canon, '/src/')) {
            return true;
        }

        if (str_starts_with($canon, 'app/') || str_contains($canon, '/app/')) {
            return true;
        }

        if (str_starts_with($canon, 'lib/') || str_contains($canon, '/lib/')) {
            return true;
        }

        if (preg_match('#(^|/)packages/[^/]+/src/#', $canon) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Determines whether a given path is within the TypePHP cache directory.
     */
    public static function isCachePath(string $normalizedPath): bool
    {
        if (self::$cachedCacheDir === null) {
            self::$cachedCacheDir = rtrim(self::normalizePath(CacheManager::getCacheDir()), '/') . '/';
        }

        return str_starts_with(self::canonicalizePath($normalizedPath), self::$cachedCacheDir);
    }

    /**
     * Determines whether a path belongs to TypePHP's internal engine source files.
     */
    public static function isLibraryInternal(string $normalizedPath): bool
    {
        if (self::$cachedLibSrcDir === null) {
            $parentDir = realpath(\dirname(__DIR__, 2));
            self::$cachedLibSrcDir = $parentDir !== false ? rtrim(self::normalizePath($parentDir), '/') . '/' : '';
        }

        $libSrcDir = self::$cachedLibSrcDir;
        if ($libSrcDir === '') {
            return false;
        }

        $canon = self::canonicalizePath($normalizedPath);
        $lowerCanon = strtolower($canon);
        $lowerLibSrcDir = strtolower($libSrcDir);

        if (str_contains($lowerLibSrcDir, '/vendor/')) {
            return str_starts_with($lowerCanon, $lowerLibSrcDir);
        }

        if (str_starts_with($lowerCanon, $lowerLibSrcDir)) {
            $internalDirs = [
                $lowerLibSrcDir . 'internal/',
                $lowerLibSrcDir . 'extension/',
                $lowerLibSrcDir . 'exception/',
                $lowerLibSrcDir . 'typephp.php',
                $lowerLibSrcDir . 'bootstrap.php',
            ];

            foreach ($internalDirs as $dir) {
                if (str_starts_with($lowerCanon, $dir)) {
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
     * Determines whether a directory path is a dynamic writable cache, log, or storage directory.
     */
    public static function isDynamicWritablePath(string $normalizedPath): bool
    {
        $canon = self::canonicalizePath($normalizedPath);

        return str_contains($canon, '/var/cache/') || str_starts_with($canon, 'var/cache/')
            || str_contains($canon, '/var/log/') || str_starts_with($canon, 'var/log/')
            || str_contains($canon, '/storage/') || str_starts_with($canon, 'storage/')
            || str_contains($canon, '/cache/') || str_starts_with($canon, 'cache/');
    }

    /**
     * High-speed string pre-filter to reject non-application paths before executing regex matching.
     */
    public static function mayPathBeIncluded(string $normalizedPath): bool
    {
        $canon = self::canonicalizePath($normalizedPath);

        if (str_contains($canon, '/node_modules/') || str_starts_with($canon, 'node_modules/')) {
            return false;
        }

        if (self::isCachePath($canon)) {
            return false;
        }

        $config = Config::get();
        /** @var array<int, string> $includes */
        $includes = \is_array($config['include'] ?? null) ? $config['include'] : ['**'];
        /** @var array<int, string> $excludes */
        $excludes = \is_array($config['exclude'] ?? null) ? $config['exclude'] : [];

        if (str_contains($canon, '/vendor/') || str_starts_with($canon, 'vendor/')) {
            if (! self::hasIncludeMatchingPrefix('vendor/', $includes)) {
                return false;
            }
        }

        if (str_contains($canon, '/var/') || str_starts_with($canon, 'var/')) {
            if (! self::hasIncludeMatchingPrefix('var/', $includes)) {
                return false;
            }
        }

        if (str_contains($canon, '/storage/') || str_starts_with($canon, 'storage/')) {
            if (! self::hasIncludeMatchingPrefix('storage/', $includes)) {
                return false;
            }
        }

        if (str_contains($canon, '/tests/') || str_starts_with($canon, 'tests/')) {
            if (! self::hasIncludeMatchingPrefix('tests/', $includes)) {
                return false;
            }
        }

        if (str_contains($canon, '/Migration/') || str_contains($canon, '/migration/')) {
            if (self::hasIncludeMatchingPrefix('Migration', $excludes) || self::hasIncludeMatchingPrefix('migration', $excludes)) {
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

        if ($isAbsolute) {
            $regex = preg_quote($glob, '#');
            $regex = str_replace(['\*\*', '\*'], ['.*', '[^/]*'], $regex);
            $pattern = '^' . $regex . '$';
        } elseif ($glob === '*' || $glob === '**') {
            $pattern = '.*$';
        } elseif (str_starts_with($glob, '**/')) {
            $subRegex = preg_quote(substr($glob, 3), '#');
            $subRegex = str_replace(['\*\*', '\*'], ['.*', '[^/]*'], $subRegex);
            $pattern = '(^|.*\/)' . $subRegex . '$';
        } else {
            $regex = preg_quote($glob, '#');
            $regex = str_replace(['\*\*', '\*'], ['.*', '[^/]*'], $regex);
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
        $normalizedPath = self::canonicalizePath($normalizedPath);
        $normalizedRaw = $rawPath !== '' ? self::canonicalizePath(self::normalizePath($rawPath)) : '';

        $includes = self::getCompiledPatterns($includeGlobs, $baseDir, 'include');
        $excludes = self::getCompiledPatterns($excludeGlobs, $baseDir, 'exclude');

        $isVendor = self::isVendorPath($normalizedPath, $normalizedRaw);
        if ($isVendor) {
            $hasExplicitVendorWhitelist = false;
            foreach ($includes as $compiled) {
                if (
                    str_starts_with($compiled['pattern'], 'vendor/') &&
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
