<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError as TypePHPTypeError;
use TypePHP\TypePHP;

class EloquentModelFixture
{
}

class TherapistModelFixture extends EloquentModelFixture
{
}

class RoomModelFixture extends EloquentModelFixture
{
}

/**
 * Mirrors Illuminate\Support\Collection (@template TKey of array-key, @template-covariant TValue).
 *
 * @template TKey of array-key
 *
 * @template-covariant TValue
 */
class BaseSupportCollectionFixture
{
}

/**
 * Mirrors Illuminate\Database\Eloquent\Collection
 * (@template TModel of Model, @extends BaseCollection<TKey, TModel>).
 *
 * @template TKey of array-key
 * @template TModel of EloquentModelFixture
 *
 * @extends BaseSupportCollectionFixture<TKey, TModel>
 */
class VendorEloquentCollectionFixture extends BaseSupportCollectionFixture
{
    /**
     * @typephp-ignore
     */
    public static function rawFromVendor(): self
    {
        return new self();
    }
}

/**
 * Subclass without its own docblock, exercising intermediate hierarchy fallback ($hierFallbackBindings).
 */
class SubVendorEloquentCollectionFixture extends VendorEloquentCollectionFixture
{
    /**
     * @typephp-ignore
     */
    public static function rawSubFromVendor(): self
    {
        return new self();
    }
}

/**
 * Concrete non-generic subclass explicitly locking TModel to EloquentModelFixture.
 *
 * @extends BaseSupportCollectionFixture<int, EloquentModelFixture>
 */
class ConcreteModelCollectionFixture extends BaseSupportCollectionFixture
{
}

final class EloquentCollectionConsumerFixture
{
    /**
     * @return BaseSupportCollectionFixture<int, TherapistModelFixture>
     */
    public function queryCandidateTherapists(): BaseSupportCollectionFixture
    {
        return VendorEloquentCollectionFixture::rawFromVendor();
    }

    /**
     * @return BaseSupportCollectionFixture<int, TherapistModelFixture>
     */
    public function querySubCandidateTherapists(): BaseSupportCollectionFixture
    {
        return SubVendorEloquentCollectionFixture::rawSubFromVendor();
    }

    /**
     * @param BaseSupportCollectionFixture<int, TherapistModelFixture> $therapists
     */
    public function acceptTherapists(BaseSupportCollectionFixture $therapists): void
    {
    }

    /**
     * @param BaseSupportCollectionFixture<int, RoomModelFixture> $rooms
     */
    public function acceptRooms(BaseSupportCollectionFixture $rooms): void
    {
    }

    /**
     * @param BaseSupportCollectionFixture<int, EloquentModelFixture> $models
     */
    public function acceptModels(BaseSupportCollectionFixture $models): void
    {
    }

    /**
     * @return BaseSupportCollectionFixture<int, string>
     */
    public function invalidReturnAsStrings(): BaseSupportCollectionFixture
    {
        return VendorEloquentCollectionFixture::rawFromVendor();
    }

    /**
     * Explicitly promises Collection<int, EloquentModelFixture> (not specialized to Therapist).
     *
     * @return BaseSupportCollectionFixture<int, EloquentModelFixture>
     */
    public function returnExplicitlyWideModels(): BaseSupportCollectionFixture
    {
        return VendorEloquentCollectionFixture::rawFromVendor();
    }

    /**
     * Concrete non-generic ModelCollection cannot satisfy Collection<int, Therapist>.
     *
     * @return BaseSupportCollectionFixture<int, TherapistModelFixture>
     */
    public function invalidReturnConcreteModelCollection(): BaseSupportCollectionFixture
    {
        return new ConcreteModelCollectionFixture();
    }
}

describe('Inherited Bounded Template Fallback Specialization (Laravel Eloquent Collection Pattern)', function () {
    test('specializes uninstrumented vendor EloquentCollection to @return Collection<int, Therapist>', function () {
        $service = new EloquentCollectionConsumerFixture();

        $therapists = $service->queryCandidateTherapists();
        $service->acceptTherapists($therapists);

        expect(TypePHP::getGenericType($therapists, 'TKey'))->toBe('int')
            ->and(TypePHP::getGenericType($therapists, 'TValue'))->toBe(TherapistModelFixture::class)
        ;
    });

    test('specializes subclass of vendor EloquentCollection via intermediate hierarchy fallback', function () {
        $service = new EloquentCollectionConsumerFixture();

        $therapists = $service->querySubCandidateTherapists();
        $service->acceptTherapists($therapists);

        expect(TypePHP::getGenericType($therapists, 'TKey'))->toBe('int')
            ->and(TypePHP::getGenericType($therapists, 'TValue'))->toBe(TherapistModelFixture::class)
        ;
    });

    test('specializes uninstrumented vendor EloquentCollection via @param and inline @var', function () {
        $service = new EloquentCollectionConsumerFixture();

        $directParamCol = VendorEloquentCollectionFixture::rawFromVendor();
        $service->acceptTherapists($directParamCol);
        expect(TypePHP::getGenericType($directParamCol, 'TKey'))->toBe('int')
            ->and(TypePHP::getGenericType($directParamCol, 'TValue'))->toBe(TherapistModelFixture::class)
        ;

        /** @var BaseSupportCollectionFixture<int, TherapistModelFixture> $inlineTherapists */
        $inlineTherapists = VendorEloquentCollectionFixture::rawFromVendor();
        $service->acceptTherapists($inlineTherapists);
        expect(TypePHP::getGenericType($inlineTherapists, 'TKey'))->toBe('int')
            ->and(TypePHP::getGenericType($inlineTherapists, 'TValue'))->toBe(TherapistModelFixture::class)
        ;
    });

    test('retains strict covariance and invariance after specialization and rejects invalid subtypes', function () {
        $service = new EloquentCollectionConsumerFixture();

        $therapists = $service->queryCandidateTherapists();
        $service->acceptModels($therapists);

        expect(fn () => $service->acceptRooms($therapists))
            ->toThrow(TypePHPTypeError::class, 'covariant ' . RoomModelFixture::class)
        ;

        expect(fn () => $service->invalidReturnAsStrings())
            ->toThrow(TypePHPTypeError::class, 'covariant string')
        ;

        $models = $service->returnExplicitlyWideModels();
        expect(fn () => $service->acceptTherapists($models))
            ->toThrow(TypePHPTypeError::class, 'covariant ' . TherapistModelFixture::class)
        ;

        expect(fn () => $service->invalidReturnConcreteModelCollection())
            ->toThrow(TypePHPTypeError::class, 'covariant ' . TherapistModelFixture::class)
        ;
    });
});
