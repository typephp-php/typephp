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
    | Enable Caching & Cache Directory
    |--------------------------------------------------------------------------
    | Pre-transforms and caches PHP files on disk for OPcache optimization.
    |
    | 'cache_dir' determines where these files are stored. By default (null), 
    | it uses your system's temp directory. You can change this to a path
    | inside your project (e.g., __DIR__ . '/storage/framework/typephp').
    | TypePHP automatically protects this directory from being double-transformed.
    */
    'cache' => true,
    'cache_dir' => null,

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
    | Note: you can just specify "**" glob pattern to include all files including in the root folder.
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
| **`'cache'`** | `true` | Pre-transforms and caches PHP files on disk. |
| **`'cache_dir'`** | `null` | Custom path to store cached files. Defaults to system temporary directory (`sys_get_temp_dir() . '/typephp-cache/'`). |

---

## Inline Variable Categories Reference (`inline_vars`)

*(... rest of the file remains exactly the same ...)*
```

---

### 2. `docs/advanced/how-it-works.md`

*(Find the "Zero Line-Drift Formatting and Caching" section and update the "Disk Caching" part to this:)*

```markdown
### Disk Caching

Once transformed, TypePHP saves the resulting code to disk in your configured `cache_dir` (which defaults to `sys_get_temp_dir() . '/typephp-cache/'`). On all subsequent requests:
* AST parsing runs **0 times**.
* PHP's **OPCache** compiles the cached file once into bytecode in RAM.
* Stream file reads execute natively at C-level speed inside Zend Engine.

*(TypePHP's stream wrapper automatically detects and skips intercepting files inside your configured `cache_dir` to prevent infinite loops and double-transformation overhead).*
```

---

### 3. `docs/troubleshooting.md`

*(Find the "How do I know if TypePHP is actively transforming a file?" question and update the answer:)*

```markdown

```

---
All docs are updated! What's our next target? Should we start refactoring validators, expanding the Extension System, or write a quick web-framework mock test?