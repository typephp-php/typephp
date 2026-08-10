# CLI Commands Reference

TypePHP provides a CLI runner binary (`vendor/bin/typephp`) with colon-style commands (`cache:clear`, `cache:warm`, `cache:rebuild`, `config:init`) for managing AST transformation caches and configuration.

---

## Configuration Initializer (`config:init`)

Generate a default `typephp.php` configuration file populated with documented settings in your project root directory:

```bash
vendor/bin/typephp config:init
```

### Terminal Output
```
  TYPEPHP  Configuration Initializer

  ✓ Created "typephp.php" in project root directory.
```

If `typephp.php` already exists, `config:init` preserves your existing file without overwriting it.

---

## Clearing Cache (`cache:clear`)

Wipe all transformed PHP files from the `typephp-cache/` disk directory:

```bash
vendor/bin/typephp cache:clear
```

### Terminal Output
```
  TYPEPHP  Cache Clear

  ✓ Cleared 178 cached file(s).
```

Use `cache:clear` whenever you update TypePHP or change global configuration settings.

---

## Warming Cache (`cache:warm`)

Recursively scan your project directory for files matching your `typephp.php` `include` patterns and pre-transform them before opening web traffic:

```bash
vendor/bin/typephp cache:warm
```

### Terminal Output
```
  TYPEPHP  Cache Warm-Up

  ................................................................................
  ................................................................................
  ..................

  ✓ Cache warm-up complete
    • Scanned:     178 file(s)
    • Transformed: 178 file(s)
    • Skipped:     0 file(s)
```

### Progress Indicators

* **`.` (Dot):** A file was successfully parsed, transformed, and cached to disk.
* **`s` (Skipped):** A file is already up-to-date in cache or matches an `exclude` pattern.

### Deployment Script Usage

Add `cache:warm` to your CI/CD or deployment pipeline (Forge, Envoyer, GitHub Actions) so the very first production HTTP request receives instant $O(1)$ native OPCache execution speed:

```bash
# In your deployment script:
php vendor/bin/typephp cache:warm
```

---

## Rebuilding Cache (`cache:rebuild`)

Wipe all existing cache files and immediately pre-transform the new release files in a single atomic command:

```bash
vendor/bin/typephp cache:rebuild
```

### Terminal Output
```
  TYPEPHP  Cache Clear

  ✓ Cleared 178 cached file(s).

  TYPEPHP  Cache Warm-Up

  ................................................................................
  ................................................................................
  ..................

  ✓ Cache warm-up complete
    • Scanned:     178 file(s)
    • Transformed: 178 file(s)
    • Skipped:     0 file(s)
```

This is the recommended command for automated zero-downtime deployment scripts.

---

## Help Menu (`help`)

Display the interactive CLI runner help menu:

```bash
vendor/bin/typephp help
```

### Terminal Output
```
  TYPEPHP  Runtime Type Checker

  USAGE
    vendor/bin/typephp <script.php>

  COMMANDS
    config:init    Generate default typephp.php configuration file
    cache:clear    Clear all cached transformed files
    cache:warm     Pre-transform and warm up cache for included files
    cache:rebuild  Clear and immediately warm up cache
    help           Display this help menu

  EXAMPLES
    vendor/bin/typephp config:init
    vendor/bin/typephp index.php
    vendor/bin/typephp cache:rebuild
```
