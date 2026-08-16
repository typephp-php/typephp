<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\MultiTemplateBag;
use TypePHP\TypePHP;

describe('Multi-Template Classes (Map<K, V> and Dictionary<K, V>)', function () {
    describe('Pre-binding Multiple Templates via Inline @var', function () {
        test('enforces multiple pre-bound templates on key and value', function () {
            /** @var MultiTemplateBag<non-empty-string, positive-int> $bag */
            $bag = new MultiTemplateBag();

            $bag->set('score_alpha', 100);
            expect($bag->get('score_alpha'))->toBe(100);
            expect(fn () => $bag->set('', 100))
                ->toThrow(TypeError::class, 'must be of type non-empty-string')
            ;

            expect(fn () => $bag->set('score_beta', -50))
                ->toThrow(TypeError::class, 'must be of type positive-int')
            ;
        });

        test('inspects multiple pre-bound generic types via TypePHP public API', function () {
            /** @var MultiTemplateBag<string, Dog> $catalog */
            $catalog = new MultiTemplateBag();

            expect(TypePHP::getGenericType($catalog, 'K'))->toBe('string')
                ->and(TypePHP::getGenericType($catalog, 'V'))->toBe(Dog::class)
                ->and(TypePHP::getGenericTypes($catalog))->toBe([
                    'K' => 'string',
                    'V' => Dog::class,
                ])
            ;
        });
    });

    describe('Simultaneous First-Use Multi-Template Inference', function () {
        test('infers both K and V simultaneously on first method call and locks them in WeakMap', function () {
            $bag = new MultiTemplateBag();

            expect(TypePHP::getGenericTypes($bag))->toBeEmpty();

            $bag->set('max_retries', 5);

            expect(TypePHP::getGenericTypes($bag))->toBe([
                'K' => 'string',
                'V' => 'int',
            ]);

            $bag->set('timeout', 30);
            expect($bag->get('timeout'))->toBe(30);

            expect(fn () => $bag->set(12345, 30))
                ->toThrow(TypeError::class, 'template K = string')
            ;

            expect(fn () => $bag->set('timeout', 'thirty'))
                ->toThrow(TypeError::class, 'template V = int')
            ;
        });
    });

    describe('Cloning Multi-Template Generic Instances', function () {
        test('preserves all bound template parameters when multi-template instance is cloned', function () {
            /** @var MultiTemplateBag<string, positive-int> $original */
            $original = new MultiTemplateBag();
            $original->set('initial', 10);

            $cloned = clone $original;
            $cloned->set('new_key', 20);
            expect($cloned->get('new_key'))->toBe(20);

            expect(fn () => $cloned->set('bad_val', -99))
                ->toThrow(TypeError::class, 'must be of type positive-int')
            ;
        });
    });
});
