<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;

abstract class EloquentModelFixture
{
}

class SchoolYearModelFixture extends EloquentModelFixture
{
}

/**
 * @template TRelated of EloquentModelFixture
 * @template TDeclaring of EloquentModelFixture
 */
class BelongsToRelationFixture
{
    /**
     * @param TDeclaring $parent
     * @param TRelated $related
     */
    public function __construct(
        public EloquentModelFixture $parent,
        public EloquentModelFixture $related
    ) {
    }
}

class StudentModelFixture extends EloquentModelFixture
{
    /**
     * @return BelongsToRelationFixture<SchoolYearModelFixture, $this>
     */
    public function schoolYearThis(): BelongsToRelationFixture
    {
        return new BelongsToRelationFixture($this, new SchoolYearModelFixture());
    }

    /**
     * @return BelongsToRelationFixture<SchoolYearModelFixture, static>
     */
    public function schoolYearStatic(): BelongsToRelationFixture
    {
        return new BelongsToRelationFixture($this, new SchoolYearModelFixture());
    }

    /**
     * @return BelongsToRelationFixture<SchoolYearModelFixture, self>
     */
    public function schoolYearSelf(): BelongsToRelationFixture
    {
        return new BelongsToRelationFixture($this, new SchoolYearModelFixture());
    }
}

class GraduatingStudentFixture extends StudentModelFixture
{
    /**
     * When the subclass overrides with self, self refers to GraduatingStudentFixture:
     *
     * @return BelongsToRelationFixture<SchoolYearModelFixture, self>
     */
    public function schoolYearSelfOverride(): BelongsToRelationFixture
    {
        return new BelongsToRelationFixture($this, new SchoolYearModelFixture());
    }
}

class NonModelFixture
{
    /**
     * @return BelongsToRelationFixture<SchoolYearModelFixture, $this>
     */
    public function invalidRelationThis(): BelongsToRelationFixture
    {
        return new BelongsToRelationFixture(new SchoolYearModelFixture(), new SchoolYearModelFixture());
    }

    /**
     * @return BelongsToRelationFixture<SchoolYearModelFixture, static>
     */
    public function invalidRelationStatic(): BelongsToRelationFixture
    {
        return new BelongsToRelationFixture(new SchoolYearModelFixture(), new SchoolYearModelFixture());
    }

    /**
     * @return BelongsToRelationFixture<SchoolYearModelFixture, self>
     */
    public function invalidRelationSelf(): BelongsToRelationFixture
    {
        return new BelongsToRelationFixture(new SchoolYearModelFixture(), new SchoolYearModelFixture());
    }
}

describe('Generic Type Argument ($this, static, self) Resolution', function () {
    describe('Valid Eloquent Models', function () {
        test('resolves $this inside generic argument to declaring model class', function () {
            $student = new StudentModelFixture();
            $relation = $student->schoolYearThis();

            expect($relation)->toBeInstanceOf(BelongsToRelationFixture::class)
                ->and($relation->parent)->toBe($student)
                ->and($relation->related)->toBeInstanceOf(SchoolYearModelFixture::class)
            ;
        });

        test('resolves static inside generic argument to calling model class', function () {
            $student = new StudentModelFixture();
            $relation = $student->schoolYearStatic();

            expect($relation)->toBeInstanceOf(BelongsToRelationFixture::class);
        });

        test('resolves self inside generic argument to declaring model class', function () {
            $student = new StudentModelFixture();
            $relation = $student->schoolYearSelf();

            expect($relation)->toBeInstanceOf(BelongsToRelationFixture::class);
        });

        test('resolves $this and static dynamically on child model subclasses', function () {
            $grad = new GraduatingStudentFixture();

            expect($grad->schoolYearThis())->toBeInstanceOf(BelongsToRelationFixture::class)
                ->and($grad->schoolYearStatic())->toBeInstanceOf(BelongsToRelationFixture::class)
                ->and($grad->schoolYearSelfOverride())->toBeInstanceOf(BelongsToRelationFixture::class)
            ;
        });
    });

    describe('Invalid Non-Model Classes (Upper Bound Rejections)', function () {
        test('rejects $this when declaring class does not satisfy upper bound Model', function () {
            $nonModel = new NonModelFixture();

            expect(fn () => $nonModel->invalidRelationThis())
                ->toThrow(
                    TypeError::class,
                    'Generic type argument ' . NonModelFixture::class . ' does not satisfy upper bound'
                )
            ;
        });

        test('rejects static when declaring class does not satisfy upper bound Model', function () {
            $nonModel = new NonModelFixture();

            expect(fn () => $nonModel->invalidRelationStatic())
                ->toThrow(
                    TypeError::class,
                    'Generic type argument ' . NonModelFixture::class . ' does not satisfy upper bound'
                )
            ;
        });

        test('rejects self when declaring class does not satisfy upper bound Model', function () {
            $nonModel = new NonModelFixture();

            expect(fn () => $nonModel->invalidRelationSelf())
                ->toThrow(
                    TypeError::class,
                    'Generic type argument ' . NonModelFixture::class . ' does not satisfy upper bound'
                )
            ;
        });
    });
});
