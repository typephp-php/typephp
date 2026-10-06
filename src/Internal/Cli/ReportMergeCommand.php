<?php

declare(strict_types=1);

namespace TypePHP\Internal\Cli;

use TypePHP\Internal\Reporting\ViolationRecord;

/**
 * @internal Merges multiple JSON report files into a unified report with aggregated occurrence counts.
 */
final class ReportMergeCommand implements CommandInterface
{
    public function execute(array $args, $outputStream = STDOUT, $errorStream = STDERR): int
    {
        $c = [CliFormatter::class, 'color'];

        $inputFiles = [];
        $outputFile = null;

        for ($i = 0; $i < \count($args); $i++) {
            $arg = $args[$i];

            if ($arg === 'report:merge') {
                continue;
            }

            if (str_starts_with($arg, '--output=')) {
                $outputFile = substr($arg, 9);

                continue;
            }

            if ($arg === '-o' && isset($args[$i + 1])) {
                $outputFile = $args[$i + 1];
                $i++;

                continue;
            }

            if (! str_starts_with($arg, '-')) {
                $inputFiles[] = $arg;
            }
        }

        if ($inputFiles === []) {
            fwrite($errorStream, "\n  " . $c(' TYPEPHP ', 'badge_red') . ' ' . $c('Error', 'bold') . "\n\n");
            fwrite($errorStream, '  ' . $c('✗', 'red') . " No input report files specified.\n\n");
            fwrite($errorStream, '  ' . $c('Usage:', 'yellow') . "\n");
            fwrite($errorStream, "    vendor/bin/typephp report:merge <file1.json> <file2.json> [--output=<merged.json>]\n\n");

            return 1;
        }

        /** @var array<string, ViolationRecord> $mergedViolations */
        $mergedViolations = [];
        $validFileCount = 0;

        foreach ($inputFiles as $file) {
            if (! file_exists($file)) {
                fwrite($errorStream, '  ' . $c('!', 'yellow') . ' Warning: Report file ' . $c('"' . $file . '"', 'bold') . " not found, skipping.\n");

                continue;
            }

            $rawContent = @file_get_contents($file);
            if ($rawContent === false || trim($rawContent) === '') {
                continue;
            }

            /** @var array{violations?: list<array<string, mixed>>}|null $data */
            $data = json_decode($rawContent, true);
            if (! \is_array($data) || ! isset($data['violations']) || ! \is_array($data['violations'])) {
                continue;
            }

            $validFileCount++;

            foreach ($data['violations'] as $v) {
                if (\is_array($v)) {
                    $record = ViolationRecord::fromArray($v);
                    if ($record !== null) {
                        $hash = $record->getHash();
                        if (isset($mergedViolations[$hash])) {
                            $mergedViolations[$hash] = $mergedViolations[$hash]->withIncrementedCount($record->count);
                        } else {
                            $mergedViolations[$hash] = $record;
                        }
                    }
                }
            }
        }

        $violationsList = array_values($mergedViolations);
        $filesAffected = [];
        foreach ($violationsList as $v) {
            $filesAffected[$v->file] = true;
        }

        $document = [
            'version' => '1.0',
            'generated_at' => gmdate('c'),
            'summary' => [
                'total_violations' => \count($violationsList),
                'files_affected' => \count($filesAffected),
            ],
            'violations' => $violationsList,
        ];

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($outputFile !== null && $json !== false) {
            $outputDir = \dirname($outputFile);
            if (! is_dir($outputDir)) {
                @mkdir($outputDir, 0777, true);
            }

            file_put_contents($outputFile, $json);

            fwrite($outputStream, "\n  " . $c(' TYPEPHP ', 'badge') . ' ' . $c('Report Merge', 'bold') . "\n\n");
            fwrite($outputStream, '  ' . $c('✓', 'green') . ' Merged ' . $c((string) $validFileCount, 'bold') . ' report file(s) into ' . $c('"' . $outputFile . '"', 'bold') . ' (' . $c((string) \count($violationsList), 'bold') . " unique violation(s)).\n\n");

            return 0;
        }

        if ($json !== false) {
            fwrite($outputStream, $json . "\n");
        }

        return 0;
    }
}
