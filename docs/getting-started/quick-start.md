# Quick Start Guide

TypePHP enforces PHPDoc type contracts at runtime during execution. Below is an overview of core concepts, framework setup, and code examples.

---

## What is TypePHP?

TypePHP is a transparent, pure-PHP runtime type checker that enforces extended PHPDoc type contracts (`@param`, `@return`, `@var`, `@template`, `@property`, `@method`, array shapes, integer ranges, and scalar refinements) during actual execution. 

Unlike traditional assertion libraries that force you to write repetitive manual check calls inside every function, or validation frameworks that require custom PHP attributes and base classes, TypePHP requires **zero manual checks** and **zero new syntax**. It works transparently using your existing PHPDoc annotations.

---

## What Problem Does It Solve?

While native PHP type hints (such as `int $id` or `string $name`) enforce basic scalar types, PHP's C-engine ignores PHPDoc annotations at runtime. This creates a dangerous safety gap at application boundaries:

1. **Un-sanitized Dynamic Payloads:** HTTP API requests, Stripe webhooks, database query results, and JSON inputs frequently contain data that native PHP type hints allow through (such as passing a negative integer `-50` into a parameter expecting a `positive-int`).
2. **The "DocBlock Lie" Problem:** Developers write DocBlocks assuming they are accurate, but dynamic runtime callers can pass invalid data that bypasses native PHP type hints, polluting database state or causing silent bugs.
3. **Manual Validation Boilerplate:** Traditional runtime checkers force you to write imperative assertion calls (`Assert::positiveInteger($id)`) inside every function body or introduce custom attributes (`#[Validate]`). TypePHP removes all manual boilerplate by reading standard PHPDocs automatically.
4. **Boundary Testing in Pest & PHPUnit:** TypePHP physically verifies that your application boundaries withstand real-world dynamic data during local testing and CI/CD runs.

---

## Progressive Adoption (Not All-or-Nothing)

TypePHP does not force you into an "all-or-nothing" paradigm. You do not have to type-check your entire codebase or refactor legacy modules overnight. You can adopt TypePHP progressively at whatever granularity fits your project:

1. **Path-Level Whitelisting:** Use `include` patterns in `typephp.php` to target specific mission-critical domain modules (such as `app/Domain/Billing/**`) while completely bypassing legacy directories.
2. **Method-Level Suppression:** Add `@typephp-ignore` to specific legacy methods or un-refactored functions without removing their PHPDoc annotations.
3. **Category-Level Feature Toggles:** Granularly enable or disable specific check categories (`inline_vars.scalars`, `inline_vars.arrays`, `params`, `returns`, `magic_properties`, `magic_methods`) in `typephp.php` depending on performance or migration needs.

---

## Runtime Enforcement vs. Static Analysis (Partners, Not Replacements)

TypePHP is **not a replacement** for static analysis tools like PHPStan, Psalm, Mago, or Phan. They are complementary partners designed to work together:

* **Static Analysis (Compile-Time & IDE):** Lints your source code structure offline in your IDE and CI pipeline, catching static logic errors before your code ever runs.
* **TypePHP (Runtime & Execution):** Validates actual dynamic data in RAM during execution, ensuring that incoming API payloads, database results, and test suite inputs strictly satisfy your PHPDoc contracts.

> **Recommended Workflow:** Use PHPStan or Psalm in your IDE to lint code syntax, and use TypePHP during Pest/PHPUnit test runs and CI pipelines to guarantee runtime data integrity.

---

## Execution & Framework Entry Points

Because TypePHP automatically integrates with Composer's autoloader (`vendor/autoload.php`), you don't always need to use the custom CLI runner.

If your application executes through an explicit, standard entry point like a web framework's **`public/index.php`**, Laravel's **`artisan`** console, or test runners like **`vendor/bin/pest`** and **`phpunit`**, TypePHP boots naturally out of the box. 

Once booted, TypePHP transparently intercepts, transforms, and enforces types on any PHP file that is whitelisted in your `typephp.php` configuration file (`include` paths).

*(For standalone single-file scripts without an autoloader, you can still use `vendor/bin/typephp index.php` to run them with type checking enabled).*

---

## Namespace & Import Resolution

TypePHP is fully aware of your file's namespace context and `use` import statements. You can write your docblocks using short imported names, relative names, aliased imports, or Fully Qualified Class Names (FQCN), and TypePHP will resolve them perfectly at runtime:

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Billing as BillingService;

/**
 * @param User $user                       // Resolved via use import
 * @param BillingService\Invoice $invoice  // Resolved via aliased import
 * @param \DateTimeImmutable $date         // Resolved via FQCN
 */
function processPayment(object $user, object $invoice, object $date): void 
{
    // ...
}
```

---

## Parameter Contracts (`@param`)

Write standard PHPDoc annotations on function or method parameters:

```php
<?php

declare(strict_types=1);

/**
 * @param positive-int $id
 * @param non-empty-string $username
 */
function processUser(int $id, string $username): void
{
    // ...
}

// Valid Call
processUser(42, 'Alice');

// Invalid Call (Passing negative integer)
processUser(-5, 'Alice');
// Throws: TypeError: processUser(): Argument $id must be of type positive-int, negative int (-5) given
```

> **Execution Order Note:** Native PHP type hints (e.g., `int $id`, `string $username`) are evaluated by PHP's C-engine before function execution begins. TypePHP's extended DocBlock contracts (e.g., `positive-int`, `non-empty-string`) execute at function entry. If a native type hint fails, PHP throws its native `TypeError` before TypePHP guard rails execute.

---

## Return Contracts (`@return`)

TypePHP validates function return values before they are returned to the caller:

```php
/**
 * @return array{id: positive-int, status: 'active'|'pending'}
 */
function fetchUserData(int $id): array
{
    if ($id <= 0) {
        return ['id' => $id, 'status' => 'active']; // Invalid: $id is negative
    }

    return ['id' => $id, 'status' => 'active'];
}

fetchUserData(-10);
// Throws: TypeError: fetchUserData(): Return value['id'] must be of type positive-int
```

---

## Typed Arrays, Lists, and Shapes

Enforce strict structure on arrays, sequential lists, and key-value maps:

```php
/**
 * @param list<positive-int> $scores
 * @param array<string, non-empty-string> $headers
 */
function processBatch(array $scores, array $headers): void
{
    // ...
}

// Valid Call
processBatch([10, 20, 30], ['Authorization' => 'Bearer token']);

// Invalid Call (Associative array passed where sequential list was expected)
processBatch(['score' => 10], ['Authorization' => 'Bearer token']);
// Throws: TypeError: processBatch(): Argument $scores must be a list
```

---

## Inline Variable Validation (`@var`)

Validate local variable assignments inside function bodies:

```php
/** @var positive-int $age */
$age = 25; // Valid

$age = -10; 
// Throws: TypeError: Variable $age must be of type positive-int, negative int (-10) given
```

---

## Runtime Generics with `WeakMap`

TypePHP binds generic template types (`T`) directly to object instances:

```php
use TypePHP\Tests\Fixtures\Generics\Collection;
use App\Models\User;
use App\Models\Product;

/** @var Collection<User> $users */
$users = new Collection();

$users->add(new User('Alice')); // Valid

$users->add(new Product('SKU-100')); 
// Throws: TypeError: Argument $item (template T = User) must be of type User, Product given
```

---

## Class-Level Magic Annotations (`@property` & `@method`)

TypePHP validates dynamic property writes (`__set`) and dynamic method calls (`__call`) against class-level `@property` and `@method` annotations:

```php
/**
 * @phpstan-type StatusUnion 'active'|'pending'
 *
 * @property positive-int $score
 * @method bool updateStatus(StatusUnion $status)
 */
class DynamicModel
{
    private array $storage = [];

    public function __set(string $name, mixed $value): void
    {
        $this->storage[$name] = $value;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return true;
    }
}

$model = new DynamicModel();

// Invalid Dynamic Property Assignment ($score = -50 violates positive-int)
$model->score = -50;
// Throws: TypeError: Property DynamicModel::$score must be of type positive-int

// Invalid Dynamic Method Argument ($status = 'archived' violates StatusUnion)
$model->updateStatus('archived');
// Throws: TypeError: DynamicModel::updateStatus(): Argument $status must be of type ('active' | 'pending')
```

---

## PHP 8.4 Property Hooks & Asymmetric Visibility

TypePHP validates incoming and returned values on PHP 8.4 Property Hooks:

```php
class UserProfile
{
    /** @var positive-int */
    public private(set) int $id = 10;

    /** @var non-empty-string */
    public string $username {
        get => $this->_username;
        set => $this->_username = trim($value);
    }

    private string $_username = 'Alice';
}

$profile = new UserProfile();
$profile->username = '   '; 
// Throws: TypeError: Property UserProfile::$username must be of type non-empty-string
```

---

## Suppressing Type Checks (`@typephp-ignore` & `@typephp-ignore-file`)

TypePHP provides annotations to skip type enforcement on legacy code or performance-critical sections without removing docblock types.

### Function & Method Level Suppression

Add `@typephp-ignore` to a function or class method docblock to skip type-checking for that specific function:

```php
/**
 * @typephp-ignore
 * @param positive-int $id
 */
function legacyProcess(int $id): void
{
    // TypePHP skips type enforcement for this specific function
}

legacyProcess(-500); // Passes without error
```

### File-Level Suppression

Place `@typephp-ignore-file` in a file-level docblock at the top of a file:

```php
<?php

/**
 * @typephp-ignore-file
 */

declare(strict_types=1);

namespace App\Legacy;

// All functions, methods, and properties in this file are skipped by TypePHP
```

> **Technical Note & Coding Convention:**
> Under the hood, TypePHP scans the raw file contents for `@typephp-ignore-file` before performing AST transformations, meaning the tag will function regardless of its position in the file. However, you should always place `@typephp-ignore-file` at the very top of the file (right after `<?php`) as a clean coding convention.
