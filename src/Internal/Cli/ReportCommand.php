<?php

declare(strict_types=1);

namespace TypePHP\Internal\Cli;

use TypePHP\Internal\Reporting\ViolationCollector;
use TypePHP\Internal\Util\Config;

/**
 * @internal Displays the generated TypePHP audit report summary in the terminal.
 */
final class ReportCommand implements CommandInterface
{
    public function execute(array $args, $outputStream = STDOUT, $errorStream = STDERR): int
    {
        $c = [CliFormatter::class, 'color'];

        $reportFile = Config::getReportFile() ?? (Config::getProjectRoot() . '/var/typephp-report.json');
        $reportDir = \dirname($reportFile);
        $shardDir = $reportDir . '/.typephp-shards';

        if (is_dir($shardDir)) {
            ViolationCollector::exportReport($reportFile);
        }

        fwrite($outputStream, "\n  " . $c(' TYPEPHP ', 'badge') . ' ' . $c('Violation Audit Report', 'bold') . "\n\n");

        if (! file_exists($reportFile)) {
            fwrite($outputStream, '  ' . $c('✓', 'green') . " No report file found or no violations recorded.\n\n");

            return 0;
        }

        $rawContent = @file_get_contents($reportFile);
        if ($rawContent === false || trim($rawContent) === '') {
            fwrite($outputStream, '  ' . $c('✓', 'green') . " Report file is empty (no violations recorded).\n\n");

            return 0;
        }

        /** @var array{
         *     version?: string,
         *     generated_at?: string,
         *     context?: array{project_root?: string, config_file?: string, files_instrumented?: int},
         *     summary?: array{total_violations?: int, files_affected?: int, files_instrumented?: int, warning?: string},
         *     violations?: list<array<string, mixed>>
         * }|null $data */
        $data = json_decode($rawContent, true);

        if (! \is_array($data)) {
            fwrite($outputStream, '  ' . $c('✗', 'red') . " Invalid or corrupted report JSON file.\n\n");

            return 1;
        }

        $violations = (isset($data['violations']) && \is_array($data['violations'])) ? $data['violations'] : [];
        $totalCount = \count($violations);
        $filesInstrumented = $data['summary']['files_instrumented'] ?? ($data['context']['files_instrumented'] ?? null);
        $configSource = $data['context']['config_file'] ?? null;
        $projectRoot = $data['context']['project_root'] ?? null;
        $relativeReportPath = ViolationCollector::normalizeRelativePath($reportFile);

        if ($totalCount === 0) {
            if ($filesInstrumented === 0) {
                fwrite($outputStream, '  ' . $c('•', 'cyan') . ' Total Violations: ' . $c('0', 'bold') . "\n");
                fwrite($outputStream, '  ' . $c('•', 'red') . ' Files Instrumented: ' . $c('0 (⚠️  No files matched configuration!)', 'bold') . "\n");
                if ($configSource !== null) {
                    fwrite($outputStream, '  ' . $c('•', 'cyan') . ' Config Source: ' . $c($configSource, 'gray') . "\n");
                }
                if ($projectRoot !== null) {
                    fwrite($outputStream, '  ' . $c('•', 'cyan') . ' Project Root: ' . $c($projectRoot, 'gray') . "\n");
                }
                fwrite($outputStream, "\n  " . $c('✗', 'red') . " 0 violations over 0 instrumented files. Check your 'include'/'exclude' paths in typephp.php.\n\n");

                return 1;
            }

            if ($filesInstrumented !== null && $filesInstrumented > 0) {
                fwrite($outputStream, '  ' . $c('•', 'cyan') . ' Total Violations: ' . $c('0', 'bold') . "\n");
                fwrite($outputStream, '  ' . $c('•', 'green') . ' Files Instrumented: ' . $c((string) $filesInstrumented, 'bold') . "\n");
                if ($configSource !== null) {
                    fwrite($outputStream, '  ' . $c('•', 'cyan') . ' Config Source: ' . $c($configSource, 'gray') . "\n");
                }
                fwrite($outputStream, "\n  " . $c('✓', 'green') . " Clean run: No type violations found across {$filesInstrumented} instrumented file(s).\n\n");

                return 0;
            }

            fwrite($outputStream, '  ' . $c('✓', 'green') . " No violations recorded in report.\n\n");

            return 0;
        }

        /**
         * @var array<string, list<array<string, mixed>>>
         */
        $grouped = [];
        $filesAffectedMap = [];

        foreach ($violations as $v) {
            $file = isset($v['file']) && \is_string($v['file']) ? $v['file'] : 'unknown';
            $grouped[$file][] = $v;
            $filesAffectedMap[$file] = true;
        }

        $filesAffected = isset($data['summary']['files_affected']) && \is_int($data['summary']['files_affected'])
            ? $data['summary']['files_affected']
            : \count($filesAffectedMap);

        fwrite($outputStream, '  ' . $c('•', 'cyan') . ' Total Violations: ' . $c((string) $totalCount, 'bold') . "\n");
        fwrite($outputStream, '  ' . $c('•', 'cyan') . ' Files Affected: ' . $c((string) $filesAffected, 'bold') . "\n");
        if ($filesInstrumented !== null) {
            fwrite($outputStream, '  ' . $c('•', 'cyan') . ' Files Instrumented: ' . $c((string) $filesInstrumented, 'bold') . "\n");
        }
        if ($configSource !== null) {
            fwrite($outputStream, '  ' . $c('•', 'cyan') . ' Config Source: ' . $c($configSource, 'gray') . "\n");
        }
        fwrite($outputStream, '  ' . $c('•', 'cyan') . ' Report File: ' . $c($relativeReportPath, 'gray') . "\n\n");

        foreach ($grouped as $file => $items) {
            fwrite($outputStream, '  ' . $c($file, 'bold') . "\n");

            foreach ($items as $item) {
                $line = isset($item['line']) && \is_int($item['line']) ? $item['line'] : 1;
                $kind = isset($item['kind']) && \is_string($item['kind']) ? $item['kind'] : 'type';
                $target = isset($item['target']) && \is_string($item['target']) ? $item['target'] : '';
                $expected = isset($item['expected']) && \is_string($item['expected']) ? $item['expected'] : '';
                $given = isset($item['given']) && \is_string($item['given']) ? $item['given'] : '';
                $count = isset($item['count']) && \is_int($item['count']) ? $item['count'] : 1;
                $caller = isset($item['caller']) && \is_string($item['caller']) ? $item['caller'] : null;
                $declaredIn = isset($item['declared_in']) && \is_string($item['declared_in']) ? $item['declared_in'] : null;

                $lineFormatted = str_pad("Line {$line}", 10);
                $kindFormatted = str_pad($kind, 11);
                $targetFormatted = str_pad($target, 12);
                $countFormatted = $count > 1 ? $c(" (x{$count})", 'yellow') : '';

                fwrite($outputStream, '    ' . $c($lineFormatted, 'gray') . ' ' . $c($kindFormatted, 'yellow') . ' ' . $c($targetFormatted, 'cyan') . ' expected ' . $c($expected, 'green') . ', ' . $c($given, 'red') . $countFormatted . "\n");

                if ($caller !== null) {
                    fwrite($outputStream, '      ' . $c('↳ caller:', 'gray') . ' ' . $c($caller, 'gray') . "\n");
                }

                if ($declaredIn !== null) {
                    fwrite($outputStream, '      ' . $c('↳ declared in:', 'gray') . ' ' . $c($declaredIn, 'gray') . "\n");
                }
            }

            fwrite($outputStream, "\n");
        }

        return 1;
    }
}
