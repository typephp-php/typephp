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
    | Auto-Boot on Composer Autoload
    |--------------------------------------------------------------------------
    | When true (default), TypePHP automatically hooks into the stream wrapper
    | as soon as 'vendor/autoload.php' is required.
    |
    | Set to false to disable auto-booting project-wide. You can then manually
    | boot TypePHP where desired (e.g. in 'tests/bootstrap.php') via:
    |   \TypePHP\TypePHP::boot();
    |
    | Can also be configured in root composer.json:
    |   "extra": { "typephp": { "auto-boot": false } }
    */
    'auto_boot' => true,

    /*
    |--------------------------------------------------------------------------
    | Violation Handling Strategy & Audit Reporting
    |--------------------------------------------------------------------------
    | Controls what happens when a type contract is violated at runtime:
    |
    | - 'throw'  : (Default / Strict) Immediately throws TypePHP\Exception\TypeError.
    | - 'report' : (Audit Mode) Does not throw; records each unique violation
    |              and dumps a structured JSON audit report to 'report_file' at shutdown.
    | - 'warn'   : Emits PHP E_USER_WARNING once per unique violation to your logs
    |              and allows execution to continue normally without crashing.
    |
    | 'report_file'    : Sets the target JSON output path when 'report' mode is active.
    | 'fail_on_report' : When true, terminates the process with exit code 1 at shutdown
    |                    if the violation report is non-empty. Useful for CI gates.
    */
    'on_violation' => 'throw',
    'report_file' => null,
    'fail_on_report' => false,

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
    | Enable Caching & Cache Directory
    |--------------------------------------------------------------------------
    | When enabled, transformed PHP files are cached on disk for speed.
    | Set to false to run AST transformations purely in RAM (php://memory).
    |
    | 'cache_dir' determines where these files are stored. By default (null),
    | it uses your system's temp directory. You can change this to a path
    | inside your project, e.g., __DIR__ . '/storage/framework/typephp'.
    | TypePHP will automatically protect this directory from being re-transformed.
    */
    'cache' => true,
    'cache_dir' => null,

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
    | Array Validation Strategy
    |--------------------------------------------------------------------------
    | Controls how collections (list<T>, array<K, V>, Type[]) are verified:
    |
    | - 'full'   : (Default / Strict) 100% exhaustive scan. Checks every single
    |             item in every array, guaranteeing every single offending item
    |             is caught without exception.
    |
    | - 'hybrid' : (Beartype O(1) Mode) Fast boundary + random sampling on
    |             arrays > 128 items. Ideal for massive production datasets.
    */
    'array_validation' => 'hybrid',

    /*
    |--------------------------------------------------------------------------
    | Respect Native Parameter Nullability
    |--------------------------------------------------------------------------
    | When true (default), if a native PHP parameter explicitly declares
    | nullable syntax (e.g. ?array $param = null), TypePHP permits null even
    | if the DocBlock author omitted "|null" (e.g. @param string[] $param).
    |
    | Set to false for strict pedantic enforcement where DocBlocks are the
    | absolute law and null is rejected unless explicitly typed in the DocBlock.
    */
    'respect_native_nullability' => true,

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
        'generics' => true,
        'callables' => true,
        'scalars' => true,
        'arrays' => true,
        'objects' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stub Files (DocBlock Overrides for Third-Party & Vendor Packages)
    |--------------------------------------------------------------------------
    | Path globs or specific file paths containing stub files (.stub, .stub.php, .php)
    | that override inaccurate or missing DocBlocks in third-party vendor packages.
    */
    'stubs' => [
        // 'stubs/**',
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
    | (vendor/monologvendor/monolog/monolog/src/Monolog/Logger.php').
    | You can use "*" glob to match any file.
    */
    'include' => [
        '*',
        'app/**',
        'internals/**',
        'tests/**',
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
        'app/BlacklistClass.php',
        'vendor/**',
        'storage/**',
        'var/**',
        'cache/**',
    ],
];
