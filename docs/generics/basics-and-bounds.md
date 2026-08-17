# Generics Basics & Bounds

Generics parameterize classes, interfaces, and functions, allowing you to define reusable containers and algorithms that strictly enforce specific types at runtime.

---

## What Are Generics and Why Do You Need Them?

Without generics, a container class (like a `Collection` or `List`) or a wrapper service (like a `Repository` or `Response`) can only accept or return un-typed `mixed` or generic `object`:

```php
// Without Generics:
$users = new Collection();
$users->add(new User('Alice'));
$users->add(new Product('SKU-100')); // Accidental bug! Collection holds mixed types.
```

To prevent bugs without generics, you would have to write repetitive runtime type checks (`if (!$item instanceof User) throw ...`) inside every loop or method body.

**Generics solve this problem.** Generics allow you to pass a type parameter (like `<User>`) into a class or function signature. It parameterizes the container, telling PHP: *"This specific Collection instance holds ONLY User objects."*

```php
// With Generics:
/** @var Collection<User> $users */
$users = new Collection();
$users->add(new User('Alice')); // Valid
$users->add(new Product('SKU-100')); // TypePHP blocks this instantly at runtime!
```

---

## How Runtime Generics Work

TypePHP manages generic templates at two distinct execution levels:

1. **Function-Level Templates:** Bound per call site (e.g., `collectSameType(...$items)`).
2. **Class-Level Templates:** Bound to specific object instances in memory using PHP's native `WeakMap` (e.g., `Collection<User>`).

---

## PHP Limitation: No Native `instanceof` on Generics

In PHP, writing generic syntax directly in executable statements (such as `if ($obj instanceof Collection<User>)`) is a syntax error. 

In TypePHP, you declare generics in standard PHPDoc annotations (`@param Collection<User> $users` or `/** @var Collection<User> $users */`). TypePHP's AST engine intercepts these annotations and enforces generic contracts at runtime without altering native PHP syntax rules.

---

## Basic Template Annotations (`@template T`)

When a function uses `@template T` across multiple parameters, TypePHP infers `T` from the first argument and enforces consistency across all subsequent arguments:

```php
<?php

declare(strict_types=1);

/**
 * @template T
 *
 * @param T ...$items
 * @return list<T>
 */
function collectSameType(mixed ...$items): array
{
    return $items;
}

// Valid Call (Infers T = int)
collectSameType(10, 20, 30);

// Invalid Call (Infers T = int from item #1, then item #3 'invalid' fails T = int)
collectSameType(10, 20, 'invalid');
// Throws: TypeError: collectSameType(): Argument $items[2] (template T = int) must be of type int
```

---

## Multiple Generic Templates (`@template T`, `@template U`)

Functions and classes are not limited to a single template parameter. You can declare multiple independent generic templates (such as `T`, `U`, `K`, `V`):

```php
/**
 * @template T of Animal
 * @template U of Car
 *
 * @param T $animal
 * @param U $car
 */
function pairUp(Animal $animal, Car $car): void
{
    // T is bound to Animal subtype, U is bound to Car subtype
}

// Valid Call (T = Dog, U = SportsCar)
pairUp(new Dog(), new SportsCar());

// Invalid Call (Swapped arguments: T receives Car, U receives Dog)
pairUp(new Car(), new Dog());
// Throws: TypeError: pairUp(): Argument $animal (template T) must be an instance of Animal, Car given
```

---

## Generic Template Upper Bounds (`@template T of Bound`)

Use `@template T of Bound` to restrict template arguments to a specific class hierarchy, interface, or scalar range:

```php
abstract class Animal {}
class Dog extends Animal {}
class Car {} // Not an Animal!

/**
 * @template T of Animal
 *
 * @param T $animal
 * @return T
 */
function processAnimal(Animal $animal): Animal
{
    return $animal;
}

// Valid Call (Dog extends Animal)
processAnimal(new Dog());

// Invalid Call (Car does not extend Animal)
processAnimal(new Car());
// Throws: TypeError: processAnimal(): Argument $animal (template T) must be an instance of Animal, Car given
```

---

## Default Generic Templates (`@template T = DefaultType`)

If a template parameter `T` cannot be inferred from function arguments, TypePHP uses the declared default type:

```php
/**
 * @template T = string
 *
 * @param mixed $value
 * @return T
 */
function getDefaultValue(mixed $value): mixed
{
    return $value;
}

// Valid Call (Unbound T falls back to default: string)
getDefaultValue('valid_string');

// Invalid Call (Return value violates default string type)
getDefaultValue(12345);
// Throws: TypeError: getDefaultValue(): Return value must be of type string
```

---

## Upper Bounds with Defaults (`@template T of Bound = DefaultType`)

Combine upper bounds with defaults (`@template T of Bound = DefaultType`) to enforce class inheritance while providing a fallback type:

```php
/**
 * @template T of object = stdClass
 *
 * @param mixed $value
 * @return T
 */
function getObjectInstance(mixed $value): mixed
{
    return $value;
}

// Valid Call (stdClass satisfies default stdClass)
getObjectInstance(new stdClass());

// Invalid Call (Dog is an object, but violates default stdClass)
getObjectInstance(new Dog());
// Throws: TypeError: getObjectInstance(): Return value must be an instance of stdClass
```

---

## Generics of Scalars, Refinements, and Array Shapes

Generic parameters (`T`) in TypePHP are not limited to object classes. You can bind generics to refined scalar types (`positive-int`, `non-empty-string`) or complex array shapes (`array{id: positive-int}`):

### Generic Collections of Refined Scalars (`Collection<positive-int>`)

```php
/** @var Collection<positive-int> $scores */
$scores = new Collection();

$scores->add(100); // Valid

$scores->add(-50); 
// Throws: TypeError: Collection::add(): Argument $item (template T = positive-int) must be of type positive-int
```

### Generic Collections of Array Shapes (`Collection<array{...}>`)

```php
/** @var Collection<array{id: positive-int, name: non-empty-string}> $userShapes */
$userShapes = new Collection();

$userShapes->add(['id' => 1, 'name' => 'Alice']); // Valid

$userShapes->add(['id' => -5, 'name' => 'Alice']); 
// Throws: TypeError: Collection::add(): Argument $item['id'] must be of type positive-int
```

---

## First-Use Type Inference (Unannotated Generic Instances)

If you instantiate a generic class without an inline `@var` prebinding annotation:

```php
$collection = new Collection(); // No @var Collection<User> annotation!
```

TypePHP automatically infers the template parameter `T` from the **first method call** executed on that object instance and locks `T` to that type in `WeakMap` memory for all subsequent calls:

```php
// 1. First method call infers T = User and locks T to User for this instance!
$collection->add(new User('Alice'));

// 2. Subsequent call succeeds because argument is a User
$collection->add(new User('Bob'));

// 3. Subsequent call fails because T was locked to User on first use!
$collection->add(new Product('SKU-100'));
// Throws: TypeError: Collection::add(): Argument $item (template T = User) must be of type User, Product given
```

---

## Simultaneous First-Use Multi-Template Inference

If you instantiate a multi-template class without an inline `@var` annotation:

```php
/**
 * @template K of array-key = string
 * @template V = int
 */
class MultiTemplateBag
{
    private array $storage = [];

    /**
     * @param K $key
     * @param V $val
     */
    public function set(mixed $key, mixed $val): void
    {
        $this->storage[$key] = $val;
    }
}
```

1. **Simultaneous Inference:** The very first method call (e.g. `$bag->set('timeout', 30)`) infers and locks **all active template parameters simultaneously** (`K = string`, `V = int`) in `WeakMap` memory.
2. **Lock-In:** All subsequent method calls on that instance enforce both locked types:

```php
$bag = new MultiTemplateBag();

// 1. First method call infers K = string and V = int simultaneously
$bag->set('max_retries', 5);

// 2. Subsequent call matching K = string and V = int succeeds
$bag->set('timeout', 30); // Valid

// 3. Subsequent call violating locked K = string fails
$bag->set(12345, 30);
// Throws: TypeError: MultiTemplateBag::set(): Argument $key (template K = string) must be of type string

// 4. Subsequent call violating locked V = int fails
$bag->set('timeout', 'thirty');
// Throws: TypeError: MultiTemplateBag::set(): Argument $val (template V = int) must be of type int
```

