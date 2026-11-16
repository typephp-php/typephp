<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Io;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;

describe('Multi-Namespace, Enum, and Trait Metadata Seeding (Red Phase)', function () {
    beforeEach(function () {
        Config::reset();
        StreamWrapper::reset();
        SpecialTypeResolver::reset();
    });

    afterEach(function () {
        Config::reset();
        StreamWrapper::reset();
        SpecialTypeResolver::reset();
    });

    describe('1. AST Seeding Without Disk Fallback (Virtual File)', function () {
        test('seeds TraitUse docblocks for classes across all namespace blocks in a multi-namespace file', function () {
            $source = <<<'PHP'
<?php

namespace VirtualMultiNs\First {
    use TypePHP\Tests\Fixtures\Domain\Dog;

    class FirstConsumer
    {
        /** @use GenericTrait<Dog> */
        use GenericTrait;
    }
}

namespace VirtualMultiNs\Second {
    use TypePHP\Tests\Fixtures\Domain\Cat;

    class SecondConsumer
    {
        /** @use GenericTrait<Cat> */
        use GenericTrait;
    }
}
PHP;

            StreamWrapper::transformSource($source, 'VirtualMultiNsClasses.php');

            $firstDocs = SpecialTypeResolver::getClassTraitUseDocs('VirtualMultiNs\First\FirstConsumer');
            $secondDocs = SpecialTypeResolver::getClassTraitUseDocs('VirtualMultiNs\Second\SecondConsumer');

            expect($firstDocs)->toHaveCount(1)
                ->and($firstDocs[0])->toContain('GenericTrait<Dog>')
                ->and($secondDocs)->toHaveCount(1)
                ->and($secondDocs[0])->toContain('GenericTrait<Cat>')
            ;
        });

        test('seeds TraitUse docblocks for Enum_ and Trait_ declarations', function () {
            $source = <<<'PHP'
<?php

namespace VirtualEnumTraitNs;

trait InnerGenericTrait {}

trait OuterUsingTrait
{
    /** @use InnerGenericTrait<positive-int> */
    use InnerGenericTrait;
}

enum StatusEnumWithTrait: string
{
    /** @use OuterUsingTrait<non-empty-string> */
    use OuterUsingTrait;

    case Active = 'active';
}
PHP;

            StreamWrapper::transformSource($source, 'VirtualEnumAndTraitSeed.php');

            $traitDocs = SpecialTypeResolver::getClassTraitUseDocs('VirtualEnumTraitNs\OuterUsingTrait');
            $enumDocs = SpecialTypeResolver::getClassTraitUseDocs('VirtualEnumTraitNs\StatusEnumWithTrait');

            expect($traitDocs)->toHaveCount(1)
                ->and($traitDocs[0])->toContain('InnerGenericTrait<positive-int>')
                ->and($enumDocs)->toHaveCount(1)
                ->and($enumDocs[0])->toContain('OuterUsingTrait<non-empty-string>')
            ;
        });

        test('seeds TraitUse docblocks for Enum_ and Trait_ inside a second namespace block', function () {
            $source = <<<'PHP'
<?php

namespace VirtualMultiNsEnum\Alpha;

class AlphaDummy {}

namespace VirtualMultiNsEnum\Beta;

enum BetaEnum: string
{
    /** @use BetaTrait<int> */
    use BetaTrait;

    case One = 'one';
}

trait BetaTraitHolder
{
    /** @use BetaTrait<string> */
    use BetaTrait;
}
PHP;

            StreamWrapper::transformSource($source, 'VirtualMultiNsEnumTrait.php');

            $enumDocs = SpecialTypeResolver::getClassTraitUseDocs('VirtualMultiNsEnum\Beta\BetaEnum');
            $traitDocs = SpecialTypeResolver::getClassTraitUseDocs('VirtualMultiNsEnum\Beta\BetaTraitHolder');

            expect($enumDocs)->toHaveCount(1)
                ->and($enumDocs[0])->toContain('BetaTrait<int>')
                ->and($traitDocs)->toHaveCount(1)
                ->and($traitDocs[0])->toContain('BetaTrait<string>')
            ;
        });
    });

    describe('2. End-to-End Runtime Resolution in Multi-Namespace Files', function () {
        test('resolves use imports and same-namespace classes in the second namespace block at runtime', function () {
            $baseTemp = sys_get_temp_dir() . '/typephp_multins_' . uniqid();
            mkdir($baseTemp, 0777, true);
            $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;
            $filePath = $tempDir . '/multi_ns_runtime.php';

            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace MultiNsRuntime\First {
    use TypePHP\Tests\Fixtures\Domain\Dog;

    class FirstBox
    {
        /**
         * @var Dog
         */
        public object $pet;
    }
}

namespace MultiNsRuntime\Second {
    use TypePHP\Tests\Fixtures\Domain\Cat;

    class SecondHelper
    {
    }

    class SecondBox
    {
        /**
         * @var Cat
         */
        public object $pet;

        /**
         * @var SecondHelper
         */
        public object $helper;
    }
}
PHP;
            file_put_contents($filePath, $source);

            try {
                $normDir = str_replace('\\', '/', $tempDir);
                Config::set([
                    'include' => [$normDir . '/**'],
                    'exclude' => ['vendor/**'],
                ]);
                StreamWrapper::register();

                require_once $filePath;

                $first = new \MultiNsRuntime\First\FirstBox();
                $first->pet = new Dog();
                expect($first->pet)->toBeInstanceOf(Dog::class);

                $second = new \MultiNsRuntime\Second\SecondBox();
                $second->pet = new Cat();
                $second->helper = new \MultiNsRuntime\Second\SecondHelper();

                expect($second->pet)->toBeInstanceOf(Cat::class)
                    ->and($second->helper)->toBeInstanceOf(\MultiNsRuntime\Second\SecondHelper::class)
                ;

                expect(fn () => $second->pet = new Car())
                    ->toThrow(TypeError::class, Cat::class)
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });

        test('isolates conflicting use aliases across multiple namespaces in the same file', function () {
            $baseTemp = sys_get_temp_dir() . '/typephp_multins_alias_' . uniqid();
            mkdir($baseTemp, 0777, true);
            $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;
            $filePath = $tempDir . '/multi_ns_conflict.php';

            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace MultiNsConflict\First;

use TypePHP\Tests\Fixtures\Domain\Dog as SharedPetAlias;

class FirstHolder
{
    /**
     * @var SharedPetAlias
     */
    public object $pet;
}

namespace MultiNsConflict\Second;

use TypePHP\Tests\Fixtures\Domain\Cat as SharedPetAlias;

class SecondHolder
{
    /**
     * @var SharedPetAlias
     */
    public object $pet;
}
PHP;
            file_put_contents($filePath, $source);

            try {
                $normDir = str_replace('\\', '/', $tempDir);
                Config::set([
                    'include' => [$normDir . '/**'],
                    'exclude' => ['vendor/**'],
                ]);
                StreamWrapper::register();

                require_once $filePath;

                $first = new \MultiNsConflict\First\FirstHolder();
                $first->pet = new Dog();
                expect($first->pet)->toBeInstanceOf(Dog::class);

                expect(fn () => $first->pet = new Cat())
                    ->toThrow(TypeError::class, Dog::class)
                ;

                $second = new \MultiNsConflict\Second\SecondHolder();
                $second->pet = new Cat();
                expect($second->pet)->toBeInstanceOf(Cat::class);

                expect(fn () => $second->pet = new Dog())
                    ->toThrow(TypeError::class, Cat::class)
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });

        test('enforces @use generic trait contract on an Enum and nested Trait at runtime', function () {
            $baseTemp = sys_get_temp_dir() . '/typephp_enum_trait_' . uniqid();
            mkdir($baseTemp, 0777, true);
            $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;
            $filePath = $tempDir . '/enum_nested_trait.php';

            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace RuntimeEnumTraitNs;

/**
 * @template T
 */
trait LeafGenericTrait
{
    /**
     * @param T $val
     */
    public function accept(mixed $val): mixed
    {
        return $val;
    }
}

/**
 * @template U
 */
trait MiddleNestedTrait
{
    /** @use LeafGenericTrait<U> */
    use LeafGenericTrait;
}

enum ColorCode: string
{
    /** @use MiddleNestedTrait<positive-int> */
    use MiddleNestedTrait;

    case Red = 'red';
}
PHP;
            file_put_contents($filePath, $source);

            try {
                $normDir = str_replace('\\', '/', $tempDir);
                Config::set([
                    'include' => [$normDir . '/**'],
                    'exclude' => ['vendor/**'],
                ]);
                StreamWrapper::register();

                require_once $filePath;

                expect(\RuntimeEnumTraitNs\ColorCode::Red->accept(42))->toBe(42);

                expect(fn () => \RuntimeEnumTraitNs\ColorCode::Red->accept(-5))
                    ->toThrow(TypeError::class, 'positive-int')
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });
    });
});
