<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Doctrine\Column;

describe('Constructor Parameter vs Property Type Conflict (Doctrine DBAL AbstractNamedObject)', function () {
    test('does not override native scalar constructor parameter with conflicting object property @var docblock', function () {
        $column = new Column('"id"');

        expect($column)->toBeInstanceOf(Column::class);
    });
});
