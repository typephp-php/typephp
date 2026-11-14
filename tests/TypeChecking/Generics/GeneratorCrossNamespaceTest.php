<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Consumer\GenericIterableBox;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Domain\Sub\ImportedAnimalProducer;
use TypePHP\Tests\Fixtures\Domain\Sub\TraitAnimalProducer;

describe('Generator Template Inference Across Namespaces', function () {
    test('resolves same-namespace class in trait generator @return when consumed in another namespace', function () {
        $producer = new TraitAnimalProducer(new Dog());
        $box = GenericIterableBox::fromIterable($producer->yieldSameNamespaceAnimals());

        expect($box->values()['first'])->toBeInstanceOf(Animal::class);
    });

    test('resolves imported class in generator @return when consumed in another namespace', function () {
        $producer = new ImportedAnimalProducer();
        $box = GenericIterableBox::fromIterable($producer->yieldImportedAnimals());

        expect($box->values()['first'])->toBeInstanceOf(Animal::class);
    });

    test('resolves conditional generator @return in the generator own context across namespaces', function () {
        $dogProducer = new TraitAnimalProducer(new Dog());
        $dogBox = GenericIterableBox::fromIterable($dogProducer->yieldConditionalAnimal());
        expect($dogBox->values()['dog'])->toBeInstanceOf(Animal::class);

        $nullProducer = new TraitAnimalProducer(null);
        $carBox = GenericIterableBox::fromIterable($nullProducer->yieldConditionalAnimal());
        expect($carBox->values()['car'])->toBeInstanceOf(Car::class);
    });

    test('still throws TypeError with resolved FQCN when generator yields wrong type to consumer', function () {
        $producer = new class () {
            /**
             * @return \Generator<string, Animal>
             */
            public function yieldInvalid(): \Generator
            {
                yield 'bad' => new Car();
            }
        };

        expect(fn () => GenericIterableBox::fromIterable($producer->yieldInvalid()))
            ->toThrow(TypeError::class, Animal::class)
        ;
    });
});
