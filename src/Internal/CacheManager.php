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
     * Returns the absolute path to the cache directory.
     */
    public static function getCacheDir(): string
    {
        $config = Config::get();
        $dir = $config['cache_dir'] ?? null;

        return \is_string($dir) ? $dir : (sys_get_temp_dir() . '/typephp-cache');
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
     * Clears all cached transformed files from the cache directory.
     */
    public static function clear(): int
    {
        $wasRegistered = StreamWrapper::isRegistered();
        StreamWrapper::unregister();

        $cacheDir = self::getCacheDir();

        if (! is_dir($cacheDir)) {
            if ($wasRegistered) {
                StreamWrapper::register();
            }

            return 0;
        }

        $files = glob($cacheDir . '/*.php');
        if ($files === false || \count($files) === 0) {
            if ($wasRegistered) {
                StreamWrapper::register();
            }

            return 0;
        }

        $count = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                $count++;
            }
        }

        if ($wasRegistered) {
            StreamWrapper::register();
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
                    $cacheDir = self::getCacheDir();
                    if (! is_dir($cacheDir)) {
                        @mkdir($cacheDir, 0777, recursive: true);
                    }
                    file_put_contents($cachedFile, $transformed);
                    $cached++;
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
