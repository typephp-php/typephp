<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Callables\CurriedPipelineService;

/**
 * Higher-order standalone function accepting a transformer callback and applying it
 *
 * @param callable(callable(positive-int): non-empty-string, positive-int): non-empty-string $pipeline
 * @param callable(positive-int): non-empty-string $transformer
 * @param positive-int $value
 */
function testHigherOrderPipeline(callable $pipeline, callable $transformer, int $value): string
{
    return $pipeline($transformer, $value);
}

describe('Higher-Order & Curried Callables', function () {
    describe('Curried Callable Return Contracts (callable: callable)', function () {
        test('executes valid curried callable factory through all invocation stages', function () {
            $service = new CurriedPipelineService();
            $factory = $service->createValidatorFactory();

            $minFiveValidator = $factory(5);
            expect($minFiveValidator('HelloWorld'))->toBeTrue();
            expect($minFiveValidator('Hi'))->toBeFalse();
        });

        test('throws TypeError when outer factory receives invalid argument', function () {
            $service = new CurriedPipelineService();
            $factory = $service->createValidatorFactory();

            expect(fn () => $factory(-5))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });

        test('throws TypeError when inner curried callback receives invalid argument', function () {
            $service = new CurriedPipelineService();
            $factory = $service->createValidatorFactory();
            $validator = $factory(3);

            expect(fn () => $validator(''))
                ->toThrow(TypeError::class, 'non-empty-string')
            ;
        });

        test('throws TypeError when inner curried callback returns invalid return type', function () {
            $service = new CurriedPipelineService();
            $badFactory = $service->createBadReturnFactory();
            $badValidator = $badFactory(3);

            expect(fn () => $badValidator('ValidString'))
                ->toThrow(TypeError::class, 'must be of type bool')
            ;
        });
    });

    describe('Higher-Order Functions Accepting Callables as Arguments', function () {
        test('executes higher-order pipeline function cleanly with valid callbacks', function () {
            $pipeline = fn (callable $trans, int $val): string => $trans($val);
            $transformer = fn (int $id): string => "user_id_{$id}";

            $result = testHigherOrderPipeline($pipeline, $transformer, 42);
            expect($result)->toBe('user_id_42');
        });

        test('throws TypeError when transformer inside higher-order pipeline violates return type', function () {
            $pipeline = fn (callable $trans, int $val): string => $trans($val);
            $badTransformer = fn (int $id): string => '';

            expect(fn () => testHigherOrderPipeline($pipeline, $badTransformer, 42))
                ->toThrow(TypeError::class, 'non-empty-string')
            ;
        });
    });
});
