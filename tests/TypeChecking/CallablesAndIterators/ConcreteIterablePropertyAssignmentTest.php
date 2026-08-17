<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Collections\ConcreteFileCollection;
use TypePHP\Tests\Fixtures\Collections\PluginConfiguration;

describe('Concrete Traversable & Iterable Property Assignments (Shopware Bug Reproduction)', function () {
    describe('Un-annotated & DocBlock-Annotated Methods', function () {
        test('does not wrap concrete collection in IteratorProxy when setting and getting typed property (un-annotated)', function () {
            $config = new PluginConfiguration();
            $files = new ConcreteFileCollection();
            $files->add('style.css');

            $config->setStyleFiles($files);

            expect($config->getStyleFiles())->toBeInstanceOf(ConcreteFileCollection::class)
                ->and($config->getStyleFiles())->toBe($files)
            ;
        });

        test('does not wrap concrete collection when method has explicit @param ConcreteFileCollection docblock', function () {
            $config = new PluginConfiguration();
            $files = new ConcreteFileCollection();
            $files->add('custom.css');

            $config->setStyleFilesWithDocblock($files);

            expect($config->styleFiles)->toBeInstanceOf(ConcreteFileCollection::class)
                ->and($config->styleFiles)->toBe($files)
            ;
        });

        test('does not wrap concrete collection when method has explicit @return ConcreteFileCollection docblock', function () {
            $config = new PluginConfiguration();
            $files = new ConcreteFileCollection();
            $files->add('bundle.css');
            $config->setStyleFiles($files);

            $returned = $config->getStyleFilesWithDocblock();

            expect($returned)->toBeInstanceOf(ConcreteFileCollection::class)
                ->and($returned)->toBe($files)
            ;
        });
    });

    describe('Constructor Promotion & Nullable Properties', function () {
        test('preserves concrete collection type in constructor property promotion without wrapping in IteratorProxy', function () {
            $files = new ConcreteFileCollection();
            $files->add('promoted.css');

            $config = new PluginConfiguration($files);

            expect($config->promotedFiles)->toBeInstanceOf(ConcreteFileCollection::class)
                ->and($config->promotedFiles)->toBe($files)
            ;
        });

        test('handles nullable concrete collection parameter cleanly', function () {
            $config = new PluginConfiguration();

            $config->setNullableFiles(null);
            expect($config->styleFiles)->toBeInstanceOf(ConcreteFileCollection::class);

            $files = new ConcreteFileCollection();
            $files->add('new.css');
            $config->setNullableFiles($files);

            expect($config->styleFiles)->toBe($files);
        });
    });

    describe('Inline @var Assignments', function () {
        test('preserves concrete collection instance in inline @var variable assignment', function () {
            /** @var ConcreteFileCollection $localFiles */
            $localFiles = new ConcreteFileCollection();
            $localFiles->add('local.css');

            expect($localFiles)->toBeInstanceOf(ConcreteFileCollection::class);
        });
    });

    describe('Legitimate Generic Traversable Contracts', function () {
        test('correctly validates items when method explicitly declares @param Traversable<string>', function () {
            $config = new PluginConfiguration();
            $collection = new ConcreteFileCollection();
            $collection->add('valid_a.css');
            $collection->add('valid_b.css');

            $result = $config->processGenericTraversable($collection);
            expect($result)->toBe(['valid_a.css', 'valid_b.css']);
        });
    });
});
