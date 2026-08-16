<?php

declare(strict_types=1);

namespace TypePHP\Internal;

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

    /**
     * @var array<string, string>
     */
    private static array $includeRawPatterns = [];

    /**
     * @var array<string, string>
     */
    private static array $excludeRawPatterns = [];

    private static string $baseDir = '';

    private static bool $isInitialized = false;

    private static bool $isRegistered = false;

    private static bool $cacheEnabled = true;

    private static string $cacheDir = '';

    /**
     * @param array<string, mixed> $config
     */
    public static function register(array $config = []): void
    {
        $resolvedConfig = array_replace_recursive(Config::get(), $config);

        if (! self::$isInitialized || \count($config) > 0) {
            self::$baseDir = Config::getProjectRoot();

            /** @var array<int, string> $includes */
            $includes = \is_array($resolvedConfig['include'] ?? null) ? $resolvedConfig['include'] : ['**'];

            /** @var array<int, string> $excludes */
            $excludes = \is_array($resolvedConfig['exclude'] ?? null) ? $resolvedConfig['exclude'] : ['vendor/**', 'storage/**', 'var/**', 'cache/**'];

            self::$includeRawPatterns = [];
            foreach ($includes as $pattern) {
                self::$includeRawPatterns[trim($pattern)] = self::compileGlobToRegex($pattern);
            }

            self::$excludeRawPatterns = [];
            foreach ($excludes as $pattern) {
                self::$excludeRawPatterns[trim($pattern)] = self::compileGlobToRegex($pattern);
            }

            self::$cacheEnabled = (bool) ($resolvedConfig['cache'] ?? true);
            self::$cacheDir = CacheManager::getCacheDir();

            self::$isInitialized = true;
        }

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
     */
    public static function transformSource(string $source, string $filePath = ''): string
    {
        // Respect per-file suppression tag unless respect_ignore_tags is false
        if ((bool) (Config::get()['respect_ignore_tags'] ?? true) && (str_contains($source, '@typephp-ignore-file') || str_contains($source, '@typephp-disable-file'))) {
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

        /** @var array<\PhpParser\Node\Stmt> $newStmts */
        $newStmts = $traverser1->traverse($nodesToTraverse);

        $traverser2 = new NodeTraverser();
        $traverser2->addVisitor(new ContractVisitor());

        /** @var array<\PhpParser\Node\Stmt> $newStmts */
        $newStmts = $traverser2->traverse($newStmts);

        $printer = new TypePHPPrinter();
        $transformed = $printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

        $transformed = preg_replace('/[ \t]*\r?\n[ \t]*\/\*__TYPEPHP_INJECTED_START__\*\//', ' /*__TYPEPHP_INJECTED_START__*/', $transformed) ?? $transformed;

        $transformedLineCount = substr_count($transformed, "\n");
        $drift = $transformedLineCount - $originalLineCount;

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

        $transformed = str_replace(['/*__TYPEPHP_INJECTED_START__*/', '/*__TYPEPHP_INJECTED_END__*/'], '', $transformed);

        return $transformed;
    }

    /**
     * Opens a file stream, intercepting application files for AST transformation.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::unregister();
        $exists = self::silent(fn () => file_exists($path));
        $resolvedPath = $exists ? realpath($path) : '';
        self::register();

        $isAppFile = $exists && ! self::isReadOnlyCall() && self::isApplicationFile($path, $resolvedPath);

        if (! $isAppFile || $resolvedPath === false) {
            self::unregister();
            $targetFile = ($resolvedPath !== false && $resolvedPath !== '') ? $resolvedPath : $path;

            /** @var resource|false $handle */
            $handle = self::silent(fn () => fopen($targetFile, $mode));

            $this->handle = $handle !== false ? $handle : null;
            self::register();

            return $this->handle !== null;
        }

        self::unregister();

        $success = self::$cacheEnabled
            ? $this->openCachedStream($resolvedPath, $mode)
            : $this->openMemoryStream($resolvedPath);

        self::register();

        return $success;
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

        // CRITICAL: PHPStan's internal stub narrows flock's $operation parameter to int<0, 7>.
        // At PHP runtime, valid bitwise lock operations (such as LOCK_UN = 8 or LOCK_UN | LOCK_NB = 12)
        // range from 1 to 15. Passing 0 to flock() causes PHP to throw a warning ("must be one of LOCK_SH,
        // LOCK_EX, or LOCK_UN").
        //
        // CONSEQUENCE OF IGNORING: if it bypass PHPStan's narrow stub check here.
        // RUNTIME SAFETY: The guard clause above ($operation < 1 || $operation > 15) guarantees that invalid
        // operations (like 0) never reach flock(), preserving full runtime safety and compatibility
        // with Pest/PHPUnit result-caching (file_put_contents).
        //
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

        return fseek($this->handle, $offset, $whence) === 0;
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
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        self::unregister();
        /** @var array<int|string, int>|false $result */
        $result = self::silent(fn () => stat($path));
        self::register();

        return $result;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
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
        self::unregister();
        $result = (bool) self::silent(fn () => mkdir($path, $mode, (bool) ($options & STREAM_MKDIR_RECURSIVE)));
        self::register();

        return $result;
    }

    public function rmdir(string $path, int $options): bool
    {
        self::unregister();
        $result = (bool) self::silent(fn () => rmdir($path));
        self::register();

        return $result;
    }

    public function unlink(string $path): bool
    {
        self::unregister();
        $result = (bool) self::silent(fn () => unlink($path));
        self::register();

        return $result;
    }

    public function rename(string $pathFrom, string $pathTo): bool
    {
        self::unregister();
        $result = (bool) self::silent(fn () => rename($pathFrom, $pathTo));
        self::register();

        return $result;
    }

    /**
     * Converts a glob pattern into an absolute regex pattern for path matching.
     */
    private static function compileGlobToRegex(string $glob): string
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
            $pattern = '(^' . preg_quote(self::$baseDir . '/', '#') . '|^.*\/)' . $regex . '$';
        }

        return '#' . $pattern . '#i';
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
     * Determines if the current stream_open call is directly for reading file contents (e.g. file_get_contents)
     * rather than PHP engine's require/include execution.
     */
    private static function isReadOnlyCall(): bool
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $callerFunc = strtolower($trace[2]['function'] ?? '');

        return \in_array($callerFunc, ['file_get_contents', 'file', 'readfile', 'highlight_file', 'show_source', 'token_get_all'], true);
    }

    /**
     * Determines whether a target PHP file path should be intercepted using Pattern Specificity.
     */
    private static function isApplicationFile(string $path, string|false $resolvedPath): bool
    {
        if (! (bool) (Config::get()['enabled'] ?? true)) {
            return false; // TypePHP is globally disabled!
        }

        if (! str_ends_with($path, '.php') || $resolvedPath === false) {
            return false;
        }

        $normalizedPath = str_replace('\\', '/', $resolvedPath);

        // Prevent parsing TypePHP's own source code
        $parentDir = realpath(__DIR__ . '/..');
        $libSrcDir = $parentDir !== false ? str_replace('\\', '/', $parentDir) : '';

        if ($libSrcDir !== '' && str_starts_with($normalizedPath, $libSrcDir)) {
            return false;
        }

        // Unconditionally prevent double-parsing cached files!
        $normalizedCacheDir = rtrim(str_replace('\\', '/', self::$cacheDir), '/') . '/';
        if (str_starts_with($normalizedPath, $normalizedCacheDir)) {
            return false;
        }

        $longestIncludeMatch = 0;
        foreach (self::$includeRawPatterns as $pattern => $regex) {
            if (preg_match($regex, $normalizedPath) === 1) {
                $longestIncludeMatch = max($longestIncludeMatch, \strlen($pattern));
            }
        }

        if ($longestIncludeMatch === 0) {
            return false;
        }

        $longestExcludeMatch = 0;
        foreach (self::$excludeRawPatterns as $pattern => $regex) {
            if (preg_match($regex, $normalizedPath) === 1) {
                $longestExcludeMatch = max($longestExcludeMatch, \strlen($pattern));
            }
        }

        // Equal specificity tie-breaker: Exclude wins!
        return $longestIncludeMatch > $longestExcludeMatch;
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
        $cacheDir = self::$cacheDir;
        if (! is_dir($cacheDir)) {
            self::silent(fn () => mkdir($cacheDir, 0777, true));
        }

        $cachedFile = CacheManager::getCachedFilePath($resolvedPath);

        if (! file_exists($cachedFile)) {
            $source = file_get_contents($resolvedPath);
            if ($source !== false) {
                $transformed = self::transformSource($source, $resolvedPath);
                file_put_contents($cachedFile, $transformed);
            }
        }

        $cacheHandle = fopen($cachedFile, $mode);
        $this->handle = $cacheHandle !== false ? $cacheHandle : null;

        return $this->handle !== null;
    }

    /**
     * Scans top-level AST statements for namespace and use import declarations to seed SpecialTypeResolver.
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
            }
        }

        SpecialTypeResolver::seedFileMetadata($filePath, $namespace, $imports);
    }
}
