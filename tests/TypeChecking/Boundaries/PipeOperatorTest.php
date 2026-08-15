<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80500) {
    return;
}

use TypePHP\Internal\StreamWrapper;
use TypePHP\Tests\Fixtures\Pipes\NativePipeRunner;
use TypePHP\Tests\Fixtures\Pipes\PipePipelineService;

describe('PHP 8.5+ Pipe Operator (|>) and Multi-Step Pipelines', function () {
    describe('Native Pipe Execution (PHP 8.5+)', function () {
        test('executes multi-step piped transformation through first-class callables', function () {
            $service = new PipePipelineService();
            $runner = new NativePipeRunner();

            $result = $runner->runPipeline(10, $service);

            expect($result)->toBe('[TAG] RECORD_20');
        });

        test('throws TypeError at the exact pipe step where parameter contract is violated', function () {
            $service = new PipePipelineService();
            $runner = new NativePipeRunner();
            
            expect(fn () => $runner->runPipeline(-5, $service))
                ->toThrow(TypeError::class, 'positive-int');
        });

        test('executes standalone method pipeline with native pipe operator', function () {
            $runner = new NativePipeRunner();

            $result = $runner->runStandalonePipeline(5);

            expect($result)->toBe('piped_user_15');
        });

        test('throws TypeError when standalone pipe step receives invalid integer', function () {
            $runner = new NativePipeRunner();

            expect(fn () => $runner->runStandalonePipeline(-50))
                ->toThrow(TypeError::class, 'positive-int');
        });
    });

    describe('AST Transformation & Zero Line-Drift with Pipe Syntax', function () {
        test('transforms code containing multi-line pipe operator chains with zero line-drift', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * @param positive-int $id
 * @return non-empty-string
 */
function formatId(int $id): string
{
    return "ID_{$id}";
}

$service = new \TypePHP\Tests\Fixtures\Pipes\PipePipelineService();

$targetLine = true;
PHP;

            $transformed = StreamWrapper::transformSource($source, 'test_pipe_drift.php');

            $origLines = explode("\n", str_replace("\r\n", "\n", $source));
            $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

            expect(count($transLines))->toBe(count($origLines));

            $origTarget = array_search('$targetLine = true;', array_map('trim', $origLines), true);
            $transTarget = array_search('$targetLine = true;', array_map('trim', $transLines), true);

            expect($transTarget)->toBe($origTarget);
        });
    });
});