# Inline Variables (`@var`)

While parameter and return contracts protect function boundaries, inline `@var` annotations enforce type safety on local variable assignments, reassignments, and direct return statements inside function bodies or PHP scripts.

---

## Basic Variable Validation

When you write an inline `@var` docblock above a local variable assignment, TypePHP validates the assigned value before the assignment executes:

```php
<?php

declare(strict_types=1);

/** @var positive-int $age */
$age = 25; // Valid

$age = -10; 
// Throws: TypeError: Variable $age must be of type positive-int, negative int (-10) given
```

TypePHP validates all supported type categories on inline variable assignments:
* **Scalars:** `positive-int`, `non-empty-string`, `numeric-string`, `truthy`
* **Arrays & Shapes:** `array{id: int, name: string}`, `list<positive-int>`, `int[]`
* **Objects:** `/** @var \App\Models\User $user */`
* **Unions & Intersections:** `positive-int|non-empty-string`, `\Countable&\ArrayAccess`
* **Generics:** `/** @var Collection<User> $users */`
* **Callables:** `/** @var callable(positive-int): non-empty-string $formatter */`

---

## Single-Line vs. Multi-Line Annotations

TypePHP supports both single-variable single-line docblocks and multi-variable docblocks (such as for `list()` array destructuring or multiple assignments):

### Single-Variable Annotation

```php
/** 
 * @var positive-int $id -> multiline docblock
 */
$id = 100;

/** @var non-empty-string $name -> single-line docblock */ 
$name = 'Reymart';
```

### Multi-Variable Annotation (Array Destructuring)

When assigning multiple variables simultaneously via `list()` or `[$a, $b]`, declare all `@var` tags inside a single multi-line docblock:

```php
/**
 * @var positive-int $id
 * @var non-empty-string $username
 */
[$id, $username] = [42, 'Reymart']; // Valid

[$id, $username] = [-5, 'Reymart'];
// Throws: TypeError: Variable $id must be of type positive-int
```

---

## Unnamed Variable Annotations (`/** @var Type */`)

If you omit the variable name from an inline `@var` docblock, TypePHP automatically infers the target variable name directly from the assignment statement:

```php
/** @var positive-int */
$count = 100; // TypePHP automatically infers that $count is positive-int!

$count = -5;
// Throws: TypeError: Variable $count must be of type positive-int, negative int (-5) given
```

Both `/** @var positive-int $count */` and `/** @var positive-int */` behave identically.

---

## Inline `@var` on Direct Return Statements

You can place `/** @var Type */` directly above a `return` statement to perform surgical, expression-level type assertion narrowing inside function bodies, closures, or specific conditional branches:

```php
function fetchUserScores(): array
{
    /** @var list<positive-int> */
    return [10, 20, 30]; // Valid
}

function fetchBadScores(): array
{
    /** @var list<positive-int> */
    return [10, -5, 30]; // Throws TypeError on -5!
}

$getUser = function () use ($repo) {
    /** @var array{id: positive-int, username: non-empty-string} */
    return $repo->fetchRawUser();
};
```

> **PHPStan & Psalm Parity:** Static analysis tools treat `/** @var Type */ return $expr;` as an inline type cast assertion. TypePHP physically enforces this assertion at runtime, ensuring that live dynamic returns strictly satisfy the annotated type.

---

## Block-Level Scope Isolation & Shadowing

TypePHP tracks variable type contracts using **Lexical Block Scope Frames**. 

When an inline `@var` tag is declared inside a control block (`if`, `elseif`, `else`, `foreach`, `while`, `for`, `try/catch`), the type contract applies **strictly inside that block**:

```php
/** @var positive-int $z */
$z = 10; // Outer contract: positive-int

if ($condition) {
    /** @var non-empty-string $z */ // Inner shadow contract: non-empty-string
    $z = 'hello';
}

// Outside the if-block, $z reverts back to its outer contract: positive-int!
$z = -5;
// Throws: TypeError: Variable $z must be of type positive-int, negative int (-5) given
```

> **Unexecuted Code Protection:** Unexecuted branches (such as `if (false)`) never pollute outer scope variable contracts during execution.

---

## Closure Scope Preservation

When a local variable is captured by a short closure (arrow function `fn()`) or a long closure (`use ($var)` or `use (&$var)`), TypePHP inherits and enforces the outer variable contract inside the closure:

```php
/** @var positive-int $id */
$id = 10;

// 1. Short Closure (Arrow Function)
$arrowFn = fn () => $id = -5;
$arrowFn();
// Throws: TypeError: Variable $id must be of type positive-int

// 2. Long Closure (By-Value Capture)
$closure = function () use ($id) {
    $id = -50;
};
$closure();
// Throws: TypeError: Variable $id must be of type positive-int

// 3. Long Closure (By-Reference Capture)
$refClosure = function () use (&$id) {
    $id = -99;
};
$refClosure();
// Throws: TypeError: Variable $id must be of type positive-int
```

---

## Fine-Grained Configuration Control

You can enable or disable specific categories of inline variable validation in `typephp.php` without turning off function parameter or return contracts:

```php
'inline_vars' => [
    'properties' => true,  // Class property assignments ($this->id = 1)
    'generics'   => true,  // Generic instance prebinding (Collection<User>)
    'callables'  => true,  // Inline callback wrapping (callable(int): string)
    'scalars'    => true,  // Scalar constraints (positive-int, non-empty-string)
    'arrays'     => true,  // Array shapes and lists (array{id: int}, list<T>)
    'objects'    => true,  // Class instance checks (@var User $user)
],
```