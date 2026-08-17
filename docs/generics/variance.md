# Demystifying Variance

Generic variance controls how subtype relationships between underlying types affect the generic container. If `Dog` is a subclass of `Animal`, what is the relationship between `Producer<Dog>` and `Producer<Animal>`?

---

## The Core Question of Variance

* If **`Dog extends Animal`**, does **`Container<Dog>` extend `Container<Animal>`**?

The answer depends on whether the container is **reading data (Producer)**, **writing data (Consumer)**, or **both (Read-Write)**:

```
                            THE 3 MODES OF GENERIC VARIANCE

   1. Invariance (Default)       2. Covariance (Producer)       3. Contravariance (Consumer)
  ┌─────────────────────────┐   ┌───────────────────────────┐   ┌───────────────────────────┐
  │   Exact Match ONLY      │   │   Subtypes Allowed (Dog)  │   │  Supertypes Allowed       │
  │   Read-Write Container  │   │   Read-Only Container     │   │  Write-Only Consumer      │
  └─────────────────────────┘   └───────────────────────────┘   └───────────────────────────┘
```

---

## 1. Invariance (Default / Read-Write Containers)

By default, generics in TypePHP (and PHPStan) are **invariant**. Invariance requires an **exact type match**.

```php
/** @template T */
class Box
{
    public function __construct(public mixed $item) {}
}

/**
 * @param Box<Animal> $box
 */
function checkBox(Box $box): void
{
    // ...
}

checkBox(new Box(new Animal())); // Valid
checkBox(new Box(new Dog()));    // Invalid in invariant mode!
// Throws: TypeError: Argument $box expects Box<invariant Animal>, but Box<Dog> was given
```

### Why Invariance is Mandatory for Read-Write Containers
If PHP allowed `Box<Dog>` to be passed into `checkBox(Box<Animal> $box)`:
```php
function checkBox(Box $box): void
{
    $box->item = new Cat(); // Valid for Box<Animal>, but corrupts Box<Dog>!
}
```
Putting a `Cat` into what the caller thought was a `Box<Dog>` would corrupt memory state! **Invariance completely prevents this bug.**

---

## 2. Covariance (`@template-covariant T` / Producer Mindset)

Covariance allows **subtypes** (`Dog` for `Animal`). Think of covariance as a **Producer / Read-Only** relationship.

If a function only *reads* from a container producing `Animal`s, passing a container producing `Dog`s is 100% safe because every `Dog` read out of the container is guaranteed to be an `Animal`!

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
 * @param Producer<Animal> $producer
 */
function handleProducer(Producer $producer): mixed
{
    return $producer->item;
}

// 1. Valid Call (Dog is a subtype of Animal)
handleProducer(new Producer(new Dog()));

// 2. Valid Call (Cat is a subtype of Animal)
handleProducer(new Producer(new Cat()));

// 3. Invalid Call (Car is not an Animal)
handleProducer(new Producer(new Car()));
// Throws: TypeError: handleProducer() expects Producer<covariant Animal>, but Producer<Car> was given
```

---

## 3. Contravariance (`@template-contravariant T` / Consumer Mindset)

Contravariance allows **supertypes** (`Animal` for `Dog`). Think of contravariance as a **Consumer / Write-Only** relationship.

If a function needs a handler that consumes a `Dog`, giving it a handler that can consume any general `Animal` is 100% safe because an `Animal` handler can process any `Dog` given to it!

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
 * @param Consumer<Dog> $consumer
 */
function processDogConsumer(Consumer $consumer, Dog $dog): void
{
    $consumer->consume($dog);
}

// 1. Valid: Animal handler can safely consume a Dog!
$animalHandler = fn (Animal $a) => null;
processDogConsumer(new Consumer($animalHandler), new Dog());

// 2. Invalid: Puppy handler cannot handle any general Dog!
$puppyHandler = fn (Puppy $p) => null;
processDogConsumer(new Consumer($puppyHandler), new Dog());
// Throws: TypeError: processDogConsumer() expects Consumer<contravariant Dog>, but Consumer<Puppy> was given
```

---

## Inline Usage-Site Variance Syntax

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

## Variance Precedence Rules (Usage-Site Overrides)

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
```

---

## Summary Matrix

| Variance Mode | Keyword / Syntax | Allowed Types | Mental Model |
| :--- | :--- | :--- | :--- |
| **Invariant** (Default) | `Collection<T>` | **Exact type only** | **Read-Write:** Prevents container corruption. |
| **Covariant** | `@template-covariant T`<br>`Box<covariant Animal>` | **Subtypes** (`Dog`, `Cat`) | **Producer:** Safe for reading data out. |
| **Contravariant** | `@template-contravariant T`<br>`Consumer<contravariant Dog>` | **Supertypes** (`Animal`) | **Consumer:** Safe for writing data in. |

