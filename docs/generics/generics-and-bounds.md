# Generics and Bounds

## What Are Generics and Why Do You Need Them?

Without generics, a container class (like a `Collection` or `List`) or a wrapper class (like a `Repository` or `Response`) can only accept or return `mixed` or `object`:

```php
// Without Generics:
$users = new Collection();
$users->add(new User('Alice'));
$users->add(new Product('SKU-100')); // Accidental bug! Collection holds mixed types.
```

To prevent bugs without generics, you would have to write repetitive runtime type checks (`if (!$item instanceof User) throw ...`) inside every loop or method.

**Generics solve this problem.** Generics allow you to pass a type parameter (like `<User>`) into a class or function signature. It parameterizes the container, telling PHP: *"This specific Collection instance holds ONLY User objects."*

```php
// With Generics:
/** @var Collection<User> $users */
$users = new Collection();
$users->add(new User('Alice')); // Valid
$users->add(new Product('SKU-100')); // TypePHP blocks this instantly at runtime!
```

PHP natively does not possess built-in generic syntax in executable statements (unlike languages like C# or Java). Modern PHP relies on PHPDoc annotations (`@template T`). TypePHP reads those PHPDoc annotations and actively enforces them during actual runtime execution!

---

## How Runtime Generics Work

TypePHP manages generic templates at two distinct execution levels:

1. **Function-Level Templates:** Bound per call site (e.g., `collectSameType(...$items)`).
2. **Class-Level Templates:** Bound to specific object instances in memory using PHP's native `WeakMap` (e.g., `Collection<User>`).

---

## PHP Limitation: No Native `instanceof` on Generics

In PHP, writing generic syntax directly in executable statements (such as `if ($obj instanceof Collection<User>)`) is a PHP syntax error. 

In TypePHP, you declare generics exclusively in PHPDoc annotations (`@param Collection<User> $users` or `/** @var Collection<User> $users */`). TypePHP's AST engine intercepts these annotations and enforces generic contracts at runtime without altering native PHP syntax rules.

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

## Reified Generics API (Kind of)

Unlike languages that use Type Erasure (such as TypeScript or Java), TypePHP maintains generic template parameters in memory. 

You can inspect an object's bound generic types at runtime using `TypePHP::getGenericType()` or `TypePHP::getGenericTypes()`:

```php
use TypePHP\TypePHP;

/** @var Collection<User> $users */
$users = new Collection();

/** @var Dictionary<string, Product> $catalog */
$catalog = new Dictionary();

//  Single-Template Smart Fallback (No template name needed!)
$userType = TypePHP::getGenericType(object: $users); // Returns 'App\Models\User'

//  Multi-Template Explicit Inspection
$keyType   = TypePHP::getGenericType(object: $catalog, template: 'K'); // Returns 'string'
$valueType = TypePHP::getGenericType(object: $catalog, template: 'V'); // Returns 'App\Models\Product'

// Inherited Generic Classes (@extends BaseRepository<User>)
$userRepo = new UserRepository();
$repoType = TypePHP::getGenericType(object: $userRepo); // Returns 'App\Models\User'

//  Inspect all bound template parameters as an array
$types = TypePHP::getGenericTypes(object: $catalog); // Returns ['K' => 'string', 'V' => 'App\Models\Product']

//  Inspect Declared Variance ('covariant', 'contravariant', or 'invariant')
$variance = TypePHP::getGenericVariance(object: $producer); // Returns 'covariant'

//  Inspect All Bound Variances as Arrays
$variances = TypePHP::getGenericVariances(object: $producer); // Returns ['T' => 'covariant']
```

### How Reified Generic Inspection Works

* **Single-Template Smart Fallback:** If a class has only 1 template parameter (e.g. `@template ItemType`), `TypePHP::getGenericType($object)` automatically returns that template's bound type without requiring you to guess whether the author named it `T`, `E`, or `ItemType`.
* **Inherited Template Resolution:** Automatically resolves generic types declared on parent classes (`@extends BaseRepository<User>`) or interfaces (`@implements ProcessorInterface<Cat>`).
* **First-Use Inference:** On un-annotated generic instances (`$collection = new Collection()`), `getGenericType()` returns `null` before first use, and returns the inferred type (e.g. `User`) immediately after the first method call!

---

## Cloning Generic Instances (`clone $obj` and `__clone()`)

When you clone an object instance that has bound generic templates (`$cloned = clone $original`), TypePHP automatically preserves and copies all bound generic template parameters (`T`) to the new cloned object instance in `WeakMap` memory:

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

> **Deep Dive Guide:** For complete details on how covariance, contravariance, and invariance rules work across generic containers, see the [Demystifying Variance](#demystifying-variance-covariant-contravariant-invariant) section below.

---

## Generics of Scalars, Refinements, and Array Shapes, Etc..

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

For full reference guides on all supported scalar refinements and array shape structures, see [Primitives & Scalars](/supported-types/primitives-and-scalars) and [Arrays & Shapes](/supported-types/arrays-and-shapes).


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

## Generics with Unions and Intersections

TypePHP fully supports combining generic structures with Union (`|`) and Intersection (`&`) types:

> **Deep Dive Guide:** For complete syntax rules, parenthesized intersections, and disjunctive normal forms, see the dedicated [Unions, Intersections & Conditionals](/supported-types/unions-intersections-and-conditionals) guide.

### Generic Containers Holding Unions (`Collection<Dog|Cat>`)

Allow a generic container to hold multiple types specified in a union:

```php
/** @var Collection<Dog|Cat> $animals */
$animals = new Collection();

$animals->add(new Dog()); // Valid
$animals->add(new Cat()); // Valid

$animals->add(new Car()); // Invalid: Car is neither Dog nor Cat
// Throws: TypeError: Collection::add(): Argument $item (template T = Dog|Cat) must be of type (Dog | Cat)
```

### Unions of Generic Containers (`Producer<Dog> | Producer<Cat>`)

Accept a union of separate generic container instances:

```php
/**
 * @param Producer<Dog>|Producer<Cat> $producer
 */
function handleAnimalProducer(Producer $producer): void
{
    // ...
}

handleAnimalProducer(new Producer(new Dog())); // Valid
handleAnimalProducer(new Producer(new Cat())); // Valid

handleAnimalProducer(new Producer(new Car())); // Invalid
// Throws: TypeError: Argument $producer must be of type Producer<Dog>|Producer<Cat>
```

### Generic Containers Holding Intersections (`Collection<Countable & ArrayAccess>`)

Enforce that generic items must implement multiple interfaces simultaneously:

```php
/** @var Collection<Countable&ArrayAccess> $collections */
$collections = new Collection();

$collections->add(new CountableArrayAccess()); // Valid (Implements both)

$collections->add(new CountableOnly()); // Invalid (Fails ArrayAccess interface)
// Throws: TypeError: Argument $item must be of type Countable&ArrayAccess
```

### Complex Unions of Intersections in Generics

You can combine parenthesized unions and intersections inside generic parameters:

```php
/** @var Collection<(Countable&ArrayAccess)|(Iterator&Countable)> $payload */
$payload = new Collection();

$payload->add(new CountableArrayAccess()); // Valid
$payload->add(new ArrayIterator([1, 2]));  // Valid
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

## Generic Template Bounds (`@template T of Bound`)

Use `@template T of Bound` to restrict template arguments to a specific class, interface, or scalar range:

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

## Nested Generics (`Collection<Producer<Dog>>`)

TypePHP recursively evaluates deeply nested generic structures:

```php
/** @var Collection<Producer<Dog>> $producers */
$producers = new Collection();

// Valid Addition
$producers->add(new Producer(new Dog()));

// Invalid Addition (Producer holding Car instead of Dog)
$producers->add(new Producer(new Car()));
// Throws: TypeError: Argument $item must be an instance of Producer<Dog>
```

---

## Class Inheritance (`@extends` and `@implements`)

When a child class extends a generic parent class or implements a generic interface, declare the template mapping using `@extends` or `@implements` (also recognized as `@template-extends` and `@template-implements`):

```php
/**
 * Generic Interface
 *
 * @template T
 */
interface ProcessorInterface
{
    /**
     * @param T $item
     * @return T
     */
    public function process(mixed $item): mixed;
}

/**
 * Fulfills T = Cat via @implements
 *
 * @implements ProcessorInterface<Cat>
 */
class CatProcessor implements ProcessorInterface
{
    public function process(mixed $item): mixed
    {
        return $item;
    }
}

$processor = new CatProcessor();

// Valid Call
$processor->process(new Cat());

// Invalid Call
$processor->process(new Dog());
// Throws: TypeError: CatProcessor::process(): Argument $item (template T = Cat) must be of type Cat
```

---

## Real-World Example 1: Generic Collections (`Collection<T>`)

TypePHP binds template types to object instances using `\WeakMap`. Prebind a generic collection using an inline `@var` annotation:

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

## Real-World Example 2: Generic Repositories (`Repository<T>`)

When a class extends a generic parent class (`@extends BaseRepository<User>`), TypePHP automatically resolves and inherits the parent's generic template bindings:

```php
namespace App\Repositories;

use App\Models\User;

/**
 * @template T
 */
abstract class BaseRepository
{
    /**
     * @param T $entity
     */
    public function save(mixed $entity): void
    {
        // ...
    }
}

/**
 * Fulfills T = User via @extends
 *
 * @extends BaseRepository<User>
 */
class UserRepository extends BaseRepository
{
}

$userRepo = new UserRepository();

// Valid Save
$userRepo->save(new User('Alice'));

// Invalid Save
$userRepo->save(new Product('SKU-100'));
// Throws: TypeError: UserRepository::save(): Argument $entity (template T = User) must be of type User
```

---

## Real-World Example 3: `class-string<T>` Factories

Use `class-string<T>` to bind template `T` from a class name string and enforce matching return types:

```php
/**
 * Generic Factory Function
 *
 * @template T of object
 *
 * @param class-string<T> $class
 * @return T
 */
function makeInstance(string $class): object
{
    return new $class();
}

// Valid Call (Returns Dog instance matching class-string<Dog>)
$dog = makeInstance(Dog::class);
```

---

## Demystifying Variance (`covariant`, `contravariant`, `invariant`)

Generic variance controls how subtype relationships between underlying types affect the generic container. If `Dog` is a subclass of `Animal`, how does `Producer<Dog>` relate to `Producer<Animal>`?

### Inline Variance Syntax

In addition to class-level declarations (`@template-covariant T` / `@template-contravariant T`), TypePHP supports declaring variance inline directly on function parameter and return type hints:

```php
/**
 * @param Repository<covariant Animal> $repo
 * @param Consumer<contravariant Dog> $consumer
 * @return Producer<covariant Animal>
 */
function processContracts(Repository $repo, Consumer $consumer): Producer
{
    // ...
}
```

---

### 1. Invariance (Default / Read-Write)

By default, generics in TypePHP (and PHPStan) are **invariant**. Invariance requires an **exact type match**.

```php
/** @template T */
class Box { public function __construct(public mixed $item) {} }

/** @param Box<Animal> $box */
function checkBox(Box $box): void {}

checkBox(new Box(new Animal())); // Valid
checkBox(new Box(new Dog()));    // Invalid in invariant mode!
```

**Why?** If `checkBox` modifies `$box->item = new Cat()`, putting a `Cat` into a `Box<Dog>` would corrupt the box! Invariance prevents this.

---

### 2. Covariance (`@template-covariant T` / Producer Mindset)

Covariance allows **subtypes** (`Dog` for `Animal`). Think of covariance as a **Producer / Read-Only** relationship.

If a function only *reads* from a container producing `Animal`s, passing a container producing `Dog`s is completely safe because every `Dog` read out of the container is guaranteed to be an `Animal`!

```php
/**
 * @template-covariant T
 */
class Producer
{
    public function __construct(public mixed $item) {}
}

/**
 * Accepts Producer holding Animal or any subtype of Animal (Dog, Cat)
 *
 * @param Producer<covariant Animal> $producer
 */
function handleProducer(Producer $producer): mixed
{
    return $producer->item;
}

// Valid (Dog is a subtype of Animal)
handleProducer(new Producer(new Dog()));

// Invalid (Car is not an Animal)
handleProducer(new Producer(new Car()));
// Throws: TypeError: handleProducer() expects Producer<covariant Animal>, but Producer<Car> was given
```

---

### 3. Contravariance (`@template-contravariant T` / Consumer Mindset)

Contravariance allows **supertypes** (`Animal` for `Dog`). Think of contravariance as a **Consumer / Write-Only** relationship.

If a function needs a handler that consumes a `Dog`, giving it a handler that can consume any `Animal` is completely safe because an `Animal` handler can process any `Dog` given to it!

```php
class Puppy extends Dog {}

/**
 * @template-contravariant T
 */
class Consumer
{
    /**
     * @param callable(T): void $handler
     */
    public function __construct(public mixed $handler) {}

    /**
     * @param T $item
     */
    public function consume(mixed $item): void
    {
        ($this->handler)($item);
    }
}

/**
 * Accepts Consumer designed for Dog or any supertype of Dog (Animal)
 *
 * @param Consumer<contravariant Dog> $consumer
 */
function processDogConsumer(Consumer $consumer, Dog $dog): void
{
    $consumer->consume($dog);
}

// Valid: Animal handler can safely consume a Dog!
$animalHandler = fn (Animal $a) => null;
processDogConsumer(new Consumer($animalHandler), new Dog());

// Invalid: Puppy handler cannot handle any general Dog!
$puppyHandler = fn (Puppy $p) => null;
processDogConsumer(new Consumer($puppyHandler), new Dog());
// Throws: TypeError: processDogConsumer() expects Consumer<contravariant Dog>, but Consumer<Puppy> was given
```

### Variance Precedence Rules

What happens if an inline type hint specifies `Consumer<covariant Animal>`, but the class definition declared `@template-contravariant T`?

TypePHP resolves variance conflicts using **Usage-Site Precedence**:

1. **Usage-Site Override:** If a function parameter or return type explicitly specifies an inline variance modifier (`covariant` or `contravariant`), **the usage-site modifier takes precedence**.
2. **Class-Level Fallback:** If the call site uses standard syntax (`Consumer<Animal>`), TypePHP falls back to the class's declared `@template-covariant` or `@template-contravariant` rule.

```php
/**
 * Class declares Contravariant T (Default: Consumer / Supertypes)
 *
 * @template-contravariant T
 */
class Consumer
{
    public function __construct(public mixed $handler) {}
}

/**
 * Function parameter EXPLICITLY overrides with inline 'covariant Animal'
 *
 * @param Consumer<covariant Animal> $consumer
 */
function processCovariantConsumer(Consumer $consumer): mixed
{
    return $consumer->handler;
}

// 1. Valid Call (Dog is a subtype of Animal)
// Class declared contravariant, BUT function parameter explicitly specified 'covariant'.
// Usage-site 'covariant' wins!
processCovariantConsumer(new Consumer(new Dog()));

// 2. Invalid Call (Car is not an Animal)
processCovariantConsumer(new Consumer(new Car()));
// Throws: TypeError: processCovariantConsumer() expects Consumer<covariant Animal>, but Consumer<Car> was given