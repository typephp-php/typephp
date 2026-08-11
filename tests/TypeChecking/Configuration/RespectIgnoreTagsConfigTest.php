<?php

declare(strict_types=1);

use TypePHP\Internal\Config;
use TypePHP\Tests\Fixtures\IgnoreTags\ForceCheckedMethod;

describe('Respect Ignore Tags Configuration (respect_ignore_tags)', function () {
    afterEach(function () {
        Config::reset();
    });

    test('forces type-checking on @typephp-ignore methods when respect_ignore_tags is set to false', function () {
        Config::set(['respect_ignore_tags' => false]);

        $fixture = new ForceCheckedMethod();

        expect(fn () => $fixture->ignoredMethod(-100))
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });
});
