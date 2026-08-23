<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * @internal Indexes and resolves DocBlock stub overrides for third-party classes, methods, properties, and functions.
 */
final class StubManager
{
    private static bool $initialized = false;

    /**
     * @var array<string, string> ClassName::methodName => DocCommentText
     */
    private static array $methodStubs = [];

    /**
     * @var array<string, string> ClassName::$propertyName => DocCommentText
     */
    private static array $propertyStubs = [];

    /**
     * @var array<string, string> ClassName => ClassDocCommentText
     */
    private static array $classStubs = [];

    /**
     * @var array<string, string> functionName => DocCommentText
     */
    private static array $functionStubs = [];

    /**
     * Resets the indexed stub cache. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$initialized = false;
        self::$methodStubs = [];
        self::$propertyStubs = [];
        self::$classStubs = [];
        self::$functionStubs = [];
    }

    /**
     * Initializes and indexes stub files configured in typephp.php and registered extensions.
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;
        $config = Config::get();
        $rawStubs = $config['stubs'] ?? [];

        /** @var array<int, string> $stubGlobs */
        $stubGlobs = \is_array($rawStubs) ? array_filter($rawStubs, 'is_string') : [];

        if (\count($stubGlobs) === 0) {
            return;
        }

        self::loadStubFiles($stubGlobs);
    }

    public static function getMethodDoc(string $className, string $methodName): ?string
    {
        self::init();

        return self::$methodStubs[$className . '::' . $methodName] ?? null;
    }

    public static function getPropertyDoc(string $className, string $propertyName): ?string
    {
        self::init();

        return self::$propertyStubs[$className . '::$' . $propertyName] ?? null;
    }

    public static function getClassDoc(string $className): ?string
    {
        self::init();

        return self::$classStubs[$className] ?? null;
    }

    public static function getFunctionDoc(string $functionName): ?string
    {
        self::init();

        return self::$functionStubs[$functionName] ?? null;
    }

    public static function hasMethodStub(string $className, string $methodName): bool
    {
        self::init();

        return isset(self::$methodStubs[$className . '::' . $methodName]);
    }

    public static function hasPropertyStub(string $className, string $propertyName): bool
    {
        self::init();

        return isset(self::$propertyStubs[$className . '::$' . $propertyName]);
    }

    public static function hasClassStub(string $className): bool
    {
        self::init();

        return isset(self::$classStubs[$className]);
    }

    public static function hasFunctionStub(string $functionName): bool
    {
        self::init();

        return isset(self::$functionStubs[$functionName]);
    }

    /**
     * @param array<int, string> $globs
     */
    private static function loadStubFiles(array $globs): void
    {
        $projectRoot = Config::getProjectRoot();
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        foreach ($globs as $pattern) {
            $files = self::resolveStubFiles($pattern, $projectRoot);

            foreach ($files as $file) {
                $source = file_get_contents($file);
                if ($source === false) {
                    continue;
                }

                try {
                    $stmts = $parser->parse($source);
                    if ($stmts !== null) {
                        self::extractStubsFromAst($stmts);
                    }
                } catch (Throwable $e) {
                    // Silently ignore malformed stub files
                }
            }
        }
    }

    /**
     * Resolves files matching any file extension (.stub, .stub.php, .php, custom) for a glob pattern.
     *
     * @return list<string>
     */
    private static function resolveStubFiles(string $pattern, string $projectRoot): array
    {
        $pattern = str_replace('\\', '/', trim($pattern));
        $isAbsolute = str_starts_with($pattern, '/') || (bool) preg_match('#^[a-zA-Z]:/#', $pattern);
        $fullPath = $isAbsolute ? $pattern : ($projectRoot . '/' . ltrim($pattern, '/'));

        if (is_file($fullPath)) {
            return [$fullPath];
        }

        if (str_contains($pattern, '*')) {
            $parts = explode('*', $fullPath, 2);
            $rawPrefix = rtrim($parts[0], '/');
            $baseDir = is_dir($rawPrefix) ? $rawPrefix : \dirname($rawPrefix);

            if (! is_dir($baseDir)) {
                return [];
            }

            $regex = PathMatcher::compileGlobToRegex($pattern, $projectRoot);
            $matchedFiles = [];

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($iterator as $fileInfo) {
                    if ($fileInfo instanceof SplFileInfo && $fileInfo->isFile()) {
                        $real = str_replace('\\', '/', (string) $fileInfo->getRealPath());
                        if (preg_match($regex, $real) === 1 || preg_match($regex, str_replace('\\', '/', $fileInfo->getPathname())) === 1) {
                            $matchedFiles[] = $real;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Ignore filesystem access errors
            }

            return $matchedFiles;
        }

        if (is_dir($fullPath)) {
            $matchedFiles = [];

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($fullPath, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $fileInfo) {
                    if ($fileInfo instanceof SplFileInfo && $fileInfo->isFile()) {
                        $real = $fileInfo->getRealPath();
                        if ($real !== false) {
                            $matchedFiles[] = str_replace('\\', '/', $real);
                        }
                    }
                }
            } catch (Throwable $e) {
                // Ignore filesystem access errors
            }

            return $matchedFiles;
        }

        return [];
    }

    /**
     * @param array<Node\Stmt> $stmts
     */
    private static function extractStubsFromAst(array $stmts, string $namespace = ''): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $ns = $stmt->name !== null ? $stmt->name->toString() : '';
                self::extractStubsFromAst($stmt->stmts, $ns);
            } elseif ($stmt instanceof Node\Stmt\Function_) {
                $funcName = $namespace !== '' ? $namespace . '\\' . $stmt->name->toString() : $stmt->name->toString();
                $doc = $stmt->getDocComment();
                if ($doc !== null) {
                    self::$functionStubs[$funcName] = $doc->getText();
                }
            } elseif (
                $stmt instanceof Node\Stmt\Class_
                || $stmt instanceof Node\Stmt\Interface_
                || $stmt instanceof Node\Stmt\Trait_
                || $stmt instanceof Node\Stmt\Enum_
            ) {
                if ($stmt->name === null) {
                    continue;
                }

                $className = $namespace !== '' ? $namespace . '\\' . $stmt->name->toString() : $stmt->name->toString();

                $classDoc = $stmt->getDocComment();
                if ($classDoc !== null) {
                    self::$classStubs[$className] = $classDoc->getText();
                }

                foreach ($stmt->stmts as $member) {
                    if ($member instanceof Node\Stmt\ClassMethod) {
                        $mDoc = $member->getDocComment();
                        if ($mDoc !== null) {
                            self::$methodStubs[$className . '::' . $member->name->toString()] = $mDoc->getText();
                        }
                    } elseif ($member instanceof Node\Stmt\Property) {
                        $pDoc = $member->getDocComment();
                        if ($pDoc !== null) {
                            foreach ($member->props as $prop) {
                                self::$propertyStubs[$className . '::$' . $prop->name->toString()] = $pDoc->getText();
                            }
                        }
                    }
                }
            }
        }
    }
}
