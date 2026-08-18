<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Shopware\Exception\TableHelperExceptionFixture;

describe('Constructor Parameter Inheritance Mismatch (Shopware TableHelperException)', function () {
    test('child constructor with different parameter names does not inherit parent constructor docblocks by positional index', function () {
        $previous = new \Exception('Underlying DB error');

        $exception = new TableHelperExceptionFixture('Table missing', $previous);

        expect($exception)->toBeInstanceOf(TableHelperExceptionFixture::class)
            ->and($exception->getPrevious())->toBe($previous);
    });
});