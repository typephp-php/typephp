<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;

class DeepIgnoreTargetService
{
    /**
     * @param positive-int $id
     */
    public function executeLeaf(int $id): int
    {
        return $id;
    }
}

class DeepPipelineRunner
{
    public static function recurse(int $currentDepth, int $targetDepth, callable $leaf): mixed
    {
        if ($currentDepth >= $targetDepth) {
            return $leaf();
        }

        return self::recurse($currentDepth + 1, $targetDepth, $leaf);
    }
}

class DeepIgnoringCaller
{
    /**
     * @typephp-ignore
     */
    public function runDeep(int $framesDeep): int
    {
        $target = new DeepIgnoreTargetService();

        return DeepPipelineRunner::recurse(1, $framesDeep, function () use ($target) {
            return $target->executeLeaf(-1); 
        });
    }
}

class DeepNormalCaller
{
    public function runDeep(int $framesDeep): int
    {
        $target = new DeepIgnoreTargetService();

        return DeepPipelineRunner::recurse(1, $framesDeep, function () use ($target) {
            return $target->executeLeaf(-1); 
        });
    }
}

describe('Deep Stack Trace @typephp-ignore Resolution (11+ Frames Deep)', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    test('honors @typephp-ignore when caller is 12 frames above the failing check', function () {
        $caller = new DeepIgnoringCaller();

        $result = $caller->runDeep(12);

        expect($result)->toBe(-1);
    });

    test('still throws TypeError when caller 12 frames deep does not have ignore tag', function () {
        $caller = new DeepNormalCaller();

        expect(fn () => $caller->runDeep(12))
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });

    test('allows custom ignore_trace_depth configuration', function () {
        Config::set(['ignore_trace_depth' => 30]);

        $caller = new DeepIgnoringCaller();

        $result = $caller->runDeep(20);
        expect($result)->toBe(-1);
    });

    test('shallow configured depth throws when ignore tag is beyond window', function () {
        Config::set(['ignore_trace_depth' => 5]);

        $caller = new DeepIgnoringCaller();

        expect(fn () => $caller->runDeep(10))
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });
});