<?php

declare(strict_types=1);

namespace TypePHP\Internal\Diagnostic;

use TypePHP\Internal\Cli\CliFormatter;

/**
 * @internal High-precision performance profiler and call tracing engine.
 */
final class Profiler
{
    public static bool $enabled = false;

    // Call Counts
    public static int $scopeCount = 0;

    public static int $paramCheckCount = 0;

    public static int $paramCheckSkips = 0;

    public static int $returnCount = 0;

    public static int $variableCount = 0;

    public static int $propertyCount = 0;

    public static int $docblockParseCount = 0;

    public static int $docblockParseHits = 0;

    public static int $streamTransformCount = 0;

    public static int $streamCachedCount = 0;

    // Timing (nanoseconds via hrtime)
    public static int $scopeTimeNs = 0;

    public static int $paramCheckTimeNs = 0;

    public static int $returnTimeNs = 0;

    public static int $variableTimeNs = 0;

    public static int $propertyTimeNs = 0;

    public static int $docblockParseTimeNs = 0;

    public static int $transformTimeNs = 0;

    /**
     * Top method hotspots: functionName => callCount
     *
     * @var array<string, int>
     */
    public static array $hotspots = [];

    private static bool $shutdownRegistered = false;

    private static bool $reported = false;

    public static function init(): void
    {
        $env = getenv('TYPEPHP_PROFILE');
        $hasEnv = $env !== false && $env !== '0' && strtolower((string) $env) !== 'false';

        $hasArg = false;
        if (isset($_SERVER['argv']) && \is_array($_SERVER['argv'])) {
            foreach ($_SERVER['argv'] as $arg) {
                if ($arg === '--profile') {
                    $hasArg = true;

                    break;
                }
            }
        }

        self::$enabled = $hasEnv || $hasArg;

        if (self::$enabled && ! self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'report']);
            self::$shutdownRegistered = true;
        }
    }

    public static function recordHotspot(string $function): void
    {
        self::$hotspots[$function] = (self::$hotspots[$function] ?? 0) + 1;
    }

    public static function reset(): void
    {
        self::$scopeCount = 0;
        self::$paramCheckCount = 0;
        self::$paramCheckSkips = 0;
        self::$returnCount = 0;
        self::$variableCount = 0;
        self::$propertyCount = 0;
        self::$docblockParseCount = 0;
        self::$docblockParseHits = 0;
        self::$streamTransformCount = 0;
        self::$streamCachedCount = 0;

        self::$scopeTimeNs = 0;
        self::$paramCheckTimeNs = 0;
        self::$returnTimeNs = 0;
        self::$variableTimeNs = 0;
        self::$propertyTimeNs = 0;
        self::$docblockParseTimeNs = 0;
        self::$transformTimeNs = 0;

        self::$hotspots = [];
        self::$reported = false;
    }

    public static function report(): void
    {
        if (! self::$enabled || self::$reported) {
            return;
        }

        self::$reported = true;

        $c = [CliFormatter::class, 'color'];

        $nsToMs = static fn (int $ns): float => round($ns / 1_000_000, 2);
        $avgUs = static fn (int $ns, int $count): float => $count > 0 ? round(($ns / $count) / 1_000, 2) : 0.0;

        $pid = getmypid();
        $output = "\n  " . $c(" TYPEPHP PROFILER [PID {$pid}] ", 'badge') . ' ' . $c('Runtime Execution Breakdown', 'bold') . "\n\n";

        $rows = [
            [
                'Component',
                'Calls',
                'Total Time (ms)',
                'Avg Time (µs)',
            ],
            [
                'Scope Setup (setupScope)',
                number_format(self::$scopeCount),
                $nsToMs(self::$scopeTimeNs) . ' ms',
                $avgUs(self::$scopeTimeNs, self::$scopeCount) . ' µs',
            ],
            [
                '  └─ Param Check (checkParams)',
                number_format(self::$paramCheckCount) . ' (' . number_format(self::$paramCheckSkips) . ' no-ops)',
                $nsToMs(self::$paramCheckTimeNs) . ' ms',
                $avgUs(self::$paramCheckTimeNs, self::$paramCheckCount) . ' µs',
            ],
            [
                'Return Check (checkReturn)',
                number_format(self::$returnCount),
                $nsToMs(self::$returnTimeNs) . ' ms',
                $avgUs(self::$returnTimeNs, self::$returnCount) . ' µs',
            ],
            [
                'Inline Variable Check (@var)',
                number_format(self::$variableCount),
                $nsToMs(self::$variableTimeNs) . ' ms',
                $avgUs(self::$variableTimeNs, self::$variableCount) . ' µs',
            ],
            [
                'Property Check (checkProperty)',
                number_format(self::$propertyCount),
                $nsToMs(self::$propertyTimeNs) . ' ms',
                $avgUs(self::$propertyTimeNs, self::$propertyCount) . ' µs',
            ],
            [
                'DocBlock Parsing & Reflection',
                number_format(self::$docblockParseCount) . ' (' . number_format(self::$docblockParseHits) . ' hits)',
                $nsToMs(self::$docblockParseTimeNs) . ' ms',
                $avgUs(self::$docblockParseTimeNs, self::$docblockParseCount) . ' µs',
            ],
            [
                'AST Stream Transformations',
                number_format(self::$streamTransformCount) . ' (' . number_format(self::$streamCachedCount) . ' cached)',
                $nsToMs(self::$transformTimeNs) . ' ms',
                $avgUs(self::$transformTimeNs, self::$streamTransformCount) . ' µs',
            ],
        ];

        $colWidths = [35, 22, 18, 15];
        foreach ($rows as $index => $row) {
            $line = '  ';
            foreach ($row as $colIdx => $col) {
                $style = $index === 0 ? 'bold' : '';
                $line .= str_pad($c((string) $col, $style), $colWidths[$colIdx] + ($index === 0 ? 9 : 0));
            }
            $output .= $line . "\n";
            if ($index === 0) {
                $output .= '  ' . str_repeat('─', 90) . "\n";
            }
        }

        if (! empty(self::$hotspots)) {
            arsort(self::$hotspots);
            $topHotspots = \array_slice(self::$hotspots, 0, 10, true);

            $output .= "\n  " . $c('TOP 10 METHOD HOTSPOTS (MOST FREQUENTLY CALLED)', 'yellow') . "\n";
            $output .= '  ' . str_repeat('─', 90) . "\n";
            $rank = 1;
            foreach ($topHotspots as $func => $count) {
                $output .= \sprintf("  %2d. %-67s %12s calls\n", $rank++, $func, number_format($count));
            }
        }

        $output .= "\n";

        // Print to STDERR for foreground executions
        fwrite(STDERR, $output);

        // Also append to /tmp/typephp_profile.log for parallel workers
        @file_put_contents(
            sys_get_temp_dir() . '/typephp_profile.log',
            preg_replace('/\x1b\[[0-9;]*m/', '', $output), // strip ANSI for log file
            FILE_APPEND
        );
    }
}
