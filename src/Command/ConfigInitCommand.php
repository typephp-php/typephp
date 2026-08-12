<?php

declare(strict_types=1);

namespace TypePHP\Command;

final class ConfigInitCommand implements CommandInterface
{
    public function execute(array $args, $outputStream = STDOUT, $errorStream = STDERR): int
    {
        $c = [CliFormatter::class, 'color'];
        $cwd = getcwd();
        $targetFile = ($cwd !== false ? $cwd : '.') . '/typephp.php';

        fwrite($outputStream, "\n  " . $c(' TYPEPHP ', 'badge') . ' ' . $c('Configuration Initializer', 'bold') . "\n\n");

        if (file_exists($targetFile)) {
            fwrite($outputStream, '  ' . $c('•', 'cyan') . ' Configuration file ' . $c('"typephp.php"', 'bold') . " already exists.\n\n");

            return 0;
        }

        $template = self::getTemplate();
        file_put_contents($targetFile, $template);

        fwrite($outputStream, '  ' . $c('✓', 'green') . ' Created ' . $c('"typephp.php"', 'bold') . " in project root directory.\n\n");

        return 0;
    }

    private static function getTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Global Master Switch
    |--------------------------------------------------------------------------
    | Controls whether TypePHP enforces type checks at runtime.
    | Set to false for an emergency kill-switch or zero-overhead benchmarking.
    |
    | Note on Disabling Approaches:
    | - Config Switch ('enabled' => false): TypePHP boots normally, but turns all
    |   runtime checks into instant no-ops (pass-through mode).
    | - Bootstrap Prevention (TYPEPHP_DISABLE=true): To completely prevent TypePHP
    |   from booting or registering its stream wrapper during Composer autoload,
    |   set the environment variable TYPEPHP_DISABLE=true or define('TYPEPHP_DISABLE', true)
    |   before requiring 'vendor/autoload.php'.
    */
    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Function Boundary Contracts (@param & @return)
    |--------------------------------------------------------------------------
    | Controls whether function and method parameter/return contracts are enforced.
    | When enabled, all parameter and return types (generics, shapes, scalars)
    | are enforced uniformly to maintain type state consistency.
    */
    'params' => true,
    'returns' => true,

    /*
    |--------------------------------------------------------------------------
    | Magic Annotations (@property & @method)
    |--------------------------------------------------------------------------
    | Enforces class-level annotations for dynamic properties and magic methods
    | routed through __get, __set, __call, and __callStatic.
    */
    'magic_properties' => true,
    'magic_methods' => true,

    /*
    |--------------------------------------------------------------------------
    | Respect Ignore Docblock Tags
    |--------------------------------------------------------------------------
    | When true (default), @typephp-ignore and @typephp-ignore-file docblock tags
    | skip type-checking on specific methods/files. Set to false in CI/CD or
    | audit runs to force type-checking on all ignored methods without deleting
    | the docblock tags from source code.
    */
    'respect_ignore_tags' => true,

    /*
    |--------------------------------------------------------------------------
    | Enable Caching
    |--------------------------------------------------------------------------
    | When enabled, transformed PHP files are cached on disk for speed.
    | Set to false to run AST transformations purely in RAM (php://memory).
    */
    'cache' => true,

    /*
    |--------------------------------------------------------------------------
    | Registered Extensions
    |--------------------------------------------------------------------------
    | Explicitly list third-party extension classes that provide path overrides.
    */
    'extensions' => [
        // \Acme\Domain\TypePHPExtension::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Inline Variable Validation (@var $x = ...)
    |--------------------------------------------------------------------------
    | Fine-grained control over which type categories are enforced on local
    | variable assignments with inline @var Type $var docblocks.
    |
    | Supported options:
    | - 'properties': Validates class property assignments (e.g. $this->id = 1).
    | - 'generics'  : Prebinds generic template instances (e.g. Collection<Dog>).
    | - 'callables' : Wraps inline callbacks (e.g. callable(int): string).
    | - 'scalars'   : Enforces scalar constraints (e.g. positive-int, non-empty-string).
    | - 'arrays'    : Enforces array shapes, lists, & typed arrays (e.g. array{id: int}, int[]).
    | - 'objects'   : Enforces class instance checks (e.g. @var User $user).
    */
    'inline_vars' => [
        'properties' => true,
        'generics'   => true,
        'callables'  => true,
        'scalars'    => true,
        'arrays'     => true,
        'objects'    => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Included Paths & Whitelisting
    |--------------------------------------------------------------------------
    | Globs or specific file paths that should be intercepted and type-checked.
    |
    | Pattern Specificity:
    | More specific patterns take precedence over broader rules.
    | You can specify directory globs (e.g. 'src/**'), single vendor packages
    | (e.g. 'vendor/my-org/my-package/**'), or single specific files
    | (e.g. 'vendor/monolog/monolog/src/Monolog/Logger.php').
    */
    'include' => [
        'src/**',
        'app/**',
        'tests/**',
        // 'vendor/my-org/my-package/**', // Whitelist a vendor package
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Paths & Single-File Blacklisting
    |--------------------------------------------------------------------------
    | Globs or specific file paths that should be ignored by the type checker.
    | You can exclude entire directories (e.g. 'vendor/**') or blacklist
    | single legacy files inside included directories (e.g. 'src/Legacy/File.php').
    */
    'exclude' => [
        'vendor/**',
        'storage/**',
        'var/**',
        'cache/**',
        // 'src/Legacy/UnsafeFile.php', // Blacklist a single specific file
    ],
];
PHP;
    }
}
