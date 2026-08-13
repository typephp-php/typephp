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
     * Determines whether a given file path is excluded from contract inheritance.
     * Non-PHP files and excluded paths return true.
     */
    public static function isFileExcluded(string|false|null $fileName): bool
    {
        if ($fileName === null || $fileName === false || $fileName === '') {
            return false;
        }

        $normalizedPath = str_replace('\\', '/', $fileName);

        // Non-PHP files are always excluded from PHPDoc contract processing
        if (! str_ends_with(strtolower($normalizedPath), '.php')) {
            return true;
        }

        $normalizedCacheDir = rtrim(str_replace('\\', '/', CacheManager::getCacheDir()), '/') . '/';
        if (str_starts_with($normalizedPath, $normalizedCacheDir)) {
            return true;
        }

        $config = Config::get();
        /** @var array<mixed> $includes */
        $includes = \is_array($config['include'] ?? null) ? $config['include'] : ['**'];
        /** @var array<mixed> $excludes */
        $excludes = \is_array($config['exclude'] ?? null) ? $config['exclude'] : ['vendor/**', 'storage/**', 'var/**', 'cache/**'];

        $cwd = getcwd();
        $baseDir = $cwd !== false ? rtrim(str_replace('\\', '/', $cwd), '/') : '';

        $longestIncludeMatch = 0;
        foreach ($includes as $pattern) {
            if (! \is_string($pattern)) {
                continue;
            }
            $regex = self::compileGlobToRegex($pattern, $baseDir);
            if (preg_match($regex, $normalizedPath) === 1) {
                $longestIncludeMatch = max($longestIncludeMatch, \strlen(trim($pattern)));
            }
        }

        $longestExcludeMatch = 0;
        foreach ($excludes as $pattern) {
            if (! \is_string($pattern)) {
                continue;
            }
            $regex = self::compileGlobToRegex($pattern, $baseDir);
            if (preg_match($regex, $normalizedPath) === 1) {
                $longestExcludeMatch = max($longestExcludeMatch, \strlen(trim($pattern)));
            }
        }

        // Equal specificity tie-breaker: Exclude wins!
        return $longestExcludeMatch >= $longestIncludeMatch;
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
        } elseif (str_starts_with($glob, '**')) {
            $pattern = '.*' . substr($regex, 4) . '$';
        } else {
            $pattern = '(^' . preg_quote($baseDir . '/', '#') . '|^.*\/)' . $regex . '$';
        }

        return '#' . $pattern . '#i';
    }
}
