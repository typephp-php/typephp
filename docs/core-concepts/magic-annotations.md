# Magic Annotations (`@property` & `@method`)

Dynamic properties and magic methods are widely used across modern PHP frameworks (such as Laravel Eloquent models, DTOs, and dynamic service repositories). TypePHP provides transparent, runtime enforcement for class-level `@property`, `@property-read`, `@property-write`, and `@method` annotations.

---

## Class-Level Magic Properties (`@property`, `@property-read`, `@property-write`)

When a property does not physically exist on a class, PHP routes property writes through `__set()`. TypePHP intercepts these dynamic assignments and validates incoming values against class-level `@property`, `@property-read`, and `@property-write` annotations declared on the class, parent classes, interfaces, or traits:

```php
<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * @property positive-int $score
 * @property-write non-empty-string $username
 * @property-read list<string> $tags
 */
class UserDTO
{
    private array $storage = [];

    public function __set(string $name, mixed $value): void
    {
        $this->storage[$name] = $value;
    }

    public function __get(string $name): mixed
    {
        return $this->storage[$name] ?? null;
    }
}

$user = new UserDTO();

// Valid dynamic property assignment
$user->score = 100;
$user->username = 'Alice';

// Invalid dynamic property assignment ($score = -50 violates positive-int)
$user->score = -50;
// Throws: TypeError: Property UserDTO::$score must be of type positive-int, negative int (-50) given
```

> **Read/Write Mechanics:** Assigning to a `@property-write` or `@property-read` annotation will validate the incoming value against the declared type constraint.

---

## Class-Level Magic Methods (`@method`)

When a method is called dynamically via `__call()` or `__callStatic()`, TypePHP intercepts the invocation and validates both incoming arguments and returned values against class-level `@method` annotations:

```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * @phpstan-type StatusUnion 'active'|'pending'
 *
 * @method positive-int processOrder(positive-int $id, non-empty-string $sku)
 * @method static list<int> fetchBatch(int ...$ids)
 * @method bool updateStatus(StatusUnion $status)
 */
class OrderService
{
    public function __call(string $name, array $arguments): mixed
    {
        return $arguments[0] ?? null;
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        return $arguments;
    }
}

$service = new OrderService();

// Valid Dynamic Call
$service->processOrder(42, 'SKU-99');

// Invalid Argument ($id = -5 violates positive-int)
$service->processOrder(-5, 'SKU-99');
// Throws: TypeError: OrderService::processOrder(): Argument $id must be of type positive-int

// Invalid Static Variadic Argument ('invalid' violates int)
OrderService::fetchBatch(1, 2, 'invalid');
// Throws: TypeError: OrderService::fetchBatch(): Argument $ids[2] must be of type int
```

---

## DocBlock Inheritance for Magic Annotations

Child classes automatically inherit magic property and method annotations declared across their entire object hierarchy:

* **Parent Classes:** A child class extending a parent inherits all parent `@property` and `@method` annotations.
* **Interfaces:** A class implementing an interface inherits magic annotations declared on the interface.
* **Traits:** A class using a trait inherits all magic annotations declared on the trait.
* **Overriding:** If a child class redeclares an `@property` or `@method` annotation, the child's annotation takes precedence.

---

## Best Practice: Quoted Literals in `@method` Signatures

`phpdoc-parser`'s grammar for `@method` parameter signatures can encounter ambiguity when parsing unparenthesized single quotes directly inside parameter types (such as `@method bool setStatus('active'|'pending' $status)`). When `phpdoc-parser` encounters this grammar ambiguity, it drops that specific `@method` tag.

**Recommended Best Practice:** Define complex union string literals or array shapes using a local `@phpstan-type` alias, and reference the alias in your `@method` annotation:

```php
/**
 * Recommended: Clean & Grammar-Safe via @phpstan-type
 *
 * @phpstan-type StatusUnion 'active'|'pending'
 *
 * @method bool setStatus(StatusUnion $status)
 */
class OrderService
{
    public function __call(string $name, array $arguments) { ... }
}
```

---

## Configuration Toggles

Magic property and magic method validations are enabled by default. You can fine-tune or disable them in your `typephp.php` configuration file:

```php
// typephp.php
return [
    /*
    |--------------------------------------------------------------------------
    | Magic Annotations (@property & @method)
    |--------------------------------------------------------------------------
    */
    'magic_properties' => true, // Set to false to disable dynamic @property checks
    'magic_methods'    => true, // Set to false to disable dynamic @method checks
];
```