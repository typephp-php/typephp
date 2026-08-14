# Function Contracts

Functions and methods form the public boundaries of your software modules. TypePHP enforces `@param` and `@return` annotations directly at function entry and exit points.

---

## Parameter Contracts (`@param`)

When you declare `@param` annotations on a function or class method, TypePHP validates all incoming arguments before entering the function body:

> **Suppressing Function Contracts:** Need to skip type-checking on a legacy function or method? Add `@typephp-ignore` to its docblock. See [Ignore Annotations](/advanced/ignore-annotations) for full details.

```php
<?php

declare(strict_types=1);

/**
 * @param positive-int $id
 * @param non-empty-string $username
 * @param 'admin'|'editor'|'viewer' $role
 */
function registerUser(int $id, string $username, string $role): void
{
    // Executed only if all arguments pass validation
}

// Valid Call
registerUser(100, 'Alice', 'admin');

// Invalid Call (Passing negative integer)
registerUser(-5, 'Alice', 'admin');
// Throws: TypeError: registerUser(): Argument $id must be of type positive-int, negative int (-5) given
```

> **Execution Order Note:** Native PHP type hints (e.g., `int $id`, `string $username`) are evaluated by PHP's C-engine *before* function execution begins. TypePHP's extended PHPDoc contracts (e.g., `positive-int`, `non-empty-string`) execute at the very start of the function/method body. If a native type hint fails, PHP throws its native `TypeError` before TypePHP's guard rails run.

---

## PHP 8.0+ Named Arguments

TypePHP natively supports PHP 8.0+ Named Arguments. Because parameter contracts are mapped by parameter name rather than argument position index, you can pass named arguments in any order, and TypePHP will accurately validate each parameter:

```php
<?php

declare(strict_types=1);

/**
 * @param positive-int $id
 * @param non-empty-string $username
 * @param int<1, 100> $age
 */
function registerUser(int $id, string $username, int $age): void
{
    // ...
}

// Valid Call: Arguments passed in completely reversed/swapped order
registerUser(age: 25, username: 'Alice', id: 42);

// Invalid Call: $id (-5) passed as 3rd named argument
registerUser(age: 25, username: 'Alice', id: -5);
// Throws: TypeError: registerUser(): Argument $id must be of type positive-int, negative int (-5) given
```

---

## Class Methods (Instance & Static)

All parameter and return contract rules apply identically to **instance methods** (`public`, `protected`, `private`) and **static methods**:

```php
class UserService
{
    /**
     * Instance Method Contract
     *
     * @param positive-int $id
     * @return array{id: positive-int, name: non-empty-string}
     */
    public function findUser(int $id): array
    {
        return ['id' => $id, 'name' => 'Alice'];
    }

    /**
     * Static Method Contract
     *
     * @param non-empty-string $role
     * @return list<positive-int>
     */
    public static function getRoleIds(string $role): array
    {
        return [10, 20, 30];
    }
}

$service = new UserService();

// Invalid Instance Method Call ($id is negative)
$service->findUser(-10);
// Throws: TypeError: UserService::findUser(): Argument $id must be of type positive-int

// Invalid Static Method Call ($role is empty string)
UserService::getRoleIds('');
// Throws: TypeError: UserService::getRoleIds(): Argument $role must be of type non-empty-string
```

---

## Class Constructors (`__construct`)

TypePHP fully validates class constructor arguments, supporting both standard constructors and **Constructor Property Promotion** (PHP 8.0+).

### Promoted Properties (PHP 8.0+)

Annotate promoted properties in the constructor's docblock using standard `@param` tags:

```php
class Order
{
    /**
     * @param positive-int $id
     * @param non-empty-string $sku
     * @param int<1, 100> $quantity
     */
    public function __construct(
        public int $id,
        public string $sku,
        public int $quantity
    ) {}
}

// Valid Instance
new Order(1, 'SKU-99', 5);

// Invalid Instance ($id is negative)
new Order(-1, 'SKU-99', 5);
// Throws: TypeError: Order::__construct(): Argument $id must be of type positive-int
```

### Property `@var` Fallback for Un-Annotated Constructors

If a constructor parameter is un-annotated (or lacks a `@param` tag), TypePHP automatically inspects the corresponding class property's `@var` docblock to infer the parameter contract:

```php
class User
{
    /**
     * @var string[]
     */
    public array $roles;

    // Un-annotated constructor parameter inherits contract from $roles property docblock!
    public function __construct(array $roles)
    {
        $this->roles = $roles;
    }
}

// Invalid Instance (element 1 is an integer)
new User(['admin', 12345]);
// Throws: TypeError: User::__construct(): Argument $roles[1] must be of type string, int (12345) given
```

---

## Return Contracts (`@return`)

TypePHP validates `return` statements before values are returned to the caller:

```php
/**
 * @return array{id: positive-int, status: 'active'|'pending'}
 */
function getUserStatus(int $id): array
{
    if ($id <= 0) {
        return ['id' => $id, 'status' => 'active']; // Invalid: $id is negative
    }

    return ['id' => $id, 'status' => 'active'];
}

getUserStatus(-10);
// Throws: TypeError: getUserStatus(): Return value['id'] must be of type positive-int
```

> **PHPStan and Psalm Compatibility:** TypePHP also recognizes `@phpstan-param`, `@phpstan-return`, `@psalm-param`, and `@psalm-return` annotations.

---

## Fluent `$this` Identity Returns

For fluent builder or service classes annotated with `@return $this`, TypePHP verifies strict object identity (`$result === $this`), preventing accidental instantiation of new instances:

```php
class UserBuilder
{
    private string $name = '';

    /**
     * @return $this
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this; // Valid: Strict $this identity
    }

    /**
     * @return $this
     */
    public function cloneSelf(): self
    {
        return new self(); // Invalid: New instance returned instead of $this
    }
}

$builder = new UserBuilder();
$builder->cloneSelf();
// Throws: TypeError: UserBuilder::cloneSelf(): Return value must be $this instance
```

---

## Late Static Binding Return Contracts (`@return static`)

When a parent class method (static factory method or fluent instance method) is annotated with `@return static`, TypePHP enforces **Late Static Binding** at runtime. 

It dynamically verifies that the returned object is an instance of the **actual calling class** (`UserEntityFactory`), strictly rejecting parent instances (`BaseEntityFactory`), sibling instances (`AdminEntityFactory`), or generic objects (`stdClass`):

```php
abstract class BaseEntityFactory
{
    /**
     * @return static
     */
    public static function create(): static
    {
        return new static();
    }

    /**
     * @return static
     */
    public static function createSibling(): object
    {
        return new AdminEntityFactory(); // Invalid: Returns sibling instead of calling class!
    }
}

class UserEntityFactory extends BaseEntityFactory {}
class AdminEntityFactory extends BaseEntityFactory {}

// Valid: Returns UserEntityFactory instance matching the late-static calling class
$user = UserEntityFactory::create();

// Invalid: UserEntityFactory called, but AdminEntityFactory was returned!
UserEntityFactory::createSibling();
// Throws: TypeError: UserEntityFactory::createSibling(): Return value must be of type App\UserEntityFactory, App\AdminEntityFactory returned
```

### Late Static Binding with Generics (`static<T>`)

Late static binding seamlessly integrates with TypePHP's Reified Generics engine. A static factory can return a specialized generic instance of the late-static-bound calling class:

```php
/**
 * @template T
 */
abstract class BaseGenericFactory
{
    /**
     * @template TValue
     * @param TValue $value
     * @return static<TValue>
     */
    public static function of(mixed $value): static
    {
        return new static($value);
    }
}

class UserGenericFactory extends BaseGenericFactory {}

// 1. Returns UserGenericFactory instance
// 2. Binds generic template T = Dog in WeakMap memory!
$factory = UserGenericFactory::of(new Dog());
```

---

## Variadic Parameter Contracts

When a function or method accepts variadic arguments (`...$items`), TypePHP validates every element passed in the variadic argument list:

```php
/**
 * @param positive-int ...$ids
 */
function deleteUsers(int ...$ids): void
{
    // ...
}

// Valid Call
deleteUsers(10, 20, 30);

// Invalid Call (3rd variadic item violates positive-int)
deleteUsers(10, 20, -5);
// Throws: TypeError: deleteUsers(): Argument $ids[2] must be of type positive-int
```

---

## Conditional Return Types

TypePHP supports parameter-based conditional return types (`@return ($param is true ? TypeA : TypeB)`):

```php
/**
 * @param bool $asInt
 * @param mixed $value
 * @return ($asInt is true ? positive-int : non-empty-string)
 */
function formatValue(bool $asInt, mixed $value): mixed
{
    return $value;
}

// Valid Calls
formatValue(true, 42);       // Evaluates return type as positive-int
formatValue(false, 'hello'); // Evaluates return type as non-empty-string

// Invalid Call
formatValue(true, 'not_an_int');
// Throws: TypeError: formatValue(): Return value must be of type positive-int
```

---

## PHP 8.0+ Attributes Coexistence

TypePHP seamlessly coexists with native PHP 8.0+ Attributes (`#[Route]`, `#[Inject]`, `#[Validate]`). 

You can place your PHPDoc annotations **either above or below** native PHP attributes on properties, methods, or functions. TypePHP's AST engine and PHP's Reflection API process both metadata channels independently without any syntax conflicts:

```php
// Option A: DocBlock ABOVE Attribute (Supported)
/**
 * @param positive-int $id
 * @return array{id: positive-int, username: non-empty-string}
 */
#[Route('/user/{id}', method: 'GET')]
public function showUser(int $id): array
{
    return ['id' => $id, 'username' => 'Alice'];
}

// Option B: DocBlock BELOW Attribute (Supported)
#[Route('/user/{id}', method: 'GET')]
/**
 * @param positive-int $id
 * @return array{id: positive-int, username: non-empty-string}
 */
public function showUser(int $id): array
{
    return ['id' => $id, 'username' => 'Alice'];
}
```