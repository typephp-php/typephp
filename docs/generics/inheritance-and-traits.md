# Class, Interface & Trait Inheritance

TypePHP resolves and inherits generic contracts across parent classes, interfaces, and traits, ensuring subtype implementations strictly adhere to parameterized type contracts at runtime.

---

## Tooling Template Priority Hierarchy (`@phpstan-template-*` > `@psalm-template-*` > `@template-*`)

| Priority Tier | Scope | Evaluation Order (Highest → Lowest) |
| :--- | :--- | :--- |
| **Tier 1** *(Highest)* | **PHPStan** | `@phpstan-template-covariant` → `@phpstan-template-contravariant` → `@phpstan-template` |
| **Tier 2** | **Psalm** | `@psalm-template-covariant` → `@psalm-template-contravariant` → `@psalm-template` |
| **Tier 3** *(Base)* | **Standard** | `@template-covariant` → `@template-contravariant` → `@template` |

### Why Priority Matters for Templates

Authors often write a broad `@template T` for generic IDE compatibility, and then declare `@phpstan-template T of Animal` to specify strict upper bounds for static analyzers. TypePHP always extracts the tool-specific annotation so that runtime bound enforcement matches the author's intended contract:

```php
/**
 * Standard tag has no bound, but @phpstan-template enforces Animal bound:
 *
 * @template T
 * @phpstan-template T of Animal
 * @phpstan-template-covariant T
 */
class BoundedProducer
{
    public function __construct(public mixed $item) {}
}

// Valid: Dog extends Animal
new BoundedProducer(new Dog());

// Invalid: Car does not extend Animal
new BoundedProducer(new Car());
// Throws: TypeError: BoundedProducer::__construct(): Argument $item (template T) must be of type Animal, Car given
```

---

## Inherited Template Annotations (`@extends`, `@implements`, `@use`)

When extending generic parent classes, implementing generic interfaces, or using generic traits, TypePHP treats all of the following variations as 100% equivalent:

| Inheritance Context | Supported Tag Variations |
| :--- | :--- |
| **Class Inheritance** | `@extends`, `@template-extends`, `@phpstan-extends`, `@psalm-extends` |
| **Interface Implementation** | `@implements`, `@template-implements`, `@phpstan-implements`, `@psalm-implements` |
| **Trait Usage** | `@use`, `@template-use`, `@phpstan-use` |

---

## Interface Implementation (`@implements` / `@template-implements`)

When a class implements a generic interface, declare the concrete template binding via `@implements` or `@template-implements`:

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
 * Fulfills T = Cat via @template-implements
 *
 * @template-implements ProcessorInterface<Cat>
 */
class CatProcessor implements ProcessorInterface
{
    // No DocBlock needed on method! Inherits T = Cat from interface contract.
    public function process(mixed $item): mixed
    {
        return $item;
    }
}

$processor = new CatProcessor();

// Valid Call
$processor->process(new Cat());

// Invalid Call (Dog is not a Cat)
$processor->process(new Dog());
// Throws: TypeError: CatProcessor::process(): Argument $item (template T = Cat) must be of type Cat
```

---

## Class Extension (`@extends` / `@template-extends`)

When a child class extends a generic parent class (`@extends BaseRepository<User>`), TypePHP resolves and inherits the parent's generic template bindings across the entire class hierarchy:

```php
namespace App\Repositories;

use App\Models\User;
use App\Models\Product;

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
 * Fulfills T = User via @template-extends
 *
 * @template-extends BaseRepository<User>
 */
class UserRepository extends BaseRepository
{
}

$userRepo = new UserRepository();

// Valid Save
$userRepo->save(new User('Alice'));

// Invalid Save (Product is not a User)
$userRepo->save(new Product('SKU-100'));
// Throws: TypeError: UserRepository::save(): Argument $entity (template T = User) must be of type User
```

---

## Generic Traits (`@use` / `@template-use` / `@phpstan-use`)

TypePHP supports binding generic template parameters to traits. You can declare the binding either at the **class level** or **directly above the inline `use Trait;` statement**:

### Generic Trait Definition (`ItemLoggerTrait.php`)

```php
/**
 * @template T
 */
trait ItemLoggerTrait
{
    /**
     * @param T $item
     */
    public function logItem(mixed $item): bool
    {
        return true;
    }
}
```

### Class-Level Trait Annotation (`@use` / `@template-use`)

```php
/**
 * Class docblock binds T = Dog for the trait
 *
 * @use ItemLoggerTrait<Dog>
 */
class ClassLevelLogService
{
    use ItemLoggerTrait;
}

$service = new ClassLevelLogService();

$service->logItem(new Dog()); // Valid

$service->logItem(new Car());
// Throws: TypeError: Argument $item (template T = Dog) must be of type Dog, Car given
```

### Inline Statement Trait Annotation (`/** @use */ use Trait;`)

```php
class InlineLogService
{
    /**
     * Inline statement docblock binds T = Dog
     *
     * @use ItemLoggerTrait<Dog>
     */
    use ItemLoggerTrait;
}

$service = new InlineLogService();

$service->logItem(new Dog()); // Valid

$service->logItem(new Car());
// Throws: TypeError: Argument $item (template T = Dog) must be of type Dog, Car given
```

### Compact Single-Line Trait Annotation

```php
class CompactLogService
{
    /** @use ItemLoggerTrait<Dog> */
    use ItemLoggerTrait;
}
```

---

## Vendor DocBlock Isolation for Inherited Generics

Third-party vendor libraries sometimes contain loose, outdated, or buggy DocBlock annotations. If your application extends a third-party vendor class or uses a vendor trait, TypePHP protects your application via **Vendor Isolation**:

* If an ancestor class or trait is located inside an `exclude` directory (such as `/vendor/`), TypePHP **ignores its inherited DocBlocks**.
* If your application class (in `src/`, included) defines `/** @use VendorTrait<Dog> */`, TypePHP **binds and enforces your application contract**, while keeping vendor internals protected.

