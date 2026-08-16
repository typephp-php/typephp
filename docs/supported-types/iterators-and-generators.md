# Iterators & Generators

TypePHP provides lazy runtime validation for `Traversable` objects, `Iterator` instances, and PHP `Generator` functions, validating yielded keys, values, and generator inputs (`$gen->send()`) on-the-fly during iteration.

---

## How Lazy Iteration Works (`IterableWrapper` & `IteratorProxy`)

When an iterable or generator is passed into a function accepting `Traversable<K, V>` or returned from a function:
1. **Zero Memory Spikes:** TypePHP does not convert the iterator to an array or load items eagerly into RAM.
2. **On-the-Fly Validation:** Keys (`K`) and values (`V`) are validated lazily during iteration at the exact moment each item is accessed inside `current()` or `yield`.
3. **Rewindability Preserved:** `IteratorProxy` unwraps and preserves iterator rewindability, allowing multiple `foreach` loops over the same wrapped iterator without crashing.
4. **Method & Countable Forwarding:** Forwards `Countable::count()` and custom iterator methods directly to the inner iterator using `__call()`.

---

## Generic Streams vs. Concrete Collection Classes (Zero Proxy Overhead)

A critical architectural design in TypePHP is distinguishing between **abstract generic streams** and **concrete collection classes**:

### 1. Abstract Generic Streams (`iterable<T>`, `Traversable<K, V>`, `Generator<K, V>`)
When a method or parameter specifies an abstract stream keyword with inner type constraints:
```php
/**
 * Abstract stream contract -> Wrapped in IteratorProxy
 *
 * @param Traversable<string, positive-int> $scores
 */
public function processScores(Traversable $scores): void
```
Because `Traversable` is a general interface with no methods of its own, TypePHP wraps it in a lazy `IteratorProxy` to validate yielded items and keys during iteration.

### 2. Concrete Collection Classes (`FileCollection`, `ArrayCollection`, `OrderList`)
When a method parameter, property, or return value is a concrete class (even if that class implements `\IteratorAggregate` or `\Traversable`):
```php
class StorefrontConfig
{
    // Native PHP class type hint
    protected FileCollection $styleFiles;

    public function setStyleFiles(FileCollection $styleFiles): void
    {
        $this->styleFiles = $styleFiles; // Preserved as raw FileCollection!
    }
}
```

TypePHP leaves concrete collections **100% unwrapped as raw PHP objects**:

* **Preserves Native PHP Nominal Type Hints:** Prevents fatal PHP engine errors (e.g. `Cannot assign IteratorProxy to property ...::$styleFiles of type FileCollection`).
* **Preserves Custom Domain Methods & State:** Custom business methods (like `$files->getPublicUrls()` or `$files->filterByExtension()`) and private properties remain directly accessible.
* **Direct `\WeakMap` Enforcement:** For generic concrete classes (like `ArrayCollection<int, Animal>`), TypePHP tracks generic template bindings directly in `\WeakMap` memory, running validation rules inside `$collection->add()` and `$collection->set()` without needing any wrapper proxy!
* **Zero Allocation Overhead:** Eliminates proxy object allocations and garbage collection pressure, allowing concrete collections to run at native C-level speed.

### Summary: When TypePHP Wraps vs. Leaves Unwrapped

| Type Annotation | Object Passed | Action Taken | Why |
| :--- | :--- | :---: | :--- |
| `Traversable<string, positive-int>` | Any Iterator / Generator | **Wrapped in `IteratorProxy`** | Abstract stream; needs lazy item validation during iteration. |
| `iterable<User>` | Any Array / Traversable | **Wrapped in `IteratorProxy`** | Abstract stream; validates items on-the-fly. |
| `FileCollection` | `new FileCollection()` | **Unwrapped (Raw Object)** | Concrete class; preserves native PHP type hints and domain methods. |
| `ArrayCollection<int, Animal>` | `new ArrayCollection()` | **Unwrapped (Raw Object)** | Generic state is tracked directly via `\WeakMap` inside `add()`/`set()`. |
| Un-annotated (`mixed $items`) | Any Iterator | **Unwrapped (Raw Object)** | No type contracts to enforce; zero overhead. |

---

## Traversable & Iterator Contracts (`Traversable<K, V>`)

Validate keys and values on any `Traversable` or `ArrayIterator` instance:

```php
<?php

declare(strict_types=1);

/**
 * @param Traversable<non-empty-string, positive-int> $items
 */
function processTraversable(Traversable $items): array
{
    $results = [];
    foreach ($items as $key => $value) {
        $results[$key] = $value;
    }

    return $results;
}

// 1. Valid Call
$iterator = new ArrayIterator(['item1' => 10, 'item2' => 20]);
processTraversable($iterator);

// 2. Invalid Call (Value -50 violates positive-int)
$badIterator = new ArrayIterator(['item1' => 10, 'item2' => -50]);
processTraversable($badIterator);
// Throws: TypeError: Iterator $items value['item2'] must be of type positive-int, negative int (-50) given

// 3. Invalid Call (Empty string violates non-empty-string key)
$badKeyIterator = new ArrayIterator(['' => 10]);
processTraversable($badKeyIterator);
// Throws: TypeError: Iterator $items key must be of type non-empty-string, empty string ('') given
```

---

## Generic Iterables with Template Substitution (`@template T`)

When a function accepts generic iterables (`iterable<T>` or `Traversable<string, T>`), TypePHP dynamically substitutes `T` with the bound generic type and lazily validates items during iteration:

> **Deep Dive Guide:** For comprehensive details on template bounds, covariance/contravariance, and runtime generic state inspection, see the [Generics & Bounds](/generics/generics-and-bounds) documentation.

```php
use App\Models\Animal;
use App\Models\Dog;
use App\Models\Car;

/**
 * Generic stream processor where T is inferred from $sample
 *
 * @template T
 *
 * @param iterable<T> $stream
 * @param T $sample
 *
 * @return list<T>
 */
function collectStream(iterable $stream, mixed $sample): array
{
    $collected = [];
    foreach ($stream as $item) {
        $collected[] = $item;
    }

    return $collected;
}

// 1. Valid Call: Infers T = int, validates all items against int
$intIterator = new ArrayIterator([10, 20, 30]);
collectStream($intIterator, 1); // Returns [10, 20, 30]

// 2. Invalid Call: T is inferred as int (from 1), but iterator yields string ('invalid')
$badIterator = new ArrayIterator([10, 'invalid', 30]);
collectStream($badIterator, 1);
// Throws: TypeError: Iterator $stream value must be of type int, string 'invalid' given
```

### Generic Traversables with Class Bounds (`@template T of Animal`)

```php
/**
 * @template T of Animal
 *
 * @param Traversable<non-empty-string, T> $stream
 *
 * @return list<T>
 */
function collectAnimalStream(Traversable $stream): array
{
    $collected = [];
    foreach ($stream as $key => $animal) {
        $collected[] = $animal;
    }

    return $collected;
}

// Valid Call
collectAnimalStream(new ArrayIterator(['dog1' => new Dog()]));

// Invalid Call (Car is not an Animal)
collectAnimalStream(new ArrayIterator(['car1' => new Car()]));
// Throws: TypeError: Iterator $stream value must be of type App\Models\Animal, App\Models\Car given
```

---

## Generator Function Contracts (`Generator<TKey, TValue, TSend, TReturn>`)

PHP `Generator` functions allow declaring up to 4 generic parameters:
* **`TKey`:** Type of yielded keys (`yield $key => $val`).
* **`TValue`:** Type of yielded values (`yield $val`).
* **`TSend`:** Type of values sent into the generator via `$gen->send($val)`.
* **`TReturn`:** Type of value returned when the generator completes (`return $val`).

### Yielded Key and Value Validation (`TKey` & `TValue`)

```php
/**
 * @return Generator<non-empty-string, positive-int>
 */
function generateScores(): Generator
{
    yield 'alice' => 100; // Valid
    yield 'bob' => -50;   // Invalid: -50 violates positive-int!
}

$gen = generateScores();

foreach ($gen as $name => $score) {
    // Throws lazily on second yield:
    // TypeError: Return iterator value must be of type positive-int, negative int (-50) given
}
```

### Generic Generators Yielding Template `T` (`Generator<int, T>`)

Generators seamlessly support generic template substitution for yielded values:

```php
/**
 * @template T
 *
 * @param T $item
 * @param positive-int $count
 *
 * @return Generator<int, T>
 */
function streamItem(mixed $item, int $count): Generator
{
    for ($i = 0; $i < $count; $i++) {
        yield $i => $item;
    }
}

// Infers T = int, yields int values
$gen = streamItem(100, 3);
foreach ($gen as $k => $v) {
    // [0 => 100, 1 => 100, 2 => 100]
}
```

---

## Generator Input Validation (`$gen->send()` / `TSend`)

TypePHP validates values sent into an interactive generator via `$gen->send()` against the declared `TSend` parameter:

```php
/**
 * TKey = int, TValue = string, TSend = positive-int, TReturn = void
 *
 * @return Generator<int, string, positive-int, void>
 */
function processInteractiveGenerator(): Generator
{
    $receivedInput = yield 1 => 'first_value';
    yield 2 => "processed: {$receivedInput}";
}

$gen = processInteractiveGenerator();
$gen->current(); // Advances to first yield

// 1. Valid Send (100 satisfies TSend = positive-int)
$gen->send(100);

// 2. Invalid Send (-500 violates TSend = positive-int)
$gen = processInteractiveGenerator();
$gen->current();

$gen->send(-500);
// Throws: TypeError: processInteractiveGenerator(): Generator sent value (TSend) must be of type positive-int, negative int (-500) given
```

### Generic Interactive Generators (`Generator<int, T, T, void>`)

When `TSend` uses a generic template `T`, `$gen->send()` is dynamically validated against the bound generic type:

```php
/**
 * @template T
 *
 * @param T $initial
 *
 * @return Generator<int, T, T, void>
 */
function streamInteractive(mixed $initial): Generator
{
    $current = $initial;
    for ($i = 0; $i < 3; $i++) {
        $input = yield $i => $current;
        if ($input !== null) {
            $current = $input;
        }
    }
}

// Initial value 10 locks T = int
$gen = streamInteractive(10);
$gen->current();

$gen->send(20); // Valid (20 is int)

$gen->send('invalid'); // Invalid: string violates T = int!
// Throws: TypeError: streamInteractive(): Generator sent value (TSend) must be of type int, string 'invalid' given
```

---

## Delegated Generators (`yield from`)

TypePHP seamlessly intercepts delegated `yield from` expressions, lazily validating keys and values yielded from nested iterators or arrays:

```php
/**
 * @return Generator<string, positive-int>
 */
function parentGenerator(): Generator
{
    yield from ['a' => 10, 'b' => 20]; // Valid
    yield from ['c' => -99];           // Invalid: -99 violates positive-int
}

foreach (parentGenerator() as $key => $val) {
    // Throws lazily on 'c' => -99:
    // TypeError: Return iterator value must be of type positive-int
}
```

---

## Complex Yield & Send Types (Array Shapes, Generics & Lists)

Because `GeneratorChecker` delegates key, value, and `TSend` validation directly to TypePHP's central validator engine, **all complex types (array shapes, lists, generic objects, unions) are fully enforced inside generator signatures**:

```php
use App\Generics\Producer;
use App\Models\Dog;

/**
 * Generator yielding Array Shapes and accepting Array Shapes in $gen->send()
 *
 * @return Generator<int, array{id: positive-int, username: non-empty-string}, array{action: 'approve'|'reject'}, void>
 */
function processComplexGenerator(): Generator
{
    $input = yield 1 => ['id' => 10, 'username' => 'Alice'];
    
    // $input is validated against TSend shape array{action: 'approve'|'reject'} when sent!
}

$gen = processComplexGenerator();
$firstItem = $gen->current(); // Returns ['id' => 10, 'username' => 'Alice']

// 1. Valid Send
$gen->send(['action' => 'approve']);

// 2. Invalid Send ('action' => 'delete' violates 'approve'|'reject')
$gen = processComplexGenerator();
$gen->current();

$gen->send(['action' => 'delete']);
// Throws: TypeError: processComplexGenerator(): Generator sent value (TSend)['action'] must be of type ('approve' | 'reject')
```

---

## Multi-Level `IteratorAggregate` Unwrapping & Method Forwarding

When passing custom classes implementing `IteratorAggregate`, `IteratorProxy` recursively unwraps the inner iterator while preserving method forwarding and `Countable` support:

```php
class NestedCollection implements IteratorAggregate, Countable
{
    public function __construct(private array $items = ['a' => 10, 'b' => 20]) {}

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getCustomMetadata(): string
    {
        return 'custom_metadata';
    }
}

/**
 * @param Traversable<string, positive-int> $collection
 */
function processCollection(Traversable $collection): void
{
    // 1. Iteration validates on-the-fly
    foreach ($collection as $k => $v) { ... }

    // 2. Countable::count() is forwarded
    echo count($collection); // 2

    // 3. Custom methods forwarded via __call
    echo $collection->getCustomMetadata(); // 'custom_metadata'
}

processCollection(new NestedCollection());
```
```