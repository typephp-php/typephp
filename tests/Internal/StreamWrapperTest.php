<?php

declare(strict_types=1);

use TypePHP\Contract\FileFilter;
use TypePHP\Internal\Config;
use TypePHP\Internal\StreamWrapper;

describe('StreamWrapper Unit Tests', function () {
    test('transformSource transforms functions and injects RuntimeTypeChecker checks', function () {
        $source = <<<'PHP'
<?php

/**
 * @param positive-int $id
 */
function testUser(int $id): bool
{
    return true;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_sample.php');

        expect($transformed)->toContain('RuntimeTypeChecker::setupScope')
            ->and($transformed)->toContain('testUser')
        ;
    });

    test('transformSource returns raw source unchanged if source is not valid PHP', function () {
        $invalidSource = '<?php invalid php syntax {{{';

        // StreamWrapper should gracefully handle parsing errors
        $transformed = StreamWrapper::transformSource($invalidSource, 'bad.php');

        expect($transformed)->toBe($invalidSource);
    });

    test('transformSource wraps yield expressions in generator functions', function () {
        $genSource = <<<'PHP'
<?php

/**
 * @return Generator<string, positive-int>
 */
function testGen(): Generator
{
    yield 'a' => 10;
}
PHP;

        $transformed = StreamWrapper::transformSource($genSource, 'gen.php');

        expect($transformed)->toContain('RuntimeTypeChecker::checkYield')
            ->and($transformed)->toContain('RuntimeTypeChecker::checkSend')
        ;
    });

   test('strictly isolates vendor files with nested src directories when application includes specific src subpackages', function () {
        Config::set([
            'include' => [
                'src/**',
                'src/Core/**',         
                'src/Storefront/**',
                'src/Administration/**',
            ],
            'exclude' => [
                'vendor/**',          
                'storage/**',
                'var/**',
                'cache/**',
            ],
        ]);

        $projectRoot = Config::getProjectRoot();

        $vendorFile = str_replace('\\', '/', $projectRoot . '/vendor/doctrine/dbal/src/Core/Table.php');
        $appFile = str_replace('\\', '/', $projectRoot . '/src/Core/Framework/Util.php');

        expect(FileFilter::isFileExcluded($vendorFile))->toBeTrue()
            ->and(FileFilter::isFileExcluded($appFile))->toBeFalse()
        ;

        Config::reset();
    });
});
