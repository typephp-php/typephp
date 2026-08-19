<?php

declare(strict_types=1);

namespace TypePHP\Contract;

use TypePHP\Internal\CacheManager;
use TypePHP\Internal\Config;

/**
 * @internal Checks file paths against vendor directories, file extensions, and user-configured include/exclude globs.
 */
final class FileFilter
{
    /**
     * In-memory cache of boolean exclusion results keyed by normalized file path.
     *
     * @var array<string, bool>
     */
    private static array $pathFilterCache = [];

    /**
     * Pre-compiled include regex patterns, raw patterns, and match lengths.
     *
     * @var array<int, array{pattern: string, len: int, regex: string}>|null
     */
    private static ?array $compiledIncludes = null;

    /**
     * Pre-compiled exclude regex patterns, raw patterns, and match lengths.
     *
     * @var array<int, array{pattern: string, len: int, regex: string}>|null
     */
    private static ?array $compiledExcludes = null;

    /**
     * Cached normalized cache directory path.
     */
    private static ?string $cachedCacheDir = null;

    /**
     * Resets the path decision cache and pre-compiled regex patterns. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$pathFilterCache = [];
        self::$compiledIncludes = null;
        self::$compiledExcludes = null;
        self::$cachedCacheDir = null;
    }

    /**
     * Determines whether a given file path is excluded from contract inheritance.
     * Non-PHP files and excluded paths return true.
     */
    public static function isFileExcluded(string|false|null $fileName): bool
    {
        if ($fileName === null || $fileName === false || $fileName === '') {
            return false;
        }

        $normalizedPath = str_replace('\\', '/', $fileName);

        if (isset(self::$pathFilterCache[$normalizedPath])) {
            return self::$pathFilterCache[$normalizedPath];
        }

        // Non-PHP files are always excluded from PHPDoc contract processing
        if (! str_ends_with(strtolower($normalizedPath), '.php')) {
            return self::$pathFilterCache[$normalizedPath] = true;
        }

        if (self::$cachedCacheDir === null) {
            self::$cachedCacheDir = rtrim(str_replace('\\', '/', CacheManager::getCacheDir()), '/') . '/';
        }

        if (str_starts_with($normalizedPath, self::$cachedCacheDir)) {
            return self::$pathFilterCache[$normalizedPath] = true;
        }

        if (self::$compiledIncludes === null || self::$compiledExcludes === null) {
            self::compilePatterns();
        }

        $longestIncludeMatch = 0;
        $isVendorPath = str_contains($normalizedPath, '/vendor/');

        /** @var array<int, array{pattern: string, len: int, regex: string}> $includes */
        $includes = self::$compiledIncludes;
        foreach ($includes as $compiled) {
            $isExplicitVendorInclude = str_starts_with($compiled['pattern'], 'vendor/');
            $isWildcard = ($compiled['pattern'] === '*' || $compiled['pattern'] === '**');

            // Application include rules (like src/**, src/Core/**) never match inside vendor directories
            if ($isVendorPath && ! $isExplicitVendorInclude && ! $isWildcard) {
                continue;
            }

            if (preg_match($compiled['regex'], $normalizedPath) === 1) {
                $longestIncludeMatch = max($longestIncludeMatch, $compiled['len']);
            }
        }

        $longestExcludeMatch = 0;
        /** @var array<int, array{pattern: string, len: int, regex: string}> $excludes */
        $excludes = self::$compiledExcludes;
        foreach ($excludes as $compiled) {
            if (preg_match($compiled['regex'], $normalizedPath) === 1) {
                $longestExcludeMatch = max($longestExcludeMatch, $compiled['len']);
            }
        }

        // Equal specificity tie-breaker: Exclude wins!
        return self::$pathFilterCache[$normalizedPath] = ($longestExcludeMatch >= $longestIncludeMatch);
    }

    /**
     * Compiles configured include and exclude globs into regex patterns once per configuration lifecycle.
     */
    private static function compilePatterns(): void
    {
        $config = Config::get();
        /** @var array<mixed> $includes */
        $includes = \is_array($config['include'] ?? null) ? $config['include'] : ['**'];
        /** @var array<mixed> $excludes */
        $excludes = \is_array($config['exclude'] ?? null) ? $config['exclude'] : ['vendor/**', 'storage/**', 'var/**', 'cache/**'];

        $baseDir = Config::getProjectRoot();

        self::$compiledIncludes = [];
        foreach ($includes as $pattern) {
            if (\is_string($pattern)) {
                $trimmed = trim($pattern);
                self::$compiledIncludes[] = [
                    'pattern' => $trimmed,
                    'len' => \strlen($trimmed),
                    'regex' => self::compileGlobToRegex($trimmed, $baseDir),
                ];
            }
        }

        self::$compiledExcludes = [];
        foreach ($excludes as $pattern) {
            if (\is_string($pattern)) {
                $trimmed = trim($pattern);
                self::$compiledExcludes[] = [
                    'pattern' => $trimmed,
                    'len' => \strlen($trimmed),
                    'regex' => self::compileGlobToRegex($trimmed, $baseDir),
                ];
            }
        }
    }

    /**
     * Converts a glob pattern into an absolute regex pattern.
     */
    private static function compileGlobToRegex(string $glob, string $baseDir): string
    {
        $glob = str_replace('\\', '/', trim($glob));
        $isAbsolute = str_starts_with($glob, '/') || (bool) preg_match('#^[a-zA-Z]:/#', $glob);

        $regex = preg_quote($glob, '#');
        $regex = str_replace(['\*\*', '\*'], ['.*', '[^/]*'], $regex);

        if ($isAbsolute) {
            $pattern = '^' . $regex . '$';
        } elseif ($glob === '*' || $glob === '**' || str_starts_with($glob, '**')) {
            $pattern = '.*' . ($glob === '*' || $glob === '**' ? '' : substr($regex, 4)) . '$';
        } else {
            $pattern = '(^' . preg_quote($baseDir . '/', '#') . '|^.*\/)' . $regex . '$';
        }

        return '#' . $pattern . '#i';
    }
}