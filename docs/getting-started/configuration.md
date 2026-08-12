# Configuration

Generate a default `typephp.php` configuration file in your project root directory:

```bash
vendor/bin/typephp config:init
```

---

## Default Configuration Options

```php
<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Global Master Switch
    |--------------------------------------------------------------------------
    | Controls whether TypePHP enforces type checks at runtime.
    | Set to false for an emergency kill-switch or zero-overhead benchmarking.
    */
    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Function Boundary Contracts (@param & @return)
    |--------------------------------------------------------------------------
    | Enforces function and method parameter and return type contracts uniformly.
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
    'magic_methods'    => true,

    /*
    |--------------------------------------------------------------------------
    | Respect Ignore Docblock Tags
    |--------------------------------------------------------------------------
    | Set to false in CI/CD runs to force type-checking on @typephp-ignore methods.
    */
    'respect_ignore_tags' => true,

    /*
    |--------------------------------------------------------------------------
    | Enable Caching
    |--------------------------------------------------------------------------
    | Pre-transforms and caches PHP files on disk for maximum speed.
    */
    'cache' => true,

    /*
    |--------------------------------------------------------------------------
    | Registered Extensions
    |--------------------------------------------------------------------------
    | Explicitly list third-party extension classes.
    */
    'extensions' => [
        // \Acme\Domain\TypePHPExtension::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Inline Variable Validation (@var $x = ...)
    |--------------------------------------------------------------------------
    | Fine-grained control over local variable assignment checks.
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
    | Note: you can just specify "*" glob pattern to include all files including in the root folder.
    */
    'include' => [
        'src/**',
        'app/**',
        'internals/**',
        'tests/**',
        // 'vendor/my-org/my-package/**', // Whitelist a specific vendor package
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Paths
    |--------------------------------------------------------------------------
    | Globs or specific file paths that should be ignored by the type checker.
    */
    'exclude' => [
        'vendor/**',
        'storage/**',
        'var/**',
        'cache/**',
    ],
];
```

---

## Configuration Reference

Key options explained:

| Configuration Option | Default | Description |
| :--- | :--- | :--- |
| **`'enabled'`** | `true` | Global master switch for runtime type enforcement. |
| **`'params'`** | `true` | Enforces parameter `@param` contracts on physical functions and methods. |
| **`'returns'`** | `true` | Enforces return `@return` contracts on physical functions and methods. |
| **`'magic_properties'`** | `true` | Enforces class-level `@property`, `@property-read`, and `@property-write` annotations on dynamic assignments (`__set`). |
| **`'magic_methods'`** | `true` | Enforces class-level `@method` annotations on dynamic method calls (`__call` / `__callStatic`). |
| **`'respect_ignore_tags'`** | `true` | Respects `@typephp-ignore` and `@typephp-ignore-file` tags. Set to `false` in CI/CD to force audit checks. |
| **`'cache'`** | `true` | Pre-transforms and caches PHP files on disk (`typephp-cache/`) for OPcache optimization. |

---

## Inline Variable Categories Reference (`inline_vars`)

How each `inline_vars` toggle maps to PHPDoc type annotations:

| Config Option | Covered PHPDoc Types | Examples |
| :--- | :--- | :--- |
| **`'scalars'`** | Primitive & Refined Scalars | `int`, `string`, `bool`, `positive-int`, `non-empty-string`, `truthy` |
| **`'objects'`** | Class Instances & Bare Class References | `User`, `stdClass`, `class-string`, `interface-string`, `enum-string` |
| **`'generics'`** | Template & Bound Types | `Collection<User>`, `Producer<T>`, `class-string<T>` |
| **`'arrays'`** | All Arrays, Shapes, & Lists | `array{id: int}`, `int[]`, `User[]`, `list<string>`, `array<string, int>` |
| **`'callables'`** | Callables & Closures | `callable`, `Closure`, `callable(int): string`, `static-closure` |
| **`'properties'`** | Class Property Writes | `$this->id = 1`, `UserProfile::$username = 'Alice'` |

### Important Notes on `inline_vars` Behavior

* **Inner Structural Types Are Always Validated:** Disabling `'scalars' => false` only turns off standalone scalar assignments (such as `/** @var positive-int $x */`). If `'arrays'` or `'generics'` is enabled, TypePHP **will still validate inner scalar constraints** inside array shapes (`array{id: positive-int}`), lists (`list<positive-int>`), or generic containers (`Collection<positive-int>`) to maintain structural type integrity.
* **Active Generic Instance Prebinding:** Enabling `'generics' => true` allows inline `@var` annotations on object instantiations (such as `/** @var Collection<User> $users */ $users = new Collection();`) to **actively prebind generic template parameters (`T = User`)** directly to that object instance in `WeakMap` memory. Every subsequent method call on that instance (`$users->add()`, `$users->get()`) will enforce `T = User`!

---

## Pattern Specificity Rules

If a file matches both an `include` rule and an `exclude` rule, TypePHP compares pattern lengths:

* **Specific Whitelist Wins:** `'vendor/my-org/package/**'` (length 25) takes precedence over `'vendor/**'` (length 8).
* **Single File Override:** `'src/LegacyFile.php'` (length 22) takes precedence over `'src/**'` (length 6).
* **Tie-Breaker:** If pattern lengths are equal, `exclude` takes precedence to ensure application safety.
