<?php

declare(strict_types=1);

namespace TypePHP\Internal\Util;

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
     * Resets the path decision cache and pre-compiled regex patterns. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$pathFilterCache = [];
        PathMatcher::reset();
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

        $normalizedPath = PathMatcher::normalizePath($fileName);

        if (isset(self::$pathFilterCache[$normalizedPath])) {
            return self::$pathFilterCache[$normalizedPath];
        }

        // Non-PHP files are always excluded from PHPDoc contract processing
        if (! str_ends_with(strtolower($normalizedPath), '.php')) {
            return self::$pathFilterCache[$normalizedPath] = true;
        }

        if (PathMatcher::isCachePath($normalizedPath)) {
            return self::$pathFilterCache[$normalizedPath] = true;
        }

        $config = Config::get();
        /** @var array<int, string> $includes */
        $includes = \is_array($config['include'] ?? null) ? $config['include'] : ['**'];
        /** @var array<int, string> $excludes */
        $excludes = \is_array($config['exclude'] ?? null) ? $config['exclude'] : ['vendor/**', 'storage/**', 'var/**', 'cache/**'];

        $isIncluded = PathMatcher::isPathIncluded($normalizedPath, $includes, $excludes, $fileName);

        return self::$pathFilterCache[$normalizedPath] = ! $isIncluded;
    }
}
