<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

abstract class ReproEloquentModelBase
{
}

/**
 * Simulates Laravel's Illuminate\Database\Eloquent\Builder
 *
 * @template TModel of ReproEloquentModelBase
 */
class ReproEloquentBuilder
{
    /**
     * @param TModel|null $model
     */
    public function __construct(public ?ReproEloquentModelBase $model = null)
    {
    }

    public function getModel(): ?ReproEloquentModelBase
    {
        return $this->model;
    }
}

class ReproPatientModel extends ReproEloquentModelBase
{
    /**
     * Exact Laravel Query Scope pattern from the user's codebase:
     *
     * @param ReproEloquentBuilder<static> $query
     * @param int $therapistId
     *
     * @return ReproEloquentBuilder<static>
     */
    public function scopeAssignedToTherapist(ReproEloquentBuilder $query, int $therapistId): ReproEloquentBuilder
    {
        return $query;
    }

    /**
     * Variant using $this instead of static:
     *
     * @param ReproEloquentBuilder<$this> $query
     */
    public function scopeWithThisType(ReproEloquentBuilder $query): ReproEloquentBuilder
    {
        return $query;
    }
}

class ReproSpecialPatientModel extends ReproPatientModel
{
}

class ReproUnrelatedNonModel
{
}

describe('Eloquent Query Scope Builder<static> Parameter Contracts (Reproduction)', function () {
    test('accepts EloquentBuilder matching Model instance when scope is typed as Builder<static>', function () {
        $patient = new ReproPatientModel();
        $builder = new ReproEloquentBuilder($patient);

        // This reproduces the exact call made by Laravel's Model::callNamedScope():
        $result = $patient->scopeAssignedToTherapist($builder, 1);

        expect($result)->toBe($builder);
    });

    test('accepts EloquentBuilder matching Model instance when scope is typed as Builder<$this>', function () {
        $patient = new ReproPatientModel();
        $builder = new ReproEloquentBuilder($patient);

        $result = $patient->scopeWithThisType($builder);

        expect($result)->toBe($builder);
    });

    test('accepts subclass of Model calling inherited scope with Builder<static>', function () {
        $specialPatient = new ReproSpecialPatientModel();
        $builder = new ReproEloquentBuilder($specialPatient);

        $result = $specialPatient->scopeAssignedToTherapist($builder, 1);

        expect($result)->toBe($builder);
    });
});
