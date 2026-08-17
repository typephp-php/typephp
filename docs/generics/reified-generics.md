# Reified Generics & State Management

Unlike languages that erase types at compile-time (like Java, TypeScript, or standard Python), TypePHP provides **Reified Generics**—preserving generic parameters in memory per object instance throughout the entire application lifecycle.

---

## What Are Reified Generics?

* **Type Erasure (Java / TypeScript):** Generic type parameters like `<User>` are erased during compilation. At runtime, the container becomes an un-typed `Object` or raw JavaScript array. You cannot inspect an object's generic parameters in live memory.
* **Reified Generics (C# / TypePHP):** Generic type parameters are preserved in live memory. Each instance knows its own bound type (`T = User`), allowing both runtime boundary enforcement and runtime reflection inspection.

```
                           THE TYPEPHP WEAKMAP ARCHITECTURE

   Class Definition                     Inline Pre-Binding             Object Instance in RAM (WeakMap)
 ┌───────────────────────────┐    ┌───────────────────────────┐    ┌─────────────────────────────────┐
 │ class Collection { ... }  │ ──►│ /** @var Collection<User> │ ──►│ Object #142 ──► ['T' => 'User'] │
 └───────────────────────────┘    └───────────────────────────┘    └─────────────────────────────────┘
  (0 Base Classes / 0 Metas)       (0 New Keywords)                 (0 Memory Leaks, Inspectable)
```

---

## The Public Inspection API

You can inspect an object's bound generic types and declared variances at runtime using TypePHP's public static methods:

```php
use TypePHP\TypePHP;
use App\Models\User;
use App\Models\Product;
use App\Collections\Collection;
use App\Collections\Dictionary;

/** @var Collection<User> $users */
$users = new Collection();

/** @var Dictionary<string, Product> $catalog */
$catalog = new Dictionary();

// 1. Single-Template Smart Fallback (No template name needed!)
$userType = TypePHP::getGenericType(object: $users); 
// Returns: 'App\Models\User'

// 2. Multi-Template Explicit Inspection
$keyType   = TypePHP::getGenericType(object: $catalog, template: 'K'); // Returns: 'string'
$valueType = TypePHP::getGenericType(object: $catalog, template: 'V'); // Returns: 'App\Models\Product'

// 3. Inspect All Bound Template Parameters as an Array
$types = TypePHP::getGenericTypes(object: $catalog); 
// Returns: ['K' => 'string', 'V' => 'App\Models\Product']

// 4. Inherited Generic Classes (@extends BaseRepository<User>)
$userRepo = new UserRepository();
$repoType = TypePHP::getGenericType(object: $userRepo); 
// Returns: 'App\Models\User'

// 5. Inspect Declared Variance ('covariant', 'contravariant', or 'invariant')
$variance = TypePHP::getGenericVariance(object: $producer); 
// Returns: 'covariant'

// 6. Inspect All Bound Variances as an Array
$variances = TypePHP::getGenericVariances(object: $producer); 
// Returns: ['T' => 'covariant']
```

### How Reified Generic Inspection Works

* **Single-Template Smart Fallback:** If a class has only 1 template parameter (e.g. `@template ItemType`), `TypePHP::getGenericType($object)` automatically returns that template's bound type without requiring you to guess whether the author named it `T`, `E`, or `ItemType`.
* **Inherited Template Resolution:** Automatically resolves generic types declared on parent classes (`@extends BaseRepository<User>`) or interfaces (`@implements ProcessorInterface<Cat>`).
* **First-Use Inference:** On un-annotated generic instances (`$collection = new Collection()`), `getGenericType()` returns `null` before first use, and returns the inferred type (e.g. `User`) immediately after the first method call.

---

## Memory Management via `\WeakMap` (Zero Memory Leaks)

TypePHP manages generic state using PHP's native `\WeakMap`:

1. **Weak References:** In `\WeakMap`, objects serve as keys. 
2. **Automatic Garbage Collection:** The exact millisecond an object instance is unset or goes out of scope, PHP's garbage collector automatically deletes its generic type bindings from RAM.
3. **Zero Subclass Explosion:** TypePHP never creates dynamic subclasses or modifies class prototypes in memory.

---

## Cloning Generic Instances (`clone $obj` and `__clone()`)

When you clone an object instance that has bound generic templates (`$cloned = clone $original`), TypePHP automatically copies all bound generic parameters (`T`) to the new cloned instance in `WeakMap` memory:

```php
/** @var Collection<User> $users */
$users = new Collection();
$users->add(new User('Alice'));

// Clone the generic collection
$clonedUsers = clone $users;

// The cloned collection retains T = User!
$clonedUsers->add(new User('Bob')); // Valid

$clonedUsers->add(new Product('SKU-100')); 
// Throws: TypeError: Collection::add(): Argument $item (template T = User) must be of type User
```

### Explicit `__clone()` Magic Methods

If a generic class defines an explicit `__clone()` magic method, TypePHP copies the generic template bindings to the new object instance **before** the `__clone()` method body executes.

This ensures that any property assignments or method calls inside your `__clone()` implementation are immediately protected by the bound generic types:

```php
/**
 * @template T
 */
class GenericBox
{
    /** @var T */
    public mixed $item = null;

    /** @param T $item */
    public function set(mixed $item): void
    {
        $this->item = $item;
    }

    public function __clone(): void
    {
        // TypePHP pre-copies T = Dog before __clone() runs!
        $this->item = new Dog(); // Valid (Dog satisfies T = Dog)
    }
}

/** @var GenericBox<Dog> $box */
$box = new GenericBox();
$clonedBox = clone $box;

$clonedBox->set(new Car());
// Throws: TypeError: GenericBox::set(): Argument $item (template T = Dog) must be of type Dog
```

### Cloned Instance Memory Isolation (`WeakMap`)

When an object is cloned, its generic bindings are copied by value to the new instance. Because TypePHP uses `\WeakMap` keyed by object instance ID, **the original and cloned instances are 100% isolated in memory**.

Re-binding or modifying the generic type on a cloned instance will never affect the original instance:

```php
/** @var GenericBox<Dog> $box1 */
$box1 = new GenericBox();

// Clone $box1 into $box2
$box2 = clone $box1;

// Re-bind $box2 instance to GenericBox<Cat>
/** @var GenericBox<Cat> $box2 */

// $box2 now accepts Cat
$box2->set(new Cat()); // Valid for $box2

// $box1 continues enforcing T = Dog and rejects Cat!
$box1->set(new Cat());
// Throws: TypeError: GenericBox::set(): Argument $item (template T = Dog) must be of type Dog
```

### Variance Enforcement on Cloned Assignments

When you assign a cloned generic instance to a variable with an inline `@var` annotation, TypePHP enforces generic variance rules during the assignment.

Because generics are **invariant** by default, assigning a cloned `GenericBox<Dog>` instance into a variable annotated as `GenericBox<Cat>` throws an invariant type mismatch `TypeError`:

```php
/** @var GenericBox<Dog> $box1 */
$box1 = new GenericBox();

// Assigning a GenericBox<Dog> clone into a GenericBox<Cat> variable
/** @var GenericBox<Cat> $box2 */
$box2 = clone $box1;
// Throws: TypeError: Variable $box2 expects GenericBox<invariant Cat>, but GenericBox<Dog> was given
```

---

## Real-World Example 1: Generic Collections (`Collection<T>`)

Prebind a generic collection using an inline `@var` annotation:

```php
namespace App\Collections;

use App\Models\User;
use App\Models\Product;

/**
 * @template T
 */
class Collection
{
    /** @var array<int, T> */
    private array $items = [];

    /**
     * @param T $item
     */
    public function add(mixed $item): static
    {
        $this->items[] = $item;
        return $this;
    }

    /**
     * @return array<int, T>
     */
    public function toArray(): array
    {
        return $this->items;
    }
}

// Prebind T = User to the $users object instance in WeakMap memory
/** @var Collection<User> $users */
$users = new Collection();

// Valid Addition
$users->add(new User('Alice'));

// Invalid Addition (Product is not a User)
$users->add(new Product('SKU-999'));
// Throws: TypeError: Collection::add(): Argument $item (template T = User) must be of type User, Product given
```

---

## Real-World Example 2: `class-string<T>` Factories

Use `class-string<T>` to bind template `T` from a class name string and enforce matching return types:

```php
abstract class Animal {}
class Dog extends Animal {}
class Car {} // Not an Animal!

/**
 * Generic Factory Function
 *
 * @template T of Animal
 *
 * @param class-string<T> $class
 * @return T
 */
function makeAnimal(string $class): Animal
{
    return new $class();
}

// Valid Call (Dog extends Animal, binds T = Dog)
$dog = makeAnimal(Dog::class);

// Invalid Call (Car does not extend Animal)
makeAnimal(Car::class);
// Throws: TypeError: makeAnimal(): Argument $class (class-string<T>) must be a class-string of Animal, 'Car' given
```
