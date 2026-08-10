# Troubleshooting & FAQ

This page addresses common questions, debugging techniques, and edge cases you might encounter when using TypePHP in local development, test suites, or production environments.

---

## Configuration & Execution

### Why is my file or method not being type-checked?

If TypePHP is not enforcing contracts on a specific file or method, check the following common causes:

1. **Path Exclusion Specificity:** Check your `typephp.php` configuration. If your file matches an `exclude` pattern (such as `vendor/**` or `storage/**`), TypePHP skips AST transformation. Remember that equal length patterns favor `exclude`.
2. **Ignore Annotations:** Check if the file header contains `@typephp-ignore-file` or if the method docblock contains `@typephp-ignore`.
3. **Disabled Inline Variable Toggles:** If an inline variable (`/** @var positive-int $x */`) is not throwing an error, verify that the corresponding toggle inside `inline_vars` in `typephp.php` is set to `true`.
4. **Stale Cache:** If you recently edited docblocks or configuration settings, your pre-transformed file might be cached on disk. Run `vendor/bin/typephp cache:clear`.

---

### How do I know if TypePHP is actively transforming a file?

You can verify that a file is being intercepted and transformed in two ways:

1. **Intentionally Trigger an Error:** Pass an invalid argument (such as a negative integer to a `positive-int` parameter). If a `TypePHP\Exception\TypeError` is thrown, TypePHP is active.
2. **Inspect the Cache Directory:** Look inside your system temporary directory (`sys_get_temp_dir() . '/typephp-cache/'`). You will see transformed PHP files containing injected `RuntimeTypeChecker` calls.

---

### How do I clear the AST cache?

You can wipe the cache using the CLI runner:

```bash
vendor/bin/typephp cache:clear
```

If you are changing configuration settings frequently during local development, you can temporarily disable disk caching in `typephp.php`:

```php
'cache' => false, // Transforms files purely in RAM (php://memory)
```

---

## Type Enforcement & Edge Cases

### Why didn't TypePHP catch a bad property assignment from an external file?

TypePHP injects guard rails at the call site where assignments happen.

* **Whitelisted Caller File:** If `Controller.php` (whitelisted) sets `$user->id = -5`, TypePHP intercepts the assignment and throws a `TypeError`.
* **Excluded Caller File:** If `LegacyVendor.php` (excluded) sets `$user->id = -5`, TypePHP does not modify `LegacyVendor.php`, so the assignment runs natively.

**Solution:** In PHP 8.4, use **Property Hooks** (`set => $this->_id = $value`). Property hooks run *inside* the class itself, guaranteeing that assignments are validated regardless of where the call originated.

---

### Why is my Pest or PHPUnit test suite running slower with JIT enabled?

During CLI test execution, a single short-lived PHP process runs your tests. 

If you pass `-d opcache.enable_cli=1` with PHP 8 JIT enabled, PHP spends extra CPU cycles compiling JIT tracing buffers that are discarded the moment the test suite finishes a second later.

**Solution:** Run CLI test runs with standard PHP execution (without `opcache.enable_cli=1` or JIT enabled). TypePHP executes 380+ complex type checks in sub-second time without JIT. Save JIT optimization for long-running production web servers (PHP-FPM, FrankenPHP, Swoole).

---

### Why does a generic container allow any item if no annotation is provided?

If you instantiate a generic class without an inline `@var` annotation:

```php
$collection = new Collection(); // Unannotated generic instance
```

TypePHP uses **First-Use Type Inference**. It allows the first method call (such as `$collection->add(new User())`) to establish the template type `T = User`. Once established, all subsequent calls on that instance enforce `T = User`. 

If you want strict enforcement before any items are added, prebind the instance using an inline `@var` annotation:

```php
/** @var Collection<User> $collection */
$collection = new Collection();
```

---

## Frameworks & Tooling

### Does TypePHP work with Laravel, Symfony, or WordPress?

Yes. TypePHP boots automatically as soon as Composer's autoloader (`vendor/autoload.php`) is required. 

It works seamlessly with standard framework entry points like `public/index.php`, Laravel's `artisan`, or Symfony's `bin/console`. No special framework bundles or service providers are required.

---

### How do I temporarily disable TypePHP in an emergency?

You have two options for turning off TypePHP instantly:

1. **Environment Level (Full Prevention):** Set `TYPEPHP_DISABLE=true` in your `.env` or server environment. This prevents TypePHP from registering its stream wrapper during Composer autoloading.
2. **Config Level (Pass-Through Mode):** Set `'enabled' => false` in `typephp.php` or call `TypePHP::setConfig(['enabled' => false])`. TypePHP will run, but all checks turn into instant no-ops.

---

### Can I run TypePHP alongside static analysis tools?

Yes, it is highly recommended. 

* **Static Analyzers (PHPStan, Psalm, Mago):** Analyze your source code at compile-time, linting docblock syntax and checking static logic in your IDE.
* **TypePHP:** Enforces those same PHPDoc contracts at runtime during dynamic execution, protecting your application against invalid database records, un-sanitized API payloads, and unexpected runtime state.
