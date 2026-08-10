# Primitives & Scalars

TypePHP provides runtime enforcement for all native PHP primitives, literal scalar values, extended integer ranges, string refinements, class-string subtypes, float constraints, resources, and special return control types.

---

## Native PHP Primitives

TypePHP validates standard PHP primitive types in function parameters, return values, properties, and `@var` local assignments:

| Primitive Keyword | Validated PHP Types | Example Values |
| :--- | :--- | :--- |
| **`int`**, **`integer`** | Native integers | `10`, `-5`, `0` |
| **`string`** | Native string scalars | `'hello'`, `''`, `'123'` |
| **`float`**, **`double`** | Floating-point numbers & integers | `12.34`, `0.0`, `-5.5`, `10` |
| **`bool`**, **`boolean`** | Native booleans | `true`, `false` |
| **`null`** | Null value | `null` |
| **`mixed`** | Any value | Always valid (Zero overhead) |
| **`scalar`** | Any PHP scalar (`int`, `string`, `float`, `bool`) | `10`, `'hello'`, `true` |

```php
/**
 * @param int $id
 * @param string $name
 * @param bool $active
 */
function processPrimitive(int $id, string $name, bool $active): void
{
    // ...
}
```

---

## Literal Scalar Values

TypePHP supports enforcing exact literal scalar values in DocBlock types. The incoming value must strictly match the declared literal:

| Literal Category | Example Syntax | Valid Value | Invalid Example |
| :--- | :--- | :--- | :--- |
| **String Literals** | `'active'`, `'admin'` | `'active'` | `'inactive'`, `'user'` |
| **Integer Literals** | `42`, `200` | `42` | `43`, `'42'` |
| **Float Literals** | `3.14`, `0.01` | `3.14`, `3` | `3.15`, `'3.14'` |
| **Boolean Literals** | `true`, `false` | `true` | `false`, `1` |

```php
/**
 * @param 'active' $status      // Requires exact string 'active'
 * @param 200 $code             // Requires exact integer 200
 * @param true $debug           // Requires exact boolean true
 * @param 0.3 $threshold        // Requires float literal 0.3
 */
function setEnvironment(string $status, int $code, bool $debug, float $threshold): void
{
    // ...
}

// Valid Call
setEnvironment('active', 200, true, 0.3);

// Invalid Call ('inactive' is not literal 'active')
setEnvironment('inactive', 200, true, 0.3);
// Throws: TypeError: setEnvironment(): Argument $status must be literal 'active', string 'inactive' given
```

### Floating-Point Precision & Epsilon Comparison

Floating-point arithmetic in computers uses IEEE 754 representation, where mathematical operations like `0.1 + 0.2` evaluate to `0.30000000000000004`.

To prevent rounding artifacts from causing unexpected type failures, TypePHP evaluates float literals using **Epsilon Tolerance (`1e-9`)**:

1. **IEEE 754 Arithmetic Handling:** Floating-point calculations evaluating to `0.30000000000000004` safely satisfy `@param 0.3`.
2. **Integer Coercion Support:** Integer values like `10` safely satisfy float literal types like `@param 10.0` in accordance with PHP scalar coercion rules.

---

## Integer Refinements and Ranges

TypePHP enforces exact value constraints and bounds on integer parameters:

| Refinement Keyword | Constraint Rule | Valid Examples | Invalid Examples |
| :--- | :--- | :--- | :--- |
| **`positive-int`** | Integer > 0 | `1`, `42`, `100` | `0`, `-5` |
| **`negative-int`** | Integer < 0 | `-1`, `-42` | `0`, `5` |
| **`non-positive-int`** | Integer <= 0 | `0`, `-1`, `-10` | `1`, `5` |
| **`non-negative-int`** | Integer >= 0 | `0`, `1`, `100` | `-1`, `-5` |
| **`non-zero-int`** | Integer != 0 | `1`, `-1`, `100` | `0` |
| **`unsigned-int`** | Integer >= 0 | `0`, `10`, `50` | `-10` |

### Integer Bounds (`int<min, max>`)

Define explicit minimum and maximum bounds for integers using range syntax:

```php
/**
 * @param int<1, 100> $percentage  // Range: 1 to 100 inclusive
 * @param int<0, max> $offset      // Range: 0 to infinity (non-negative)
 * @param int<min, 10> $maxLimit   // Range: negative infinity to 10
 */
function setRange(int $percentage, int $offset, int $maxLimit): void
{
    // ...
}

// Valid Call
setRange(50, 0, 5);

// Invalid Call ($percentage = 150 exceeds max bound 100)
setRange(150, 0, 5);
// Throws: TypeError: setRange(): Argument $percentage must be <= 100, 150 given
```

---

## Integer Bitmasks (`int-mask<...>` & `int-mask-of<...>`)

TypePHP enforces bitwise flag combinations created by bitwise `OR` (`|`) operations on integers using `int-mask` and `int-mask-of`:

| Refinement Keyword | Constraint Rule | Valid Examples | Invalid Examples |
| :--- | :--- | :--- | :--- |
| **`int-mask<1, 2, 4>`** | Value must be a valid bitwise combination of the allowed integer flags (or `0`). | `0`, `1`, `3` (`1\|2`), `7` (`1\|2\|4`) | `8`, `10`, `-1` |
| **`int-mask-of<self::FLAG_*>`** | Value must be a valid bitwise combination of class constants matching the wildcard pattern. | `1`, `3`, `7` | `16` |

```php
class BitmaskFlags
{
    public const FLAG_READ = 1;    // 0001
    public const FLAG_WRITE = 2;   // 0010
    public const FLAG_EXECUTE = 4; // 0100

    /**
     * @param int-mask<1, 2, 4> $mask
     * @param int-mask-of<self::FLAG_*> $wildcardMask
     */
    public static function setPermissions(int $mask, int $wildcardMask): void
    {
        // ...
    }
}

// Valid Call
BitmaskFlags::setPermissions(3, 7); // 3 = READ | WRITE, 7 = READ | WRITE | EXECUTE

// Invalid (8 contains bits outside allowed mask 1|2|4 = 7)
BitmaskFlags::setPermissions(8, 7);
// Throws: TypeError: Argument $mask must be a valid bitmask combination of the allowed flags
```

---

## String Refinements

Validate string lengths, formatting, character casing, and truthiness at runtime:

| Refinement Keyword | Constraint Rule | Valid Examples | Invalid Examples |
| :--- | :--- | :--- | :--- |
| **`non-empty-string`** | String length > 0 | `'hello'`, `'1'` | `''` (empty string) |
| **`numeric-string`** | `is_numeric($val) === true` | `'123'`, `'45.67'`, `'-10'` | `'abc'`, `''` |
| **`lowercase-string`** | `strtolower($val) === $val` | `'hello'`, `'user_100'` | `'Hello'`, `'ADMIN'` |
| **`non-empty-lowercase-string`** | Non-empty & lowercase | `'hello'`, `'abc'` | `''`, `'Hello'` |
| **`uppercase-string`** | `strtoupper($val) === $val` | `'USD'`, `'HELLO_100'` | `'Hello'`, `'admin'` |
| **`non-empty-uppercase-string`** | Non-empty & uppercase | `'EUR'`, `'ABC'` | `''`, `'Eur'` |
| **`array-key`** | `is_int($val) \|\| is_string($val)` | `100`, `'user_100'` | `true`, `[]`, `null` |
| **`literal-string`** | String scalar | `'active'`, `'user'` | Non-strings |
| **`truthy-string`**, **`non-falsy-string`** | Evaluates to `true` in boolean context | `'hello'`, `'1'` | `''`, `'0'` |

```php
/**
 * @param non-empty-string $title
 * @param numeric-string $amount
 * @param lowercase-string $slug
 */
function createPost(string $title, string $amount, string $slug): void
{
    // ...
}

// Valid Call
createPost('Welcome', '99.99', 'welcome-post');

// Invalid Call ($title is empty string)
createPost('', '99.99', 'welcome-post');
// Throws: TypeError: createPost(): Argument $title must be of type non-empty-string
```

---

## Class-String Subtypes and Generics (`class-string<T>`)

Enforce that string parameters contain valid class, interface, trait, or enum references, with optional generic bounds (`class-string<T>`):

| Keyword | Validation Rule | Valid Examples | Invalid Examples |
| :--- | :--- | :--- | :--- |
| **`class-string`** | Valid class, interface, trait, or enum | `User::class`, `stdClass::class` | `'NonExistentClass'` |
| **`class-string<T>`** | Valid class-string that extends or matches `T` | `Dog::class` for `class-string<Animal>` | `Car::class` for `class-string<Animal>` |
| **`interface-string`** | `interface_exists($val) === true` | `DateTimeInterface::class` | `stdClass::class` |
| **`trait-string`** | `trait_exists($val) === true` | `LoggerTrait::class` | `stdClass::class` |
| **`enum-string`** | `enum_exists($val) === true` | `StatusEnum::class` | `stdClass::class` |

### Generic `class-string<T>` Factories

When combined with `@template T`, `class-string<T>` binds the template parameter from the class name string and enforces a matching return type:

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

---

## Float Refinements

TypePHP enforces signs and bounds on floating-point parameters:

| Refinement Keyword | Constraint Rule | Valid Examples | Invalid Examples |
| :--- | :--- | :--- | :--- |
| **`positive-float`** | Float > 0.0 | `12.34`, `0.01` | `0.0`, `-5.5` |
| **`negative-float`** | Float < 0.0 | `-12.34`, `-0.01` | `0.0`, `5.5` |
| **`non-positive-float`** | Float <= 0.0 | `0.0`, `-1.5` | `1.5` |
| **`non-negative-float`** | Float >= 0.0 | `0.0`, `1.5` | `-1.5` |
| **`non-zero-float`** | Float != 0.0 | `1.5`, `-1.5` | `0.0` |

```php
/**
 * @param positive-float $rate
 * @param non-zero-float $delta
 */
function applyRate(float $rate, float $delta): void
{
    // ...
}

// Valid Call
applyRate(0.05, -1.2);

// Invalid Call ($rate = 0.0 is not positive-float)
applyRate(0.0, -1.2);
// Throws: TypeError: applyRate(): Argument $rate must be of type positive-float
```

---

## Truthiness and Numeric Types

Validate boolean truthiness or generic numeric inputs:

| Keyword | Validation Rule | Valid Examples | Invalid Examples |
| :--- | :--- | :--- | :--- |
| **`truthy`** | Evaluates to `true` in boolean context | `'hello'`, `1`, `[1]`, `true` | `0`, `''`, `null`, `false` |
| **`falsy`**, **`falsey`** | Evaluates to `false` in boolean context | `0`, `''`, `null`, `false`, `[]` | `'hello'`, `1`, `true` |
| **`numeric`**, **`number`** | Integer, Float, or Numeric String | `10`, `12.34`, `'99.9'` | `'abc'`, `null` |

```php
/**
 * @param truthy $flag
 * @param numeric $amount
 */
function processFlag(mixed $flag, mixed $amount): void
{
    // ...
}

// Valid Call
processFlag('valid', '99.9');

// Invalid Call ($flag = 0 is falsy)
processFlag(0, '99.9');
// Throws: TypeError: processFlag(): Argument $flag must be of type truthy
```

---

## Resources

Validate active open or closed PHP resource handles:

| Keyword | Validation Rule | Valid Examples | Invalid Examples |
| :--- | :--- | :--- | :--- |
| **`resource`**, **`open-resource`** | `is_resource($val) === true` | `fopen('file.txt', 'r')` | Closed stream, String |
| **`closed-resource`** | Stream handle that was closed | Closed stream handle (`fclose($fp)`) | Open stream, String |

```php
/**
 * @param open-resource $stream
 */
function processStream($stream): void
{
    // ...
}

$fp = fopen('php://memory', 'r');
processStream($fp); // Valid

fclose($fp);
processStream($fp); // Invalid: Stream was closed
// Throws: TypeError: processStream(): Argument $stream must be of type open-resource
```

---

## Special Control Return Types (`void` & `never`)

Enforce function exit behaviors:

### `void`
Verifies that a function returns `null` or omits a return expression:

```php
/**
 * @return void
 */
function processVoid(): void
{
    // Valid void function
}
```

### `never` (`no-return`, `never-return`, `never-returns`)
Verifies that a function **never returns normally** (it must either throw an exception or call `exit()`):

```php
/**
 * @return never
 */
function haltExecution(): void
{
    throw new RuntimeException('Execution stopped'); // Valid: Exits via exception
}

/**
 * @return never
 */
function badHaltExecution(): string
{
    return 'unexpected_return'; // Invalid: Function returned a value!
}

badHaltExecution();
// Throws: TypeError: badHaltExecution(): Return value must be of type never
```
