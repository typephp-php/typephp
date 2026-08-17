# Advanced Types & Callables

TypePHP allows combining generic templates (`T`, `K`, `V`) with high-level type algebra, including Higher-Order Callables, Lazy Iterables, Generators, Conditionals, Unions, Intersections, and Deeply Nested Containers.

---

## Generic Callables with Template Substitution

When a function accepts a generic callback (`@param callable(T): T $transformer`), TypePHP dynamically substitutes `T` with the inferred concrete type before callback invocation:

```php
/**
 * Generic transformer function
 *
 * @template T
 *
 * @param callable(T): T $transformer
 * @param T $input
 *
 * @return T
 */
function transformValue(callable $transformer, mixed $input): mixed
{
    return $transformer($input);
}

// 1. Valid Call: Infers T = int, validates callback argument (int) and return (int)
$double = fn (int $x): int => $x * 2;
transformValue($double, 21); // Returns 42

// 2. Invalid Callback Return: T is inferred as int (from 10), but callback returns string ('invalid')
$badReturn = fn (int $x): string => 'invalid';
transformValue($badReturn, 10);
// Throws: TypeError: transformValue(): Return value must be of type int, string 'invalid' returned
```

---

## Higher-Order Generic Transformers (`array<K, V>` & `callable(V): V2`)

TypePHP pre-infers template parameters across multiple arguments simultaneously:

```php
/**
 * Higher-order array mapper with 3 generic parameters
 *
 * @template K of array-key
 * @template V
 * @template V2
 *
 * @param callable(V): V2 $callback
 * @param array<K, V> $array
 *
 * @return array<K, V2>
 */
function mapArray(callable $callback, array $array): array
{
    $result = [];
    foreach ($array as $key => $value) {
        $result[$key] = $callback($value);
    }

    return $result;
}

$stringify = fn (int $n): string => "val_{$n}";

// 1. Valid Call: Infers K = string, V = int, V2 = string
$res = mapArray($stringify, ['a' => 10, 'b' => 20]);
// Returns: ['a' => 'val_10', 'b' => 'val_20']

// 2. Invalid Call: 'invalid_string' violates inferred V = int on function entry!
mapArray($stringify, ['item1' => 10, 'item2' => 'invalid_string']);
// Throws: TypeError: mapArray(): Argument $array['item2'] must be of type int, string 'invalid_string' given
```

---

## Generic Iterables & Generators (`iterable<T>` & `Generator<K, V>`)

TypePHP substitutes template parameters into iterators, validating yielded items, keys, and generator inputs (`$gen->send()`) lazily during execution:

```php
/**
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

// Infers T = int from $sample (1)
$iterator = new ArrayIterator([10, 'invalid', 30]);
collectStream($iterator, 1);
// Throws: TypeError: Iterator $stream value must be of type int, string 'invalid' given
```

### Generic Interactive Generators (`Generator<int, T, T, void>` / `TSend`)

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

## Conditional Return Types with Generics (`(T is Dog ? A : B)`)

TypePHP dynamically evaluates conditional return types based on generic templates:

```php
/**
 * @template T
 *
 * @param T $input
 * @param mixed $output
 *
 * @return (T is Dog ? positive-int : non-empty-string)
 */
function processInput(mixed $input, mixed $output): mixed
{
    return $output;
}

// 1. T is inferred as Dog -> Evaluates return contract as positive-int
processInput(new Dog(), 100); // Valid

// 2. T is inferred as Cat -> Evaluates return contract as non-empty-string
processInput(new Cat(), 'valid_string'); // Valid

processInput(new Cat(), ''); // Invalid: empty string violates non-empty-string
// Throws: TypeError: processInput(): Return value must be of type non-empty-string
```

### Negated Generic Conditionals (`(T is not Dog ? A : B)`)

```php
/**
 * @template T
 *
 * @param T $input
 * @param mixed $result
 *
 * @return (T is not Dog ? non-empty-string : positive-int)
 */
function processNegated(mixed $input, mixed $result): mixed
{
    return $result;
}

processNegated(new Cat(), 'valid_text'); // Valid (Cat is not Dog -> non-empty-string)
processNegated(new Dog(), 42);           // Valid (Dog is Dog -> positive-int)
```

---

## Generics with Unions and Intersections

TypePHP fully supports combining generic structures with Union (`|`) and Intersection (`&`) types:

### Generic Containers Holding Unions (`Collection<Dog|Cat>`)

```php
/** @var Collection<Dog|Cat> $animals */
$animals = new Collection();

$animals->add(new Dog()); // Valid
$animals->add(new Cat()); // Valid

$animals->add(new Car()); // Invalid: Car is neither Dog nor Cat
// Throws: TypeError: Collection::add(): Argument $item (template T = Dog|Cat) must be of type (Dog | Cat)
```

### Unions of Generic Containers (`Producer<Dog> | Producer<Cat>`)

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

## Deeply Nested Generics (`Collection<Producer<Dog>>`)

TypePHP recursively evaluates deeply nested generic structures down to any depth:

```php
/** @var Collection<Producer<Dog>> $producers */
$producers = new Collection();

// Valid Addition
$producers->add(new Producer(new Dog()));

// Invalid Addition (Producer holding Car instead of Dog)
$producers->add(new Producer(new Car()));
// Throws: TypeError: Argument $item must be an instance of Producer<Dog>
```
