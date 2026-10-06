<?php

declare(strict_types=1);

namespace TypePHP\Internal\Reporting;

use ReflectionClass;
use ReflectionFunction;
use Throwable;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Resolver\HierarchyResolver;
use TypePHP\Internal\Util\Config;

/**
 * @internal Manages violation reporting, deduplication, warning dispatch, and parallel test sharding.
 */
final class ViolationCollector
{
    /**
     * In-memory map of recorded violations keyed by deduplication hash.
     *
     * @var array<string, ViolationRecord>
     */
    private static array $violations = [];

    /**
     * In-memory set of emitted warning hashes to prevent log spamming in loops.
     *
     * @var array<string, true>
     */
    private static array $warnedHashes = [];

    /**
     * In-memory cache for property declaration lines: [$file][$propName] => ?int.
     *
     * @var array<string, array<string, ?int>>
     */
    private static array $propertyLineCache = [];

    private static bool $shutdownRegistered = false;

    /**
     * Resets in-memory state. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$violations = [];
        self::$warnedHashes = [];
        self::$propertyLineCache = [];
    }

    /**
     * Handles a validation failure according to the configured on_violation mode ('throw', 'warn', 'report').
     *
     * @param 'parameter'|'return'|'property'|'variable'|'param-out'|'self-out'|'callback'|'yield'|'send' $kind
     */
    public static function handle(
        ErrorMessage $error,
        string $kind,
        mixed $passThroughValue,
        ?string $file = null,
        ?int $line = null,
        ?string $function = null,
        ?string $target = null,
        ?string $declaredIn = null
    ): mixed {
        $mode = Config::getOnViolation();

        if ($mode === 'throw') {
            return $error;
        }

        $message = $error->getMessage();
        [$resolvedFile, $resolvedLine, $resolvedCaller] = self::resolveCallSite($file, $line, $kind);
        $parsed = self::parseMetadata($message, $kind, $function, $target);

        $resolvedDeclaredIn = self::resolveDeclaredIn(
            $declaredIn,
            $parsed['function'],
            $kind,
            $parsed['target'],
            $resolvedFile,
            $resolvedLine,
            $message
        );

        $record = new ViolationRecord(
            file: self::normalizeRelativePath($resolvedFile),
            line: $resolvedLine,
            function: $parsed['function'],
            kind: $kind,
            target: $parsed['target'],
            expected: $parsed['expected'],
            given: $parsed['given'],
            message: $message,
            count: 1,
            caller: $resolvedCaller,
            declaredIn: $resolvedDeclaredIn
        );

        $hash = $record->getHash();

        if ($mode === 'warn') {
            self::emitWarningOnce($hash, $message, $resolvedFile, $resolvedLine, $kind);

            return $passThroughValue;
        }

        if ($mode === 'report') {
            self::recordViolation($hash, $record);

            return $passThroughValue;
        }

        return $error;
    }

    /**
     * Emits PHP E_USER_WARNING once per unique violation hash with caller location.
     */
    private static function emitWarningOnce(string $hash, string $message, string $file, int $line, string $kind): void
    {
        if (isset(self::$warnedHashes[$hash])) {
            return;
        }

        self::$warnedHashes[$hash] = true;

        $locationPrefix = ($kind === 'parameter' || $kind === 'callback') ? 'called in' : 'in';
        $location = ($file !== '' && $file !== 'unknown') ? ", {$locationPrefix} {$file} on line {$line}" : '';

        trigger_error("[TypePHP Violation] {$message}{$location}", E_USER_WARNING);
    }

    /**
     * Records violation or increments occurrence count if previously encountered.
     */
    private static function recordViolation(string $hash, ViolationRecord $record): void
    {
        if (isset(self::$violations[$hash])) {
            self::$violations[$hash] = self::$violations[$hash]->withIncrementedCount();
        } else {
            self::$violations[$hash] = $record;
        }

        if (! self::$shutdownRegistered) {
            self::registerShutdownHandler();
            self::$shutdownRegistered = true;
        }
    }

    /**
     * Returns all in-memory violations recorded in the current process.
     *
     * @return list<ViolationRecord>
     */
    public static function getViolations(): array
    {
        return array_values(self::$violations);
    }

    /**
     * Clears all in-memory violations.
     */
    public static function clear(): void
    {
        self::$violations = [];
        self::$warnedHashes = [];
        self::$propertyLineCache = [];
    }

    /**
     * Normalizes absolute system paths into clean project-relative paths with forward slashes.
     */
    public static function normalizeRelativePath(string $path): string
    {
        if ($path === '') {
            return 'unknown';
        }

        $normPath = str_replace('\\', '/', $path);
        $projectRoot = str_replace('\\', '/', Config::getProjectRoot());

        if (str_starts_with($normPath, $projectRoot . '/')) {
            return substr($normPath, \strlen($projectRoot) + 1);
        }

        return $normPath;
    }

    /**
     * Registers shutdown hook to write worker shards or master report and optionally exit with failure.
     */
    private static function registerShutdownHandler(): void
    {
        register_shutdown_function(static function (): void {
            if (Config::getOnViolation() !== 'report') {
                return;
            }

            $violationCount = \count(self::$violations);
            if ($violationCount === 0) {
                return;
            }

            $reportFile = Config::getReportFile();
            $testToken = getenv('TEST_TOKEN');
            $pestWorkerId = getenv('PEST_PARALLEL_WORKER_ID');
            $isParaTest = getenv('PARATEST') !== false;

            $isParallelWorker = ($testToken !== false && $testToken !== '')
                || ($pestWorkerId !== false && $pestWorkerId !== '')
                || $isParaTest;

            if ($reportFile !== null) {
                if ($isParallelWorker) {
                    self::writeWorkerShard($reportFile);
                } else {
                    self::exportReport($reportFile);
                }
            }

            if (Config::isFailOnReportEnabled()) {
                @fwrite(STDERR, "\n[TypePHP] {$violationCount} contract violation(s) recorded in report. Exiting with status 1.\n");
                exit(1);
            }
        });
    }

    /**
     * Writes process-isolated shard during parallel testing without acquiring locks.
     */
    public static function writeWorkerShard(string $reportFile): void
    {
        if (self::$violations === []) {
            return;
        }

        $reportDir = \dirname($reportFile);
        $shardDir = $reportDir . '/.typephp-shards';

        if (! is_dir($shardDir)) {
            @mkdir($shardDir, 0777, true);
        }

        $testToken = getenv('TEST_TOKEN');
        $pestWorkerId = getenv('PEST_PARALLEL_WORKER_ID');

        $workerId = ($testToken !== false && $testToken !== '')
            ? $testToken
            : (($pestWorkerId !== false && $pestWorkerId !== '') ? $pestWorkerId : (string) getmypid());

        $safeToken = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $workerId);
        $shardFile = $shardDir . '/shard_' . $safeToken . '_' . bin2hex(random_bytes(4)) . '.json';
        $content = json_encode(array_values(self::$violations), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($content !== false) {
            @file_put_contents($shardFile, $content);
        }
    }

    /**
     * Merges in-memory violations with any worker shards and atomically exports the master report JSON.
     */
    public static function exportReport(?string $filePath = null): ?string
    {
        $targetFile = $filePath !== null ? $filePath : Config::getReportFile();
        if ($targetFile === null) {
            return null;
        }

        $reportDir = \dirname($targetFile);
        $shardDir = $reportDir . '/.typephp-shards';

        $allViolations = self::consolidateShards(self::$violations, $shardDir);

        if (! is_dir($reportDir)) {
            @mkdir($reportDir, 0777, true);
        }

        $document = self::buildReportDocument($allViolations);
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false || @file_put_contents($targetFile, $json) === false) {
            return null;
        }

        return $targetFile;
    }

    /**
     * @param array<string, ViolationRecord> $baseViolations
     *
     * @return array<string, ViolationRecord>
     */
    private static function consolidateShards(array $baseViolations, string $shardDir): array
    {
        if (! is_dir($shardDir)) {
            return $baseViolations;
        }

        $shardFiles = glob($shardDir . '/shard_*.json');
        if ($shardFiles === false) {
            @rmdir($shardDir);

            return $baseViolations;
        }

        foreach ($shardFiles as $sFile) {
            $raw = @file_get_contents($sFile);
            if ($raw !== false) {
                $baseViolations = self::mergeShardContent($baseViolations, $raw);
            }
            @unlink($sFile);
        }

        @rmdir($shardDir);

        return $baseViolations;
    }

    /**
     * @param array<string, ViolationRecord> $allViolations
     *
     * @return array<string, ViolationRecord>
     */
    private static function mergeShardContent(array $allViolations, string $raw): array
    {
        /** @var list<array<string, mixed>>|null $decoded */
        $decoded = json_decode($raw, true);
        if (! \is_array($decoded)) {
            return $allViolations;
        }

        foreach ($decoded as $item) {
            if (! \is_array($item)) {
                continue;
            }

            $record = ViolationRecord::fromArray($item);
            if ($record === null) {
                continue;
            }

            $h = $record->getHash();
            $allViolations[$h] = isset($allViolations[$h])
                ? $allViolations[$h]->withIncrementedCount($record->count)
                : $record;
        }

        return $allViolations;
    }

    /**
     * @param array<string, ViolationRecord> $violations
     *
     * @return array{version: string, generated_at: string, summary: array{total_violations: int, files_affected: int}, violations: list<ViolationRecord>}
     */
    private static function buildReportDocument(array $violations): array
    {
        $violationsList = array_values($violations);
        $filesAffected = [];
        foreach ($violationsList as $v) {
            $filesAffected[$v->file] = true;
        }

        return [
            'version' => '1.0',
            'generated_at' => gmdate('c'),
            'summary' => [
                'total_violations' => \count($violationsList),
                'files_affected' => \count($filesAffected),
            ],
            'violations' => $violationsList,
        ];
    }

    /**
     * Resolves the declaration origin (file and line of the DocBlock).
     */
    private static function resolveDeclaredIn(
        ?string $explicitDeclaredIn,
        string $function,
        string $kind,
        string $target,
        string $resolvedFile,
        int $resolvedLine,
        string $message
    ): ?string {
        if ($explicitDeclaredIn !== null && $explicitDeclaredIn !== '') {
            return self::normalizeRelativePath($explicitDeclaredIn);
        }

        $genericDecl = self::resolveGenericBoundDeclaration($message);
        if ($genericDecl !== null) {
            return $genericDecl;
        }

        if ($kind === 'variable') {
            return self::normalizeRelativePath($resolvedFile) . ':' . $resolvedLine;
        }

        try {
            if ($kind === 'property' || str_contains($function, '::$')) {
                return self::resolvePropertyDeclaration($function);
            }

            if (str_contains($function, '::')) {
                return self::resolveMethodDeclaration($function);
            }

            return self::resolveFunctionDeclaration($function);
        } catch (Throwable) {
            return null;
        }
    }

    private static function resolveGenericBoundDeclaration(string $message): ?string
    {
        $hasPattern = preg_match('/does not satisfy upper bound .+? in ([a-zA-Z0-9_\\\\]+)/', $message, $m) === 1
            || preg_match('/of template .+? in ([a-zA-Z0-9_\\\\]+)/', $message, $m) === 1;

        if (! $hasPattern) {
            return null;
        }

        $className = trim($m[1]);
        if (! (class_exists($className) || interface_exists($className) || trait_exists($className))) {
            return null;
        }

        try {
            /** @var class-string<object> $className */
            $refClass = new ReflectionClass($className);
            $file = $refClass->getFileName();
            if ($file === false) {
                return null;
            }

            $startLine = $refClass->getStartLine();

            return self::normalizeRelativePath($file) . ':' . ($startLine !== false ? $startLine : 1);
        } catch (Throwable) {
            return null;
        }
    }

    private static function resolvePropertyDeclaration(string $function): ?string
    {
        $rawTarget = str_replace('::$', '::', $function);
        if (! str_contains($rawTarget, '::')) {
            return null;
        }

        [$className, $propName] = explode('::', $rawTarget, 2);
        $propName = ltrim($propName, '$');

        if (! (class_exists($className) || trait_exists($className) || interface_exists($className))) {
            return null;
        }

        /** @var class-string<object> $className */
        $refClass = new ReflectionClass($className);
        $resolved = DocblockParser::findDeclaredPropertyDoc($refClass, $propName);
        if ($resolved === null || ! isset($resolved['declaringClass'])) {
            return null;
        }

        $declaringClass = $resolved['declaringClass'];
        $file = $declaringClass->getFileName();
        if ($file === false) {
            return null;
        }

        $propertyLine = self::findPropertyDeclarationLine($file, $propName);
        $startLine = $declaringClass->getStartLine();
        $line = $propertyLine ?? ($startLine !== false ? $startLine : 1);

        return self::normalizeRelativePath($file) . ':' . $line;
    }

    private static function findPropertyDeclarationLine(string $file, string $propName): ?int
    {
        if (isset(self::$propertyLineCache[$file][$propName]) || \array_key_exists($propName, self::$propertyLineCache[$file] ?? [])) {
            return self::$propertyLineCache[$file][$propName];
        }

        if (! file_exists($file)) {
            return self::$propertyLineCache[$file][$propName] = null;
        }

        $source = @file_get_contents($file);
        if ($source === false || ! str_contains($source, '$' . $propName)) {
            return self::$propertyLineCache[$file][$propName] = null;
        }

        try {
            $tokens = \PhpToken::tokenize($source);
            $count = \count($tokens);
            $hasModifier = false;

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];

                if (\in_array($token->id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_READONLY], true)) {
                    $hasModifier = true;
                } elseif ($token->id === T_FUNCTION || $token->text === ';' || $token->text === '{') {
                    $hasModifier = false;
                }

                if ($hasModifier && $token->id === T_VARIABLE && $token->text === '$' . $propName) {
                    return self::$propertyLineCache[$file][$propName] = $token->line;
                }
            }
        } catch (Throwable) {
            return self::$propertyLineCache[$file][$propName] = null;
        }

        return self::$propertyLineCache[$file][$propName] = null;
    }

    private static function resolveMethodDeclaration(string $function): ?string
    {
        [$className, $methodName] = explode('::', $function, 2);
        if (! (class_exists($className) || trait_exists($className) || interface_exists($className))) {
            return null;
        }

        /** @var class-string<object> $className */
        $refClass = new ReflectionClass($className);
        if (! $refClass->hasMethod($methodName)) {
            return null;
        }

        $refMethod = $refClass->getMethod($methodName);
        $hierarchy = HierarchyResolver::getMethodHierarchy($refMethod);

        foreach ($hierarchy as $hierMethod) {
            $doc = $hierMethod->getDocComment();
            if ($doc !== false && $doc !== null) {
                $file = $hierMethod->getFileName();
                if ($file !== false) {
                    return self::normalizeRelativePath($file) . ':' . $hierMethod->getStartLine();
                }
            }
        }

        $file = $refMethod->getFileName();

        return $file !== false ? self::normalizeRelativePath($file) . ':' . $refMethod->getStartLine() : null;
    }

    private static function resolveFunctionDeclaration(string $function): ?string
    {
        if ($function === '' || ! \function_exists($function)) {
            return null;
        }

        $refFunc = new ReflectionFunction($function);
        $file = $refFunc->getFileName();

        return $file !== false ? self::normalizeRelativePath($file) . ':' . $refFunc->getStartLine() : null;
    }

    /**
     * @return array{0: string, 1: int, 2: ?string}
     */
    private static function resolveCallSite(?string $explicitFile, ?int $explicitLine, string $kind = 'parameter'): array
    {
        $appFrames = self::collectApplicationFrames();

        if ($explicitFile !== null && $explicitFile !== '' && $explicitLine !== null && $explicitLine > 0) {
            $callerLabel = self::formatCallerLabel($appFrames, 0);

            return [$explicitFile, $explicitLine, $callerLabel];
        }

        if (($kind === 'parameter' || $kind === 'callback') && \count($appFrames) >= 2) {
            $f = $appFrames[1];
            $callerLabel = self::formatCallerLabel($appFrames, 1);

            return [$f['file'], $f['line'], $callerLabel];
        }

        if ($appFrames !== []) {
            $f = $appFrames[0];
            $callerLabel = self::formatCallerLabel($appFrames, 1);

            return [$f['file'], $f['line'], $callerLabel];
        }

        return [$explicitFile ?? 'unknown', $explicitLine ?? 1, null];
    }

    /**
     * @return list<array{file: string, line: int, caller: ?string}>
     */
    private static function collectApplicationFrames(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
        $appFrames = [];

        foreach ($trace as $frame) {
            if (! isset($frame['file']) || ! \is_string($frame['file'])) {
                continue;
            }

            $normalizedFile = str_replace('\\', '/', $frame['file']);
            if (
                str_contains($normalizedFile, 'src/Internal/')
                || str_contains($normalizedFile, 'bin/typephp')
                || str_contains($normalizedFile, 'vendor/phpunit/')
                || str_contains($normalizedFile, 'vendor/pestphp/')
            ) {
                continue;
            }

            $l = isset($frame['line']) && \is_int($frame['line']) ? $frame['line'] : 1;
            $func = $frame['function'];
            $class = isset($frame['class']) && \is_string($frame['class']) ? $frame['class'] . '::' : '';
            $callLabel = $class . $func;
            if ($callLabel !== '' && ! str_ends_with($callLabel, '()')) {
                $callLabel .= '()';
            }

            $appFrames[] = [
                'file' => $frame['file'],
                'line' => $l,
                'caller' => $callLabel !== '' ? $callLabel : null,
            ];
        }

        return $appFrames;
    }

    /**
     * @param list<array{file: string, line: int, caller: ?string}> $appFrames
     */
    private static function formatCallerLabel(array $appFrames, int $index): ?string
    {
        if (! isset($appFrames[$index])) {
            return null;
        }

        $f = $appFrames[$index];

        return self::normalizeRelativePath($f['file']) . ':' . $f['line'] . ($f['caller'] !== null ? " ({$f['caller']})" : '');
    }

    /**
     * @return array{function: string, target: string, expected: string, given: string}
     */
    private static function parseMetadata(string $message, string $kind, ?string $explicitFunction, ?string $explicitTarget): array
    {
        $function = $explicitFunction ?? self::extractFunctionFromMessage($message);
        $target = $explicitTarget ?? self::extractTargetFromMessage($message, $kind);

        [$expected, $given, $inferredFunction] = self::extractExpectedAndGiven($message);

        if ($function === '' && $inferredFunction !== null) {
            $function = $inferredFunction;
        }

        return [
            'function' => $function !== '' ? $function : 'unknown',
            'target' => $target !== '' ? $target : $kind,
            'expected' => $expected !== '' ? $expected : 'valid type',
            'given' => $given !== '' ? $given : 'invalid value',
        ];
    }

    private static function extractFunctionFromMessage(string $message): string
    {
        if (preg_match('/^([a-zA-Z0-9_\\\\]+(?:::[a-zA-Z0-9_\x80-\xff]+)?)\(\):/', $message, $m) === 1) {
            return trim($m[1]);
        }
        if (preg_match('/^Property\s+([a-zA-Z0-9_\\\\]+(?:::\$[a-zA-Z0-9_\x80-\xff]+)?)/', $message, $m) === 1) {
            return trim($m[1]);
        }
        if (preg_match('/^([^:]+):/', $message, $m) === 1) {
            return trim($m[1]);
        }

        return '';
    }

    private static function extractTargetFromMessage(string $message, string $kind): string
    {
        if (preg_match('/(?:Argument|Parameter)\s+(\$[a-zA-Z0-9_]+)/', $message, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/(?:Property)\s+[^$]*(\$[a-zA-Z0-9_]+)/', $message, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/(?:Variable)\s+(\$[a-zA-Z0-9_]+)/', $message, $m) === 1) {
            return $m[1];
        }
        if (str_contains($message, 'Return value')) {
            return 'return';
        }

        return $kind;
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}
     */
    private static function extractExpectedAndGiven(string $message): array
    {
        if (preg_match('/Generic type argument (.+?) does not satisfy upper bound (.+?) of template ([a-zA-Z0-9_]+) in ([a-zA-Z0-9_\\\\]+)/', $message, $m) === 1) {
            return [trim($m[2]), 'type argument ' . trim($m[1]), trim($m[4])];
        }
        if (preg_match('/must be of type (.+?),\s*(.+?)\s*(?:given|returned)$/', $message, $m) === 1) {
            return [trim($m[1]), trim($m[2]), null];
        }
        if (preg_match('/must be (.+?),\s*(.+?)\s*(?:given|returned)$/', $message, $m) === 1) {
            return [trim($m[1]), trim($m[2]), null];
        }
        if (preg_match('/is missing required (?:key|property)\s*\'([^\']+)\'/', $message, $m) === 1) {
            return ["required '{$m[1]}'", 'missing', null];
        }
        if (preg_match('/contains unsealed unexpected key\s*\'([^\']+)\'/', $message, $m) === 1) {
            return ['sealed shape', "unexpected key '{$m[1]}'", null];
        }
        if (preg_match('/contains unexpected property\s*\'([^\']+)\'/', $message, $m) === 1) {
            return ['sealed shape', "unexpected property '{$m[1]}'", null];
        }
        if (preg_match('/expects (.+?),\s*but\s*(.+?)\s*was\s*(?:given|returned)/', $message, $m) === 1) {
            return [trim($m[1]), trim($m[2]), null];
        }

        return ['', '', null];
    }
}
