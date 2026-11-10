<?php

declare(strict_types=1);

use TypePHP\Internal\Diagnostic\DebugLog;
use TypePHP\Internal\Io\StreamWrapper;

describe('DebugLog', function () {
    /**
     * Runs the callback with error_log() redirected to a file and returns what was logged.
     * Set inside the test body: PHPUnit points error_log at its own sink after setUp().
     */
    $captureErrorLog = static function (callable $callback): string {
        $logFile = sys_get_temp_dir() . '/typephp_debug_' . uniqid() . '.log';
        $previousLog = ini_set('error_log', $logFile);

        try {
            $callback();

            return file_exists($logFile) ? (string) file_get_contents($logFile) : '';
        } finally {
            ini_set('error_log', (string) $previousLog);
            if (file_exists($logFile)) {
                unlink($logFile);
            }
        }
    };

    afterEach(function () {
        putenv('TYPEPHP_DEBUG');
    });

    test('stays silent unless TYPEPHP_DEBUG is set', function () use ($captureErrorLog) {
        expect($captureErrorLog(static fn () => DebugLog::swallowed(new RuntimeException('boom'))))->toBe('');
    });

    test('logs the swallowed exception with throw and catch sites', function () use ($captureErrorLog) {
        putenv('TYPEPHP_DEBUG=1');

        expect($captureErrorLog(static fn () => DebugLog::swallowed(new RuntimeException('boom'))))
            ->toContain('[TypePHP Debug] Swallowed RuntimeException: boom')
            ->toContain('thrown at ' . __FILE__)
            ->toContain('swallowed at ' . __FILE__)
        ;
    });

    test('reports a source file that is left uninstrumented because it does not parse', function () use ($captureErrorLog) {
        putenv('TYPEPHP_DEBUG=1');
        $source = '<?php function broken( {';
        $transformed = null;

        $log = $captureErrorLog(static function () use ($source, &$transformed): void {
            $transformed = StreamWrapper::transformSource($source);
        });

        expect($transformed)->toBe($source)
            ->and($log)->toContain('Swallowed PhpParser\\Error')
            ->toContain('StreamWrapper.php')
        ;
    });
});
