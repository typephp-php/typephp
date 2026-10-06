<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\SplitInclude\Commands\FieldBuilder;
use TypePHP\Tests\Fixtures\SplitInclude\Entities\Project;
use TypePHP\Tests\Fixtures\SplitInclude\Fields\TextInputField;

describe('Split Include Template Property Assignment (Bug Reproduction)', function () {
    test('assigns object to unbound generic template property when declaring class is outside include paths', function () {
        Config::set([
            'include' => [
                'tests/Fixtures/SplitInclude/Commands/**',
            ],
            'exclude' => [
                'vendor/**',
            ],
        ]);

        $builder = new FieldBuilder();

        expect(fn () => $builder->probe(new TextInputField()))
            ->toThrow(TypeError::class, 'must be >= 0')
        ;

        $field = $builder->build(TextInputField::class, new Project());

        expect($field)->toBeInstanceOf(TextInputField::class)
            ->and($field->entity)->toBeInstanceOf(Project::class)
        ;
    });
});
