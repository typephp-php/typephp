<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use TypePHP\Contract\FileFilter;

/**
 * @internal
 */
final class CacheManager
{
    /**
     * Cache version prefix string. Bump this whenever AST printer/transformation rules change.
     */
    public const VERSION_PREFIX = 'v0.1_';

    /**
     * Cached absolute cache directory path.
     */
    private static ?string $resolvedCacheDir = null;

    public static function reset(): void
    {
        self::$resolvedCacheDir = null;
    }

    /**
     * Returns the absolute path to the cache directory, isolating by system user if using temp dir.
     */
    public static function getCacheDir(): string
    {
        if (self::$resolvedCacheDir !== null) {
            return self::$resolvedCacheDir;
        }

        $config = Config::get();
        $dir = $config['cache_dir'] ?? null;

        if (\is_string($dir) && $dir !== '') {
            return self::$resolvedCacheDir = $dir;
        }

        $username = getenv('USERNAME');
        $userEnv = getenv('USER');

        if (\function_exists('posix_geteuid')) {
            $user = (string) posix_geteuid();
        } elseif (\is_string($username) && $username !== '') {
            $user = $username;
        } elseif (\is_string($userEnv) && $userEnv !== '') {
            $user = $userEnv;
        } else {
            $user = (string) getmyuid();
        }

        $userHash = hash('xxh128', 'typephp_' . $user);

        return self::$resolvedCacheDir = sys_get_temp_dir() . '/typephp-cache-' . $userHash;
    }

    /**
     * Generates a unique cache key for a given file path based on its mtime and version prefix.
     */
    public static function getCacheKey(string $resolvedPath): string
    {
        $mtime = @filemtime($resolvedPath);
        $mtimeStr = $mtime !== false ? (string) $mtime : '0';

        return hash('xxh128', self::VERSION_PREFIX . $resolvedPath . $mtimeStr);
    }

    /**
     * Returns the full absolute disk path to the cached transformed PHP file.
     */
    public static function getCachedFilePath(string $resolvedPath): string
    {
        return self::getCacheDir() . '/' . self::getCacheKey($resolvedPath) . '.php';
    }

    /**
     * Ensures the cache directory exists securely.
     * Enforces strict UID/0700 checks on system temp dirs, while allowing
     * standard application permissions on custom user-configured directories.
     */
    public static function ensureSecureCacheDir(): bool
    {
        $cacheDir = self::getCacheDir();

        if (is_link($cacheDir)) {
            return false;
        }

        $config = Config::get();
        $isCustomDir = \is_string($config['cache_dir'] ?? null) && $config['cache_dir'] !== '';

        if (! is_dir($cacheDir)) {
            $mode = $isCustomDir ? 0775 : 0700;
            if (! @mkdir($cacheDir, $mode, recursive: true) && ! is_dir($cacheDir)) {
                return false;
            }
            if (! $isCustomDir) {
                @chmod($cacheDir, 0700);
            }
        }

        if (! $isCustomDir && \function_exists('posix_geteuid')) {
            $owner = @fileowner($cacheDir);
            if ($owner !== false && $owner !== posix_geteuid()) {
                return false;
            }
        }

        return is_writable($cacheDir);
    }

    /**
     * Safely writes cached content atomically to avoid corruption or partial reads.
     */
    public static function writeCachedFileSafely(string $cachedFile, string $transformed): bool
    {
        if (! self::ensureSecureCacheDir()) {
            return false;
        }

        $config = Config::get();
        $isCustomDir = \is_string($config['cache_dir'] ?? null) && $config['cache_dir'] !== '';

        $cacheDir = \dirname($cachedFile);
        $tmpFile = $cacheDir . '/.tmp_' . bin2hex(random_bytes(8));

        if (@file_put_contents($tmpFile, $transformed) === false) {
            return false;
        }

        @chmod($tmpFile, $isCustomDir ? 0664 : 0600);

        if (! @rename($tmpFile, $cachedFile)) {
            @unlink($tmpFile);

            return false;
        }

        return true;
    }

    /**
     * Clears all cached transformed files from the cache directory.
     */
    public static function clear(): int
    {
        $config = Config::get();
        $customDir = $config['cache_dir'] ?? null;

        $dirsToClear = [];

        if (\is_string($customDir) && $customDir !== '') {
            if (is_dir($customDir) && ! is_link($customDir)) {
                $dirsToClear[] = $customDir;
            }
        } else {
            $tempBase = sys_get_temp_dir();
            $matchedDirs = glob($tempBase . '/typephp-cache-*');

            if ($matchedDirs !== false) {
                foreach ($matchedDirs as $d) {
                    if (is_dir($d) && ! is_link($d)) {
                        if (\function_exists('posix_geteuid')) {
                            $owner = @fileowner($d);
                            if ($owner !== false && $owner !== posix_geteuid()) {
                                continue;
                            }
                        }
                        $dirsToClear[] = $d;
                    }
                }
            }
        }

        $count = 0;
        foreach ($dirsToClear as $dir) {
            $files = glob($dir . '/*.php');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file) && ! is_link($file)) {
                        @unlink($file);
                        $count++;
                    }
                }
            }

            // Also clean up any lingering temporary swap files
            $tmpFiles = glob($dir . '/.tmp_*');
            if ($tmpFiles !== false) {
                foreach ($tmpFiles as $tFile) {
                    if (is_file($tFile) && ! is_link($tFile)) {
                        @unlink($tFile);
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Recursively scans and pre-transforms all PHP files matching configured include patterns.
     *
     * @param (callable(string $status, string $file, int $current, int $total): void)|null $progressCallback
     *
     * @return array{total: int, cached: int, skipped: int}
     */
    public static function warmUp(?callable $progressCallback = null): array
    {
        $config = Config::get();
        if (! (bool) ($config['enabled'] ?? true)) {
            return ['total' => 0, 'cached' => 0, 'skipped' => 0];
        }

        $cwd = getcwd();
        $baseDir = $cwd !== false ? rtrim(str_replace('\\', '/', $cwd), '/') : '';

        $files = self::findFilesToWarm($baseDir);
        $total = \count($files);
        $cached = 0;
        $skipped = 0;

        foreach ($files as $idx => $file) {
            $cachedFile = self::getCachedFilePath($file);

            if (! file_exists($cachedFile)) {
                $source = file_get_contents($file);
                if ($source !== false) {
                    $transformed = StreamWrapper::transformSource($source, $file);
                    if (self::writeCachedFileSafely($cachedFile, $transformed)) {
                        $cached++;
                    }
                    if ($progressCallback !== null) {
                        $progressCallback('cached', $file, $idx + 1, $total);
                    }
                }
            } else {
                $skipped++;
                if ($progressCallback !== null) {
                    $progressCallback('skipped', $file, $idx + 1, $total);
                }
            }
        }

        return [
            'total' => $total,
            'cached' => $cached,
            'skipped' => $skipped,
        ];
    }

    /**
     * Scans base directory for non-excluded .php files.
     *
     * @return array<int, string>
     */
    private static function findFilesToWarm(string $baseDir): array
    {
        if ($baseDir === '' || ! is_dir($baseDir)) {
            return [];
        }

        $files = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
            );

            foreach ($iterator as $fileInfo) {
                if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'php') {
                    $realPath = $fileInfo->getRealPath();
                    if ($realPath !== false) {
                        $path = str_replace('\\', '/', $realPath);
                        if (! FileFilter::isFileExcluded($path)) {
                            $files[] = $path;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently handle filesystem read errors
        }

        return $files;
    }
}
