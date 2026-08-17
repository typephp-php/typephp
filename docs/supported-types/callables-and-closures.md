# Callables & Closures

TypePHP provides lazy runtime interception for callbacks, Closures, invokable objects (`__invoke`), array callables, PHP 8.1+ first-class callables, and PHPStan static-closure specifications.

---

## How Callback Interception Works (`CallableWrapper`)

When a function parameter, return value, or local variable is annotated with a callable contract (such as `callable(positive-int): non-empty-string`), TypePHP wraps the callable in a lazy interceptor proxy:

1. **Lazy Execution:** TypePHP does not execute the callback immediately when passed as an argument.
2. **Input Validation:** When the wrapped callback is invoked, TypePHP validates the arguments passed into the callback against the declared parameter types.
3. **Output Validation:** When the callback returns, TypePHP validates the returned value against the callback's declared return contract.
4. **Zero Overhead on Uncalled Callbacks:** If a callback is passed to a function but never invoked in that specific execution branch, zero validation overhead occurs.

---

## Basic Callable Contracts (`callable(T1, T2): R`)

Declare argument and return types for callbacks using `callable(Type1, Type2): ReturnType` syntax:

```php
<?php

declare(strict_types=1);

/**
 * @param callable(positive-int, non-empty-string): bool $callback
 */
function processUserCallback(callable $callback): bool
{
    return $callback(10, 'Alice');
}

// 1. Valid Callback
processUserCallback(function (int $id, string $name): bool {
    return $id > 0 && strlen($name) > 0;
});

// 2. Invalid Callback Return (Returns integer 123 instead of bool)
processUserCallback(function (int $id, string $name): int {
    return 123;
});
// Throws: TypeError: Callback $callback return value must be of type bool, int (123) given

// 3. Invalid Callback Argument (Passing negative id into callback)
function badInvoker(callable $callback): bool
{
    return $callback(-5, 'Alice');
}
// Throws: TypeError: Callback $callback $id must be of type positive-int, negative int (-5) given
```

---

## Generic Callables with Template Substitution (`@template T`)

When a function uses generic template parameters (`@template T`), TypePHP dynamically substitutes `T` into the callable's parameter and return types based on the bound generic type:

> **Deep Dive Guide:** For full details on generic templates, reified type inspection, and class bounds, see the dedicated [Generics Basics & Bounds](/generics/basics-and-bounds) guide.

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

// 1. Valid Execution: Infers T = int, validates callback input (int) and return (int)
$double = fn (int $x): int => $x * 2;
transformValue($double, 21); // Returns 42

// 2. Valid Execution: Infers T = string, validates callback input (string) and return (string)
$shout = fn (string $s): string => strtoupper($s);
transformValue($shout, 'hello'); // Returns 'HELLO'

// 3. Invalid Callback Return: T is inferred as int (from 10), but callback returns string ('invalid')
$badReturn = fn (int $x): string => 'invalid';
transformValue($badReturn, 10);
// Throws: TypeError: Callback $transformer return value must be of type int, string 'invalid' given
```

### Generic Callables with Class Bounds (`@template T of Animal`)

Restricting a callback's template parameter to a specific class hierarchy or interface:

```php
/**
 * @template T of Animal
 *
 * @param callable(T): non-empty-string $formatter
 * @param T $animal
 *
 * @return non-empty-string
 */
function formatAnimal(callable $formatter, Animal $animal): string
{
    return $formatter($animal);
}

// Valid Execution
formatAnimal(fn (Dog $d) => 'dog_label', new Dog());

// Invalid Return (Callback returns empty string violating non-empty-string)
formatAnimal(fn (Dog $d) => '', new Dog());
// Throws: TypeError: Callback $formatter return value must be of type non-empty-string, empty string ('') given
```

---

## Complex Parameter & Return Contracts in Callables

Because `CallableWrapper` delegates argument and return validation directly to TypePHP's central validator engine, **all complex types (generics, array shapes, lists, unions, intersections) are fully enforced inside callback signatures**:

```php
use App\Generics\Producer;
use App\Models\Dog;

/**
 * Callback accepting a generic Producer<Dog> and list<positive-int>, returning an array shape
 *
 * @param callable(Producer<Dog>, list<positive-int>): array{status: 'success'|'error', count: positive-int} $processor
 */
function executeComplexCallback(callable $processor): void
{
    $processor(new Producer(new Dog()), [10, 20]);
}

// Valid Call
executeComplexCallback(function (Producer $producer, array $ids): array {
    return ['status' => 'success', 'count' => 2];
});

// Invalid Execution (Callback returns negative count -5 violating positive-int in array shape)
executeComplexCallback(function (Producer $producer, array $ids): array {
    return ['status' => 'success', 'count' => -5];
});
// Throws: TypeError: Callback $processor return value['count'] must be of type positive-int, negative int (-5) given
```

> **Generics & Variance in Callables:** Need to enforce covariance (`Producer<covariant Animal>`) or contravariance (`Consumer<contravariant Dog>`) within callback arguments? See [Demystifying Variance in Generics](/generics/variance).

---

## Strict Closure Instance Contracts (`Closure(T): R`)

When you specify `Closure(T): R` instead of `callable(T): R`, TypePHP strictly requires a native `\Closure` instance, rejecting string function names or invokable objects:

```php
class InvokableService
{
    public function __invoke(int $id): string
    {
        return "user_{$id}";
    }
}

/**
 * Strictly requires a native \Closure instance
 *
 * @param Closure(positive-int): non-empty-string $closure
 */
function executeClosureOnly(Closure $closure): string
{
    return $closure(42);
}

// 1. Valid Call (Native closure)
executeClosureOnly(fn (int $id) => "user_{$id}");

// 2. Invalid Call (Invokable object passed where \Closure was required)
executeClosureOnly(new InvokableService());
// Throws: TypeError: Argument $closure must be of type Closure, App\InvokableService given

// 3. Invalid Call (String function name 'strlen' passed where \Closure was required)
executeClosureOnly('strlen');
// Throws: TypeError: Argument $closure must be of type Closure, string 'strlen' given
```

---

## Array & First-Class Callables (PHP 8.1+)

TypePHP seamlessly intercepts instance method array callables, static method callables, and PHP 8.1+ First-Class Callable syntax (`$obj->method(...)`):

```php
class UserService
{
    public function formatUser(int $id): string
    {
        return "user_{$id}";
    }

    public static function staticFormat(int $id): string
    {
        return "static_user_{$id}";
    }
}

/**
 * @param callable(positive-int): non-empty-string $formatter
 */
function executeFormatter(callable $formatter): string
{
    return $formatter(100);
}

$service = new UserService();

// 1. PHP 8.1+ First-Class Callable Syntax on Instance Method
executeFormatter($service->formatUser(...)); // Valid

// 2. PHP 8.1+ First-Class Callable Syntax on Static Method
executeFormatter(UserService::staticFormat(...)); // Valid

// 3. Instance Method Array Callable
executeFormatter([$service, 'formatUser']); // Valid

// 4. Static Method Array Callable
executeFormatter([UserService::class, 'staticFormat']); // Valid
```

---

## Higher-Order & Curried Functions Returning Callables

TypePHP recursively wraps functions that return other callback functions (e.g. factory pipelines, curried validators, and middleware handlers), enforcing argument and return contracts across all invocation stages:

```php
class ValidationPipeline
{
    /**
     * Method returning a curried validator function
     *
     * @return callable(positive-int): (callable(non-empty-string): bool)
     */
    public function createLengthValidator(): callable
    {
        return function (int $minLength): callable {
            return function (string $text) use ($minLength): bool {
                return strlen($text) >= $minLength;
            };
        };
    }
}

$pipeline = new ValidationPipeline();
$factory = $pipeline->createLengthValidator();

// Stage 1: Configure validator with minLength = 5
$minFiveValidator = $factory(5);

// Stage 2: Execute configured validator
$minFiveValidator('Hello World'); // Returns true
$minFiveValidator('Hi');          // Returns false

// Invalid Stage 1 Argument ($minLength = -1 violates positive-int)
$factory(-1);
// Throws: TypeError: Return value Callback argument #1 must be of type positive-int, negative int (-1) given

// Invalid Stage 2 Argument ($text = '' violates non-empty-string)
$minFiveValidator('');
// Throws: TypeError: Return value: Returned callback Callback argument #1 must be of type non-empty-string
```

---

## Advanced PHPStan Callable Specifications

TypePHP supports advanced callback specifications including variadic parameters, optional parameters, and static closures:

### Variadic Callback Parameters (`callable(T ...$items): R`)

```php
/**
 * @param callable(positive-int ...$ids): void $callback
 */
function processVariadicCallback(callable $callback): void
{
    $callback(10, 20, 30);
}

processVariadicCallback(function (int ...$ids) {
    // Valid: Every variadic argument is validated against positive-int
});
```

### Optional Callback Parameters (`callable(T1, T2=): R`)

Append an equals sign (`T=`) to denote optional callback parameters:

```php
/**
 * Second callback parameter $name is optional
 *
 * @param callable(positive-int, non-empty-string=): bool $callback
 */
function processOptionalCallback(callable $callback): bool
{
    return $callback(10); // 2nd argument omitted
}
```

### Static Closures (`static-closure`)

Enforce that a closure must be declared as `static` (not bound to `$this`):

```php
/**
 * @param static-closure(int): string $closure
 */
function processStaticClosure(Closure $closure): string
{
    return $closure(100);
}

// 1. Valid (Static closure)
processStaticClosure(static fn (int $id) => "static_{$id}");

// 2. Invalid (Non-static closure bound to $this)
processStaticClosure(fn (int $id) => "bound_{$id}");
// Throws: TypeError: Argument $closure must be a static Closure (not bound to $this)
```

---

## Inline `@var` Callable Contracts

Enforce argument and return contracts on local variables assigned with inline `@var` callable docblocks:

```php
/** @var callable(positive-int, non-empty-string): bool $formatter */
$formatter = fn (int $id, string $name) => strlen($name) > 0;

$formatter(10, 'Alice'); // Valid

$formatter(-5, 'Alice');
// Throws: TypeError: Variable $formatter: Callback argument #1 must be of type positive-int, negative int (-5) given
```
