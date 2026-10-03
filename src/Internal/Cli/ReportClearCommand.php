<?php

declare(strict_types=1);

namespace TypePHP\Internal\Cli;

use TypePHP\Internal\Reporting\ViolationCollector;
use TypePHP\Internal\Util\Config;

/**
 * @internal Deletes the generated TypePHP report file and cleans up temporary worker shards.
 */
final class ReportClearCommand implements CommandInterface
{
    public function execute(array $args, $outputStream = STDOUT, $errorStream = STDERR): int
    {
        $c = [CliFormatter::class, 'color'];

        $reportFile = Config::getReportFile() ?? (Config::getProjectRoot() . '/var/typephp-report.json');
        $reportDir = \dirname($reportFile);
        $shardDir = $reportDir . '/.typephp-shards';

        ViolationCollector::clear();
        $deletedCount = 0;

        if (file_exists($reportFile)) {
            @unlink($reportFile);
            $deletedCount++;
        }

        if (is_dir($shardDir)) {
            $shards = glob($shardDir . '/shard_*.json');
            if ($shards !== false) {
                foreach ($shards as $sFile) {
                    @unlink($sFile);
                    $deletedCount++;
                }
            }
            @rmdir($shardDir);
        }

        fwrite($outputStream, "\n  " . $c(' TYPEPHP ', 'badge') . ' ' . $c('Report Clear', 'bold') . "\n\n");

        if ($deletedCount > 0) {
            fwrite($outputStream, '  ' . $c('✓', 'green') . ' Cleared report file and temporary shards (' . $c((string) $deletedCount, 'bold') . " file(s) removed).\n\n");
        } else {
            fwrite($outputStream, '  ' . $c('•', 'cyan') . " No report files or shards found to clear.\n\n");
        }

        return 0;
    }
}
