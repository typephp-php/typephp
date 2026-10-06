<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\SplitInclude\Commands;

use TypePHP\Tests\Fixtures\SplitInclude\Fields\InputField;

final class FieldBuilder
{
    public function build(string $className, object $entity): InputField
    {
        $field = new $className();
        $field->entity = $entity;

        return $field;
    }

    public function probe(InputField $field): void
    {
        $field->count = -1;
    }
}
