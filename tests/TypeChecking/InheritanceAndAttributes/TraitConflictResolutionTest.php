<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Traits\CollisionService;

describe('Trait Method Conflict Resolution (insteadof and as)', function () {
    test('enforces docblock contracts of the trait method chosen with insteadof', function () {
        $service = new CollisionService();

        expect($service->log(10, 'app_boot'))->toBe('first: 10 - app_boot');

        expect(fn () => $service->log(-5, 'app_boot'))
            ->toThrow(TypeError::class, 'positive-int')
        ;

        expect(fn () => $service->log(10, ''))
            ->toThrow(TypeError::class, 'non-empty-string')
        ;
    });

    test('enforces docblock contracts of the aliased trait method (as backupLog)', function () {
        $service = new CollisionService();

        expect($service->backupLog(-20, 'backup_msg'))->toBe('second: -20 - backup_msg');

        expect(fn () => $service->backupLog(20, 'backup_msg'))
            ->toThrow(TypeError::class, 'negative-int')
        ;
    });
});
