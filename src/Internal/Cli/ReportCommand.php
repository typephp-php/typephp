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

        // Consolidate any parallel shards if present
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

        /** @var array{version?: string, generated_at?: string, summary?: array{total_violations?: int, files_affected?: int}, violations?: list<array<string, mixed>>}|null $data */
        $data = json_decode($rawContent, true);

        if (! \is_array($data) || ! isset($data['violations']) || ! \is_array($data['violations']) || $data['violations'] === []) {
            fwrite($outputStream, '  ' . $c('✓', 'green') . " No violations recorded in report.\n\n");

            return 0;
        }

        $violations = $data['violations'];
        $totalCount = \count($violations);

        /** @var array<string, list<array<string, mixed>>> $grouped */
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

        $relativeReportPath = ViolationCollector::normalizeRelativePath($reportFile);

        fwrite($outputStream, '  ' . $c('•', 'cyan') . ' Total Violations: ' . $c((string) $totalCount, 'bold') . "\n");
        fwrite($outputStream, '  ' . $c('•', 'cyan') . ' Files Affected:   ' . $c((string) $filesAffected, 'bold') . "\n");
        fwrite($outputStream, '  ' . $c('•', 'cyan') . ' Report Source:    ' . $c($relativeReportPath, 'gray') . "\n\n");

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