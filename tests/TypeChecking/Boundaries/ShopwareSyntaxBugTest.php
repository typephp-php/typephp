<?php

declare(strict_types=1);

use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Shopware\Config\MetricConfigProvider;

beforeEach(function () {
    Config::reset();
});

afterEach(function () {
    Config::reset();
});

describe('Imported Type Aliases from Enums (@phpstan-import-type ... from Enum)', function () {
    test('successfully resolves type alias imported from Enum and allows valid shape array', function () {
        $validConfig = [
            'plugin.install.count' => [
                'type' => 'counter',
                'description' => 'Counts plugin installs',
            ],
            'plugin.response.time' => [
                'type' => 'histogram',
                'description' => 'Measures response latency',
            ],
        ];

        $provider = new MetricConfigProvider($validConfig);

        expect($provider)->toBeInstanceOf(MetricConfigProvider::class);
    });

    test('throws TypeError with expanded Enum union when shape array item violates imported type alias', function () {
        $invalidConfig = [
            'plugin.install.count' => [
                'type' => 'invalid_metric_type',
                'description' => 'Counts plugin installs',
            ],
        ];

        expect(fn () => new MetricConfigProvider($invalidConfig))
            ->toThrow(TypeError::class, "('histogram' | 'gauge' | 'counter' | 'updown_counter')")
        ;
    });
});
