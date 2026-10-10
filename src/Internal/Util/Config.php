<?php

declare(strict_types=1);

namespace TypePHP\Internal\Util;

use TypePHP\Extension\ExtensionInterface;
use TypePHP\Internal\Checker\ParamChecker;
use TypePHP\Internal\Checker\ReturnChecker;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Io\CacheManager;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Reporting\ViolationCollector;
use TypePHP\Internal\Resolver\CallerBoundaryResolver;
use TypePHP\Internal\Resolver\HierarchyResolver;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\RuntimeTypeChecker;

/**
 * Global configuration manager for loading and dynamically overriding settings.
 *
 * @internal
 */
final class Config
{
    /**
     * Threshold (number of elements) above which Beartype O(1) hybrid random sampling is applied.
     */
    public const HYBRID_SAMPLE_THRESHOLD = 128;

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $cachedConfig = null;

    /**
     * Cached absolute project root path.
     */
    private static ?string $projectRoot = null;

    private static bool $enabled = true;

    private static bool $autoBoot = true;

    private static string $onViolation = 'throw';

    private static ?string $reportFile = null;

    private static bool $failOnReport = false;

    private static bool $redactValues = false;

    private static bool $params = true;

    private static bool $returns = true;

    private static bool $strictReturnGenericInvariance = true;

    private static bool $magicProperties = true;

    private static bool $magicPropertyWrites = true;

    private static bool $magicPropertyReads = false;

    private static bool $magicMethods = true;

    private static bool $respectIgnoreTags = true;

    private static bool $respectNativeNullability = true;

    private static bool $vendorBoundaryOnly = true;

    private static bool $cacheCheckMtime = true;

    private static bool $paramsOut = true;

    private static bool $selfOut = true;

    private static string $arrayValidation = 'full';

    private static bool $inlineProperties = true;

    private static bool $inlineGenerics = true;

    private static bool $inlineCallables = true;

    private static bool $inlineScalars = true;

    private static bool $inlineArrays = true;

    private static bool $inlineObjects = true;

    private static int $ignoreTraceDepth = 25;

    private static function getEnvValue(string $key): ?string
    {
        if (isset($_ENV[$key])) {
            $val = $_ENV[$key];
        } elseif (isset($_SERVER[$key])) {
            $val = $_SERVER[$key];
        } else {
            $env = getenv($key);
            $val = $env !== false ? $env : null;
        }

        if ($val === null) {
            return null;
        }

        if (\is_string($val)) {
            return $val;
        }

        if (\is_scalar($val)) {
            return (string) $val;
        }

        return null;
    }

    public static function isEnabled(): bool
    {
        $envDisable = self::getEnvValue('TYPEPHP_DISABLE');
        if ($envDisable !== null && trim($envDisable) !== '') {
            if (filter_var($envDisable, FILTER_VALIDATE_BOOLEAN)) {
                return false;
            }
        }

        $envEnabled = self::getEnvValue('TYPEPHP_ENABLED');
        if ($envEnabled !== null && trim($envEnabled) !== '') {
            if (! filter_var($envEnabled, FILTER_VALIDATE_BOOLEAN)) {
                return false;
            }
        }

        if (\defined('TYPEPHP_DISABLE') && TYPEPHP_DISABLE) {
            return false;
        }

        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$enabled;
    }

    public static function isAutoBootEnabled(): bool
    {
        $envAutoBoot = self::getEnvValue('TYPEPHP_AUTO_BOOT');
        if ($envAutoBoot !== null && trim($envAutoBoot) !== '') {
            return filter_var($envAutoBoot, FILTER_VALIDATE_BOOLEAN);
        }

        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$autoBoot;
    }

    public static function getOnViolation(): string
    {
        $envMode = self::getEnvValue('TYPEPHP_ON_VIOLATION');
        if ($envMode !== null && trim($envMode) !== '') {
            $normalized = strtolower(trim($envMode));
            if ($normalized === 'warning') {
                return 'warn';
            }

            return $normalized;
        }

        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$onViolation;
    }

    public static function getReportFile(): ?string
    {
        $envFile = self::getEnvValue('TYPEPHP_REPORT_FILE');
        if ($envFile !== null && trim($envFile) !== '') {
            return trim($envFile);
        }

        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$reportFile;
    }

    public static function isFailOnReportEnabled(): bool
    {
        $envFail = self::getEnvValue('TYPEPHP_FAIL_ON_REPORT');
        if ($envFail !== null && trim($envFail) !== '') {
            return filter_var($envFail, FILTER_VALIDATE_BOOLEAN);
        }

        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$failOnReport;
    }

    public static function isRedactValuesEnabled(): bool
    {
        $envRedact = self::getEnvValue('TYPEPHP_REDACT_VALUES');
        if ($envRedact !== null && trim($envRedact) !== '') {
            return filter_var($envRedact, FILTER_VALIDATE_BOOLEAN);
        }

        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$redactValues;
    }

    public static function isReportMode(): bool
    {
        return self::getOnViolation() === 'report';
    }

    public static function isWarnMode(): bool
    {
        return self::getOnViolation() === 'warn';
    }

    public static function getIgnoreTraceDepth(): int
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$ignoreTraceDepth;
    }

    public static function isInlinePropertiesEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$inlineProperties;
    }

    public static function isInlineGenericsEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$inlineGenerics;
    }

    public static function isInlineCallablesEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$inlineCallables;
    }

    public static function isInlineScalarsEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$inlineScalars;
    }

    public static function isInlineArraysEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$inlineArrays;
    }

    public static function isInlineObjectsEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$inlineObjects;
    }

    public static function hasActiveInlineChecks(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$inlineGenerics
            || self::$inlineCallables
            || self::$inlineScalars
            || self::$inlineArrays
            || self::$inlineObjects;
    }

    public static function isCacheCheckMtimeEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$cacheCheckMtime;
    }

    public static function isParamsOutEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$params && self::$paramsOut;
    }

    public static function isSelfOutEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$selfOut;
    }

    public static function isParamsEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$params;
    }

    public static function isReturnsEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$returns;
    }

    public static function isStrictReturnGenericInvarianceEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$strictReturnGenericInvariance;
    }

    public static function isMagicPropertiesEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$magicProperties;
    }

    public static function isMagicPropertyWritesEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$magicPropertyWrites;
    }

    public static function isMagicPropertyReadsEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$magicPropertyReads;
    }

    public static function isMagicMethodsEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$magicMethods;
    }

    public static function isRespectIgnoreTagsEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$respectIgnoreTags;
    }

    public static function isRespectNativeNullabilityEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$respectNativeNullability;
    }

    public static function isVendorBoundaryOnlyEnabled(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$vendorBoundaryOnly;
    }

    public static function isArrayValidationHybrid(): bool
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$arrayValidation === 'hybrid';
    }

    public static function getArrayValidationStrategy(): string
    {
        if (self::$cachedConfig === null) {
            self::get();
        }

        return self::$arrayValidation;
    }

    /**
     * Locates the project root directory by searching upwards for vendor/autoload.php or composer.json.
     * Caches the result in memory so the search happens exactly once.
     */
    public static function getProjectRoot(?string $startingDir = null): string
    {
        if ($startingDir === null && self::$projectRoot !== null) {
            return self::$projectRoot;
        }

        if ($startingDir === null && class_exists(\Composer\InstalledVersions::class)) {
            try {
                $rootPackage = \Composer\InstalledVersions::getRootPackage();
                $installPath = $rootPackage['install_path'] ?? null;
                if (\is_string($installPath) && $installPath !== '') {
                    $realPath = realpath($installPath);
                    $normPath = rtrim(str_replace('\\', '/', $realPath !== false ? $realPath : $installPath), '/');
                    if (file_exists($normPath . '/composer.json') || file_exists($normPath . '/typephp.php')) {
                        return self::$projectRoot = $normPath;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $cwd = getcwd();
        if ($startingDir === null && $cwd !== false) {
            $realCwd = realpath($cwd);
            $checkCwd = rtrim(str_replace('\\', '/', $realCwd !== false ? $realCwd : $cwd), '/');
            for ($i = 0; $i < 5; $i++) {
                if (file_exists($checkCwd . '/composer.json') || file_exists($checkCwd . '/typephp.php')) {
                    return self::$projectRoot = $checkCwd;
                }
                $parent = \dirname($checkCwd);
                if ($parent === $checkCwd) {
                    break;
                }
                $checkCwd = $parent;
            }
        }

        $dir = str_replace('\\', '/', $startingDir ?? __DIR__);

        if (str_contains($dir, '/vendor/')) {
            $vendorPos = strrpos($dir, '/vendor/');
            if ($vendorPos !== false) {
                $candidate = substr($dir, 0, $vendorPos);
                $realCandidate = realpath($candidate);
                $normCandidate = rtrim(str_replace('\\', '/', $realCandidate !== false ? $realCandidate : $candidate), '/');
                if (file_exists($normCandidate . '/vendor/autoload.php') || file_exists($normCandidate . '/composer.json')) {
                    return self::$projectRoot = $normCandidate;
                }
            }
        }

        $firstCandidate = null;

        for ($i = 0; $i < 15; $i++) {
            if (file_exists($dir . '/composer.json') || file_exists($dir . '/typephp.php') || file_exists($dir . '/vendor/autoload.php')) {
                $realDir = realpath($dir);
                $normFound = rtrim(str_replace('\\', '/', $realDir !== false ? $realDir : $dir), '/');

                if (str_ends_with($normFound, '/typephp/typephp')) {
                    $firstCandidate ??= $normFound;
                } else {
                    return self::$projectRoot = $normFound;
                }
            }

            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        if ($firstCandidate !== null) {
            return self::$projectRoot = $firstCandidate;
        }

        if ($cwd !== false) {
            $realCwd = realpath($cwd);
            $fallback = $realCwd !== false ? $realCwd : $cwd;
        } else {
            $fallback = '.';
        }

        return self::$projectRoot = rtrim(str_replace('\\', '/', $fallback), '/');
    }

    /**
     * Loads and caches global configuration from 'typephp.php', 'composer.json' extra, extensions, and base defaults.
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        if (self::$cachedConfig !== null) {
            return self::$cachedConfig;
        }

        $defaultConfig = [
            'enabled' => true,
            'auto_boot' => true,
            'on_violation' => 'throw',
            'report_file' => null,
            'fail_on_report' => false,
            'redact_values' => false,
            'params' => true,
            'returns' => true,
            'params_out' => true,
            'self_out' => true,
            'strict_return_generic_invariance' => true,
            'magic_properties' => [
                'write' => true,
                'read' => false,
            ],
            'magic_methods' => true,
            'respect_ignore_tags' => true,
            'ignore_trace_depth' => 25,
            'respect_native_nullability' => true,
            'vendor_boundary_only' => true,
            'array_validation' => 'full',
            'cache' => true,
            'cache_dir' => null,
            'cache_check_mtime' => true,
            'inline_vars' => [
                'properties' => true,
                'generics' => true,
                'callables' => true,
                'scalars' => true,
                'arrays' => true,
                'objects' => true,
            ],
            'include' => ['src/**', 'app/**', 'internals/**', 'tests/**'],
            'exclude' => ['vendor/**', 'storage/**', 'var/**', 'cache/**'],
            'extensions' => [],
            'stubs' => [],
        ];

        $projectRoot = self::getProjectRoot();
        $configFile = $projectRoot . '/typephp.php';
        $userConfig = [];

        if (file_exists($configFile)) {
            $loadedConfig = require $configFile;
            if (\is_array($loadedConfig)) {
                /** @var array<string, mixed> $userConfig */
                $userConfig = $loadedConfig;
            }
        }

        $composerJsonFile = $projectRoot . '/composer.json';
        if (file_exists($composerJsonFile)) {
            $content = @file_get_contents($composerJsonFile);
            if ($content !== false && (str_contains($content, 'typephp') || str_contains($content, 'auto-boot') || str_contains($content, 'on-violation') || str_contains($content, 'fail-on-report') || str_contains($content, 'redact-values'))) {
                $data = json_decode($content, true);
                if (
                    \is_array($data)
                    && isset($data['extra'])
                    && \is_array($data['extra'])
                    && isset($data['extra']['typephp'])
                    && \is_array($data['extra']['typephp'])
                ) {
                    if (! isset($userConfig['auto_boot']) && isset($data['extra']['typephp']['auto-boot'])) {
                        $userConfig['auto_boot'] = filter_var($data['extra']['typephp']['auto-boot'], FILTER_VALIDATE_BOOLEAN);
                    }
                    if (! isset($userConfig['on_violation']) && isset($data['extra']['typephp']['on-violation']) && \is_string($data['extra']['typephp']['on-violation'])) {
                        $userConfig['on_violation'] = $data['extra']['typephp']['on-violation'];
                    }
                    if (! isset($userConfig['report_file']) && isset($data['extra']['typephp']['report-file']) && \is_string($data['extra']['typephp']['report-file'])) {
                        $userConfig['report_file'] = $data['extra']['typephp']['report-file'];
                    }
                    if (! isset($userConfig['fail_on_report']) && isset($data['extra']['typephp']['fail-on-report'])) {
                        $userConfig['fail_on_report'] = filter_var($data['extra']['typephp']['fail-on-report'], FILTER_VALIDATE_BOOLEAN);
                    }
                    if (! isset($userConfig['redact_values']) && isset($data['extra']['typephp']['redact-values'])) {
                        $userConfig['redact_values'] = filter_var($data['extra']['typephp']['redact-values'], FILTER_VALIDATE_BOOLEAN);
                    }
                }
            }
        }

        /** @var array<int, class-string<ExtensionInterface>> $configuredExtensions */
        $configuredExtensions = \is_array($userConfig['extensions'] ?? null)
            ? $userConfig['extensions']
            : $defaultConfig['extensions'];

        $extensionIncludes = ExtensionManager::loadExtensionIncludes($configuredExtensions);
        $extensionStubs = ExtensionManager::loadExtensionStubs($configuredExtensions);

        $mergedConfig = self::mergeConfig($defaultConfig, $userConfig);

        /** @var array<int, string> $currentIncludes */
        $currentIncludes = \is_array($mergedConfig['include'] ?? null) ? $mergedConfig['include'] : [];
        /** @var array<int, string> $currentStubs */
        $currentStubs = \is_array($mergedConfig['stubs'] ?? null) ? $mergedConfig['stubs'] : [];

        $mergedConfig['include'] = array_values(array_unique([...$currentIncludes, ...$extensionIncludes]));
        $mergedConfig['stubs'] = array_values(array_unique([...$currentStubs, ...$extensionStubs]));

        self::syncFlags($mergedConfig);

        return self::$cachedConfig = $mergedConfig;
    }

    /**
     * Overrides the current configuration at runtime.
     *
     * @param array<string, mixed> $config
     */
    public static function set(array $config): void
    {
        $current = self::$cachedConfig ?? self::get();
        $mergedConfig = self::mergeConfig($current, $config);

        if (isset($config['extensions']) && \is_array($config['extensions'])) {
            /** @var array<int, class-string<ExtensionInterface>> $configuredExtensions */
            $configuredExtensions = $config['extensions'];
            $extensionIncludes = ExtensionManager::loadExtensionIncludes($configuredExtensions);
            $extensionStubs = ExtensionManager::loadExtensionStubs($configuredExtensions);

            /** @var array<int, string> $currentIncludes */
            $currentIncludes = \is_array($mergedConfig['include'] ?? null) ? $mergedConfig['include'] : [];
            /** @var array<int, string> $currentStubs */
            $currentStubs = \is_array($mergedConfig['stubs'] ?? null) ? $mergedConfig['stubs'] : [];

            $mergedConfig['include'] = array_values(array_unique([...$currentIncludes, ...$extensionIncludes]));
            $mergedConfig['stubs'] = array_values(array_unique([...$currentStubs, ...$extensionStubs]));
        }

        self::$cachedConfig = $mergedConfig;
        self::syncFlags($mergedConfig);

        DocblockParser::reset();
        ParamChecker::reset();
        ReturnChecker::reset();
        FileFilter::reset();
        PathMatcher::reset();
        StreamWrapper::reset();
        StubManager::reset();
        SpecialTypeResolver::reset();
        CallerBoundaryResolver::reset();
        CacheManager::reset();
        IgnoreManager::reset();
        RuntimeTypeChecker::reset();
        ViolationCollector::reset();
    }

    /**
     * Merges user configuration over base defaults:
     * - Associative dictionaries (inline_vars, magic_properties) are merged recursively.
     * - Sequential lists (include, exclude, extensions, stubs) are REPLACED wholesale when defined.
     * - Scalars / booleans / strings are overwritten.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function mergeConfig(array $base, array $overrides): array
    {
        $merged = $base;

        foreach ($overrides as $key => $value) {
            if ($key === 'inline_vars' && \is_array($value) && isset($base['inline_vars']) && \is_array($base['inline_vars'])) {
                /** @var array<string, bool> $baseInlineVars */
                $baseInlineVars = $base['inline_vars'];
                /** @var array<string, bool> $overrideInlineVars */
                $overrideInlineVars = $value;
                $merged['inline_vars'] = [...$baseInlineVars, ...$overrideInlineVars];
            } elseif ($key === 'magic_properties' && \is_array($value) && isset($base['magic_properties']) && \is_array($base['magic_properties'])) {
                /** @var array<string, bool> $baseMagicProps */
                $baseMagicProps = $base['magic_properties'];
                /** @var array<string, bool> $overrideMagicProps */
                $overrideMagicProps = $value;
                $merged['magic_properties'] = [...$baseMagicProps, ...$overrideMagicProps];
            } elseif (\in_array($key, ['include', 'exclude', 'extensions', 'stubs'], true) && \is_array($value)) {
                $merged[$key] = array_values($value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Resets the configuration cache. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$cachedConfig = null;
        self::$projectRoot = null;
        self::$enabled = true;
        self::$autoBoot = true;
        self::$onViolation = 'throw';
        self::$reportFile = null;
        self::$failOnReport = false;
        self::$redactValues = false;
        self::$params = true;
        self::$returns = true;
        self::$selfOut = true;
        self::$strictReturnGenericInvariance = true;
        self::$magicProperties = true;
        self::$magicPropertyWrites = true;
        self::$magicPropertyReads = false;
        self::$magicMethods = true;
        self::$respectIgnoreTags = true;
        self::$ignoreTraceDepth = 25;
        self::$respectNativeNullability = true;
        self::$vendorBoundaryOnly = true;
        self::$arrayValidation = 'full';
        self::$cacheCheckMtime = true;
        self::$paramsOut = true;
        self::$inlineProperties = true;
        self::$inlineGenerics = true;
        self::$inlineCallables = true;
        self::$inlineScalars = true;
        self::$inlineArrays = true;
        self::$inlineObjects = true;

        DocblockParser::reset();
        ParamChecker::reset();
        ReturnChecker::reset();
        TemplateManager::reset();
        HierarchyResolver::reset();
        FileFilter::reset();
        PathMatcher::reset();
        StreamWrapper::reset();
        StubManager::reset();
        SpecialTypeResolver::reset();
        CallerBoundaryResolver::reset();
        CacheManager::reset();
        IgnoreManager::reset();
        RuntimeTypeChecker::reset();
        ViolationCollector::reset();
    }

    /**
     * Synchronizes cached static boolean flags for fast O(1) checking.
     *
     * @param array<string, mixed> $config
     */
    private static function syncFlags(array $config): void
    {
        self::$enabled = (bool) ($config['enabled'] ?? true);
        self::$autoBoot = (bool) ($config['auto_boot'] ?? true);
        self::$onViolation = \is_string($config['on_violation'] ?? null) && trim($config['on_violation']) !== ''
            ? strtolower(trim($config['on_violation']))
            : 'throw';
        if (self::$onViolation === 'warning') {
            self::$onViolation = 'warn';
        }

        self::$reportFile = \is_string($config['report_file'] ?? null) && trim($config['report_file']) !== ''
            ? trim($config['report_file'])
            : null;

        self::$failOnReport = (bool) ($config['fail_on_report'] ?? false);
        self::$redactValues = (bool) ($config['redact_values'] ?? false);

        self::$params = (bool) ($config['params'] ?? true);
        self::$paramsOut = (bool) ($config['params_out'] ?? true);
        self::$selfOut = (bool) ($config['self_out'] ?? true);
        self::$returns = (bool) ($config['returns'] ?? true);
        self::$strictReturnGenericInvariance = (bool) ($config['strict_return_generic_invariance'] ?? true);

        if (\is_array($config['magic_properties'] ?? null)) {
            self::$magicPropertyWrites = (bool) ($config['magic_properties']['write'] ?? true);
            self::$magicPropertyReads = (bool) ($config['magic_properties']['read'] ?? false);
        } else {
            $bool = (bool) ($config['magic_properties'] ?? true);
            self::$magicPropertyWrites = $bool;
            self::$magicPropertyReads = false;
        }
        self::$magicProperties = self::$magicPropertyWrites || self::$magicPropertyReads;

        self::$magicMethods = (bool) ($config['magic_methods'] ?? true);
        self::$respectIgnoreTags = (bool) ($config['respect_ignore_tags'] ?? true);
        self::$ignoreTraceDepth = isset($config['ignore_trace_depth']) && is_numeric($config['ignore_trace_depth']) && (int) $config['ignore_trace_depth'] > 0
            ? (int) $config['ignore_trace_depth']
            : 25;
        self::$respectNativeNullability = (bool) ($config['respect_native_nullability'] ?? true);
        self::$vendorBoundaryOnly = (bool) ($config['vendor_boundary_only'] ?? true);
        self::$arrayValidation = \is_string($config['array_validation'] ?? null) ? $config['array_validation'] : 'full';
        self::$cacheCheckMtime = (bool) ($config['cache_check_mtime'] ?? true);
        $inline = \is_array($config['inline_vars'] ?? null) ? $config['inline_vars'] : [];
        self::$inlineProperties = (bool) ($inline['properties'] ?? true);
        self::$inlineGenerics = (bool) ($inline['generics'] ?? true);
        self::$inlineCallables = (bool) ($inline['callables'] ?? true);
        self::$inlineScalars = (bool) ($inline['scalars'] ?? true);
        self::$inlineArrays = (bool) ($inline['arrays'] ?? true);
        self::$inlineObjects = (bool) ($inline['objects'] ?? true);
    }
}
