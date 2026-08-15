<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Anonymous\AnonymousContractInterface;
use TypePHP\Tests\Fixtures\ByRef\ByRefServiceInterface;
use TypePHP\Tests\Fixtures\Services\BaseService;

describe('Anonymous Classes with Type Contracts (new class { ... })', function () {
    describe('Anonymous Class Direct Method Contracts', function () {
        test('validates parameter and return contracts on anonymous class methods', function () {
            $service = new class () {
                /**
                 * @param positive-int $id
                 * @param non-empty-string $sku
                 *
                 * @return non-empty-string
                 */
                public function generateCode(int $id, string $sku): string
                {
                    return "{$sku}_{$id}";
                }
            };

            expect($service->generateCode(42, 'ITEM'))->toBe('ITEM_42');

            expect(fn () => $service->generateCode(-5, 'ITEM'))
                ->toThrow(TypeError::class, 'positive-int');

            expect(fn () => $service->generateCode(42, ''))
                ->toThrow(TypeError::class, 'non-empty-string');
        });

        test('validates return contracts when anonymous class method returns invalid value', function () {
            $service = new class () {
                /**
                 * @param positive-int $id
                 *
                 * @return non-empty-string
                 */
                public function badReturn(int $id): string
                {
                    return ''; 
                }
            };

            expect(fn () => $service->badReturn(10))
                ->toThrow(TypeError::class, 'Return value');
        });
    });

    describe('Anonymous Class Interface Contract Inheritance (LSP)', function () {
        test('inherits parameter and return shape contracts from interface without local docblocks', function () {
            $service = new class () implements AnonymousContractInterface {
                public function formatUser(int $id, string $name): array
                {
                    return ['id' => $id, 'name' => $name];
                }
            };

            expect($service->formatUser(100, 'Alice'))->toBe(['id' => 100, 'name' => 'Alice']);

            expect(fn () => $service->formatUser(-1, 'Alice'))
                ->toThrow(TypeError::class, 'positive-int');

            expect(fn () => $service->formatUser(100, ''))
                ->toThrow(TypeError::class, 'non-empty-string');
        });

        test('inherits by-reference parameter contracts on anonymous class implementing interface', function () {
            $service = new class () implements ByRefServiceInterface {
                public function updateStatus(string &$status): void
                {
                    $status = strtoupper($status);
                }

                public function incrementCode(int &$code): void
                {
                    $code += 50;
                }
            };

            $status = 'pending';
            $service->updateStatus($status);
            expect($status)->toBe('PENDING');

            $badStatus = '';
            expect(fn () => $service->updateStatus($badStatus))
                ->toThrow(TypeError::class, 'non-empty-string');
        });
    });

    describe('Anonymous Class Extending Parent Classes', function () {
        test('inherits parent class method contracts in anonymous child class', function () {
            $service = new class () extends BaseService {
                public function find(int $id): array
                {
                    return parent::find($id);
                }
            };

            expect($service->find(10))->toBe(['id' => 10, 'name' => 'Alice']);

            expect(fn () => $service->find(-5))
                ->toThrow(TypeError::class, 'positive-int');
        });
    });

    describe('Anonymous Class Properties with @var Annotations', function () {
        test('validates property assignments on anonymous class', function () {
            $container = new class () {
                /**
                 * @var positive-int
                 */
                public int $count = 10;

                /**
                 * @var non-empty-string
                 */
                public string $title = 'Initial';
            };

            $container->count = 50;
            expect($container->count)->toBe(50);

            expect(fn () => $container->count = -10)
                ->toThrow(TypeError::class, 'positive-int');

            expect(fn () => $container->title = '')
                ->toThrow(TypeError::class, 'non-empty-string');
        });
    });
});