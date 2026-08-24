<?php

declare(strict_types=1);

namespace TypePHP\Internal;

require_once __DIR__ . '/PathMatcher.php';

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use TypePHP\Resolver\SpecialTypeResolver;

/**
 * Custom stream wrapper intercepting 'file://' inclusions to perform on-the-fly AST transformations.
 *
 * @internal
 */
final class StreamWrapper implements StreamWrapperInterface
{
    /**
     * Bitmask constant passed by PHP's Zend Engine when a stream is opened by include/require.
     */
    public const STREAM_OPEN_FOR_INCLUDE = 128;

    /**
     * Context resource provided by PHP stream subsystem.
     *
     * @var resource|null
     */
    public $context;

    /**
     * @var resource|null
     */
    private $handle = null;

    /**
     * @var resource|null
     */
    private $dirHandle = null;

    private static bool $isRegistered = false;

    private static bool $cacheEnabled = true;

    /**
     * In-memory cache for positive url_stat results.
     *
     * @var array<string, array<int|string, int>>
     */
    private static array $statCache = [];

    /**
     * In-memory cache for static-path negative misses only (e.g. vendor).
     * Never stores negative misses for dynamic paths (var/cache, storage).
     *
     * @var array<string, true>
     */
    private static array $staticNegativeStatCache = [];

    /**
     * In-memory cache for isApplicationFile path decisions.
     *
     * @var array<string, bool>
     */
    private static array $appFileDecisionCache = [];

    /**
     * Fast-lookup table for read-only source view functions.
     *
     * @var array<string, true>
     */
    private const READ_ONLY_FUNCTIONS = [
        'file_get_contents' => true,
        'file' => true,
        'readfile' => true,
        'highlight_file' => true,
        'show_source' => true,
        'token_get_all' => true,
    ];

    /**
     * Resets all internal caches.
     */
    public static function reset(): void
    {
        self::$statCache = [];
        self::$staticNegativeStatCache = [];
        self::$appFileDecisionCache = [];
        PathMatcher::reset();
    }

    /**
     * Registers the stream wrapper for the native 'file://' protocol.
     *
     * @param array<string, mixed> $config
     */
    public static function register(array $config = []): void
    {
        $resolvedConfig = array_replace_recursive(Config::get(), $config);

        self::$cacheEnabled = (bool) ($resolvedConfig['cache'] ?? true);

        if (! self::$isRegistered) {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
            self::$isRegistered = true;
        }
    }

    public static function isRegistered(): bool
    {
        return self::$isRegistered;
    }

    public static function unregister(): void
    {
        if (self::$isRegistered) {
            @stream_wrapper_restore('file');
            self::$isRegistered = false;
        }
    }

    /**
     * Transforms PHP source code by parsing AST, extracting metadata, applying ContractVisitor, and formatting output.
     * Preserves exact line numbers to guarantee zero line-drift in debug stack traces.
     */
    public static function transformSource(string $source, string $filePath = ''): string
    {
        if (Config::isRespectIgnoreTagsEnabled() && (str_contains($source, '@typephp-ignore-file') || str_contains($source, '@typephp-disable-file'))) {
            return $source;
        }

        $originalLineCount = substr_count($source, "\n");

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $oldStmts = $parser->parse($source);
            if ($oldStmts === null) {
                return $source;
            }
        } catch (\Throwable $e) {
            return $source;
        }

        self::extractAndSeedFileMetadata($oldStmts, $filePath);

        $oldTokens = $parser->getTokens();

        $traverser1 = new NodeTraverser();
        $traverser1->addVisitor(new CloningVisitor());

        /** @var array<\PhpParser\Node\Stmt> $nodesToTraverse */
        $nodesToTraverse = $oldStmts;
        $newStmts = $traverser1->traverse($nodesToTraverse);

        $traverser2 = new NodeTraverser();
        $traverser2->addVisitor(new ContractVisitor());
        $newStmts = $traverser2->traverse($newStmts);

        $printer = new TypePHPPrinter();
        $transformed = $printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

        $transformed = preg_replace('/[ \t]*\r?\n[ \t]*\/\*__TYPEPHP_INJECTED_START__\*\//', ' /*__TYPEPHP_INJECTED_START__*/', $transformed) ?? $transformed;

        $transformedLineCount = substr_count($transformed, "\n");
        $drift = $transformedLineCount - $originalLineCount;
        $count1 = 0;
        $count2 = 0;

        if ($drift > 0) {
            $transformed = preg_replace('/[ \t]*\r?\n[ \t]*\{[ \t]*\/\*__TYPEPHP_INJECTED_START__\*\//', ' { /*__TYPEPHP_INJECTED_START__*/', $transformed, $drift, $count1) ?? $transformed;
            $drift -= $count1;
        }

        if ($drift > 0) {
            $transformed = preg_replace('/\/\*__TYPEPHP_INJECTED_END__\*\/[ \t]*\r?\n[ \t]*\}/', '/*__TYPEPHP_INJECTED_END__*/ }', $transformed, $drift, $count2) ?? $transformed;
            $drift -= $count2;
        }

        if ($drift > 0) {
            $transformed = preg_replace('/\/\*__TYPEPHP_INJECTED_END__\*\/[ \t]*\r?\n[ \t]*/', '/*__TYPEPHP_INJECTED_END__*/ ', $transformed, $drift) ?? $transformed;
        }

        return str_replace(['/*__TYPEPHP_INJECTED_START__*/', '/*__TYPEPHP_INJECTED_END__*/'], '', $transformed);
    }

    /**
     * Opens a file stream, intercepting matching application files for AST transformation.
     * Evaluates string fast-paths before checking file existence or unregistering the wrapper.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if ($mode !== 'r' && $mode !== 'rb' && $mode !== 'rt') {
            return $this->openDirectHandle($path, $mode);
        }

        $isInclude = ($options & self::STREAM_OPEN_FOR_INCLUDE) !== 0;
        if (! $isInclude) {
            return $this->openDirectHandle($path, $mode);
        }

        if (! str_ends_with(strtolower($path), '.php')) {
            return $this->openDirectHandle($path, $mode);
        }

        if (! Config::isEnabled()) {
            return $this->openDirectHandle($path, $mode);
        }

        $normalizedRaw = str_replace('\\', '/', $path);
        if (! PathMatcher::mayPathBeIncluded($normalizedRaw)) {
            return $this->openDirectHandle($path, $mode);
        }

        self::unregister();
        $exists = (bool) self::silent(fn () => file_exists($path));
        $resolvedPath = $exists ? self::silent(fn () => realpath($path)) : false;
        self::register();

        if (! $exists || $resolvedPath === false) {
            return $this->openDirectHandle($path, $mode);
        }

        $normalizedResolved = str_replace('\\', '/', $resolvedPath);

        if (! self::isApplicationFile($path, $resolvedPath)) {
            return $this->openDirectHandle($normalizedResolved, $mode);
        }

        if (self::isReadOnlyCall()) {
            return $this->openDirectHandle($normalizedResolved, $mode);
        }

        self::unregister();

        $success = self::$cacheEnabled
            ? $this->openCachedStream($resolvedPath, $mode)
            : $this->openMemoryStream($resolvedPath);

        self::register();

        return $success;
    }

    /**
     * Determines if the stream_open call is for reading raw file contents/snippets
     * (e.g. error screen renderers) rather than PHP engine execution.
     */
    private static function isReadOnlyCall(): bool
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        $caller1 = strtolower($trace[1]['function'] ?? '');
        $caller2 = strtolower($trace[2]['function'] ?? '');

        return isset(self::READ_ONLY_FUNCTIONS[$caller1]) || isset(self::READ_ONLY_FUNCTIONS[$caller2]);
    }

    /**
     * Opens a raw file handle directly without stream interception.
     */
    private function openDirectHandle(string $targetFile, string $mode): bool
    {
        self::unregister();
        /** @var resource|false $handle */
        $handle = self::silent(fn () => fopen($targetFile, $mode));
        $this->handle = $handle !== false ? $handle : null;
        self::register();

        return $this->handle !== null;
    }

    public function stream_read(int $count): string
    {
        if ($this->handle === null || $count <= 0) {
            return '';
        }

        $res = fread($this->handle, $count);

        return $res !== false ? $res : '';
    }

    public function stream_write(string $data): int
    {
        if ($this->handle === null) {
            return 0;
        }

        $res = fwrite($this->handle, $data);

        return $res !== false ? $res : 0;
    }

    public function stream_lock(int $operation): bool
    {
        if ($this->handle === null) {
            return false;
        }

        if ($operation < 1 || $operation > 15) {
            return true;
        }

        // @phpstan-ignore argument.type
        return @flock($this->handle, $operation);
    }

    public function stream_tell(): int
    {
        if ($this->handle === null) {
            return 0;
        }

        $res = ftell($this->handle);

        return $res !== false ? $res : 0;
    }

    public function stream_flush(): bool
    {
        if ($this->handle === null) {
            return false;
        }

        return fflush($this->handle);
    }

    public function stream_truncate(int $new_size): bool
    {
        if ($this->handle === null || $new_size < 0) {
            return false;
        }

        return ftruncate($this->handle, $new_size);
    }

    public function stream_eof(): bool
    {
        if ($this->handle === null) {
            return true;
        }

        return feof($this->handle);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        if ($this->handle === null) {
            return false;
        }

        return fstat($this->handle);
    }

    /**
     * Extracts the underlying native file descriptor for C-level functions like proc_open() or stream_select().
     *
     * @return resource|false
     */
    public function stream_cast(int $cast_as)
    {
        return $this->handle !== null ? $this->handle : false;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($this->handle === null) {
            return false;
        }

        return @fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * High-speed stat resolution with dual-tier memoization caching:
     *
     * 1. Positive hit cache ($statCache): Caches stat arrays for existing files and directories.
     * 2. Static negative cache ($staticNegativeStatCache): Caches false lookups strictly for static
     *    read-only paths (e.g. vendor directories), eliminating thousands of duplicate C-level stat calls.
     * 3. Dynamic writable bypass: Never caches false for dynamic directories (var/cache, storage),
     *    ensuring framework cache warmers and runtime directory creation remain fully functional.
     *
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $normalized = str_replace('\\', '/', $path);

        if (isset(self::$statCache[$normalized])) {
            return self::$statCache[$normalized];
        }

        if (isset(self::$staticNegativeStatCache[$normalized])) {
            return false;
        }

        self::unregister();
        /** @var array<int|string, int>|false $result */
        $result = self::silent(fn () => stat($path));
        self::register();

        if ($result !== false) {
            self::$statCache[$normalized] = $result;
        } else {
            if (! PathMatcher::isDynamicWritablePath($normalized)) {
                self::$staticNegativeStatCache[$normalized] = true;
            }
        }

        return $result;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        $normalized = str_replace('\\', '/', $path);
        unset(self::$statCache[$normalized], self::$staticNegativeStatCache[$normalized]);

        self::unregister();
        $result = false;
        if ($option === STREAM_META_TOUCH) {
            /** @var array{0?: int, 1?: int} $valueArray */
            $valueArray = \is_array($value) ? $value : [];
            $time = $valueArray[0] ?? time();
            $atime = $valueArray[1] ?? $time;
            $result = (bool) self::silent(fn () => touch($path, (int) $time, (int) $atime));
        } elseif ($option === STREAM_META_ACCESS) {
            /** @var int $mode */
            $mode = \is_int($value) ? $value : 0777;
            $result = (bool) self::silent(fn () => chmod($path, $mode));
        }
        self::register();

        return $result;
    }

    public function dir_opendir(string $path, int $options): bool
    {
        self::unregister();
        /** @var resource|false $dh */
        $dh = self::silent(fn () => opendir($path));
        $this->dirHandle = $dh !== false ? $dh : null;
        self::register();

        return $this->dirHandle !== null;
    }

    public function dir_readdir(): string|false
    {
        if ($this->dirHandle === null) {
            return false;
        }

        return readdir($this->dirHandle);
    }

    public function dir_rewinddir(): bool
    {
        if ($this->dirHandle === null) {
            return false;
        }

        rewinddir($this->dirHandle);

        return true;
    }

    public function dir_closedir(): bool
    {
        if ($this->dirHandle !== null) {
            closedir($this->dirHandle);
            $this->dirHandle = null;
        }

        return true;
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        $normalized = str_replace('\\', '/', $path);
        unset(self::$statCache[$normalized], self::$staticNegativeStatCache[$normalized]);

        self::unregister();
        $result = (bool) self::silent(fn () => mkdir($path, $mode, ($options & STREAM_MKDIR_RECURSIVE) !== 0));
        self::register();

        return $result;
    }

    public function rmdir(string $path, int $options): bool
    {
        $normalized = str_replace('\\', '/', $path);
        unset(self::$statCache[$normalized], self::$staticNegativeStatCache[$normalized]);

        self::unregister();
        $result = (bool) self::silent(fn () => rmdir($path));
        self::register();

        return $result;
    }

    public function unlink(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        unset(self::$statCache[$normalized], self::$staticNegativeStatCache[$normalized]);

        self::unregister();
        $result = (bool) self::silent(fn () => unlink($path));
        self::register();

        return $result;
    }

    public function rename(string $pathFrom, string $pathTo): bool
    {
        $normFrom = str_replace('\\', '/', $pathFrom);
        $normTo = str_replace('\\', '/', $pathTo);
        unset(
            self::$statCache[$normFrom],
            self::$statCache[$normTo],
            self::$staticNegativeStatCache[$normFrom],
            self::$staticNegativeStatCache[$normTo]
        );

        self::unregister();
        $result = (bool) self::silent(fn () => rename($pathFrom, $pathTo));
        self::register();

        return $result;
    }

    /**
     * Executes a callback while temporarily suppressing PHP error and warning handlers.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private static function silent(callable $callback): mixed
    {
        set_error_handler(fn () => true);

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Determines whether a target PHP file path should be intercepted with $O(1)$ caching.
     */
    private static function isApplicationFile(string $path, string|false $resolvedPath): bool
    {
        if (! Config::isEnabled()) {
            return false;
        }

        if (! str_ends_with($path, '.php') || $resolvedPath === false) {
            return false;
        }

        $normalizedPath = PathMatcher::normalizePath($resolvedPath);

        if (isset(self::$appFileDecisionCache[$normalizedPath])) {
            return self::$appFileDecisionCache[$normalizedPath];
        }

        if (PathMatcher::isLibraryInternal($normalizedPath)) {
            return self::$appFileDecisionCache[$normalizedPath] = false;
        }

        if (PathMatcher::isCachePath($normalizedPath)) {
            return self::$appFileDecisionCache[$normalizedPath] = false;
        }

        $config = Config::get();
        /** @var array<int, string> $includes */
        $includes = \is_array($config['include'] ?? null) ? $config['include'] : ['**'];
        /** @var array<int, string> $excludes */
        $excludes = \is_array($config['exclude'] ?? null) ? $config['exclude'] : ['vendor/**', 'storage/**', 'var/**', 'cache/**'];

        $isIncluded = PathMatcher::isPathIncluded($normalizedPath, $includes, $excludes, $path);

        return self::$appFileDecisionCache[$normalizedPath] = $isIncluded;
    }

    private function openMemoryStream(string $resolvedPath): bool
    {
        $source = file_get_contents($resolvedPath);
        if ($source === false) {
            return false;
        }

        $transformed = self::transformSource($source, $resolvedPath);

        $memHandle = fopen('php://memory', 'r+');
        if ($memHandle !== false) {
            fwrite($memHandle, $transformed);
            rewind($memHandle);
            $this->handle = $memHandle;
        }

        return $this->handle !== null;
    }

    /**
     * Transforms and caches source code on disk before opening a file handle.
     */
    private function openCachedStream(string $resolvedPath, string $mode): bool
    {
        $cachedFile = CacheManager::getCachedFilePath($resolvedPath);

        if (! CacheManager::ensureSecureCacheDir()) {
            return $this->openMemoryStream($resolvedPath);
        }

        if (! file_exists($cachedFile) || is_link($cachedFile)) {
            $source = file_get_contents($resolvedPath);
            if ($source === false) {
                return false;
            }
            $transformed = self::transformSource($source, $resolvedPath);
            if (! CacheManager::writeCachedFileSafely($cachedFile, $transformed)) {
                return $this->openMemoryStream($resolvedPath);
            }
        }

        if (\function_exists('posix_geteuid')) {
            $owner = @fileowner($cachedFile);
            if ($owner !== false && $owner !== posix_geteuid()) {
                return $this->openMemoryStream($resolvedPath);
            }
        }

        $cacheHandle = fopen($cachedFile, $mode);
        $this->handle = $cacheHandle !== false ? $cacheHandle : null;

        return $this->handle !== null;
    }

    /**
     * Scans top-level AST statements for namespace, use imports, and trait use declarations to seed SpecialTypeResolver.
     *
     * @param array<\PhpParser\Node\Stmt> $stmts
     */
    private static function extractAndSeedFileMetadata(array $stmts, string $filePath): void
    {
        if ($filePath === '') {
            return;
        }

        $namespace = '';
        $imports = [];
        $classTraitUseDocs = [];

        $nodesToScan = $stmts;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Namespace_) {
                $namespace = $stmt->name !== null ? $stmt->name->toString() : '';
                $nodesToScan = $stmt->stmts;

                break;
            }
        }

        foreach ($nodesToScan as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Use_) {
                if ($stmt->type !== \PhpParser\Node\Stmt\Use_::TYPE_NORMAL) {
                    continue;
                }

                foreach ($stmt->uses as $use) {
                    $fqcn = $use->name->toString();
                    $alias = $use->getAlias()->toString();
                    $imports[$alias] = $fqcn;
                }
            } elseif ($stmt instanceof \PhpParser\Node\Stmt\GroupUse) {
                $prefix = $stmt->prefix->toString();

                foreach ($stmt->uses as $use) {
                    if ($use->type !== \PhpParser\Node\Stmt\Use_::TYPE_NORMAL && $use->type !== \PhpParser\Node\Stmt\Use_::TYPE_UNKNOWN && $stmt->type !== \PhpParser\Node\Stmt\Use_::TYPE_NORMAL) {
                        continue;
                    }

                    $fqcn = $prefix . '\\' . $use->name->toString();
                    $alias = $use->getAlias()->toString();
                    $imports[$alias] = $fqcn;
                }
            } elseif ($stmt instanceof \PhpParser\Node\Stmt\Class_ && $stmt->name !== null) {
                $className = $namespace !== '' ? $namespace . '\\' . $stmt->name->toString() : $stmt->name->toString();
                foreach ($stmt->stmts as $classStmt) {
                    if ($classStmt instanceof \PhpParser\Node\Stmt\TraitUse) {
                        $doc = $classStmt->getDocComment();
                        if ($doc !== null) {
                            $classTraitUseDocs[$className][] = $doc->getText();
                        }
                    }
                }
            }
        }

        SpecialTypeResolver::seedFileMetadata($filePath, $namespace, $imports, $classTraitUseDocs);
    }
}
