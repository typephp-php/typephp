# How It Works

TypePHP provides configurable runtime type enforcement without requiring custom C-extensions or modified PHP binaries. It operates entirely in PHP user-land by leveraging PHP's native `StreamWrapper` subsystem, Abstract Syntax Tree (AST) transformations, and in-memory state tracking.

---

## Parser Engine Dependencies

TypePHP relies on two industry-standard parsing libraries to process source code and docblock contracts:

* **`nikic/php-parser`:** Parses raw PHP source code into Abstract Syntax Tree (AST) statement nodes, allowing TypePHP to inspect assignments, function signatures, and property hooks to inject guard-rail expressions.
* **`phpstan/phpdoc-parser`:** Tokenizes and parses PHPDoc annotations (`@param`, `@return`, `@template`, `@var`, `@phpstan-type`) into strongly typed AST `TypeNode` objects.

---

## The 5-Step Execution Lifecycle

Whenever your application loads a PHP file via `require`, `include`, or Composer's autoloader, TypePHP processes the file through a 5-step lifecycle:

```
[require "file.php"] 
         │
         ▼
 1. Stream Interception (StreamWrapper)
         │
         ▼
 2. Path & Specificity Filtering (FileFilter)
         │
         ▼
 3. AST Transformation & Injection (ContractVisitor)
         │
         ▼
 4. Zero Line-Drift Formatting & Caching (TypePHPPrinter)
         │
         ▼
 5. Runtime Type Enforcement (RuntimeTypeChecker)
```

---

## Stream Interception

TypePHP registers a custom stream wrapper for PHP's native `file://` protocol using `stream_wrapper_register()`.

When PHP attempts to include a file, TypePHP's `StreamWrapper` intercepts the `open` call. If the call is a read-only inspection (such as `file_get_contents()` or `token_get_all()`), TypePHP passes the raw file through untouched. If the call is an execution request (`require` or `include`), the file proceeds to path filtering.

Because stream handlers hook directly into PHP's stream subsystem, all underlying stream read, write, and stat operations execute **natively at C-level speed inside Zend Engine**, ensuring zero user-land file I/O bottlenecks.

---

## Path Filtering and Pattern Specificity

TypePHP alone determines which files to transform and enforce docblock contracts on based on your `typephp.php` configuration:

```php
'include' => ['src/**', 'vendor/my-org/my-package/**'],
'exclude' => ['vendor/**', 'storage/**'],
```

TypePHP calculates pattern specificity based on glob length. If you whitelist a specific vendor package (`'vendor/my-org/my-package/**'`), its pattern length (27) takes precedence over the general `'vendor/**'` exclusion (8).

### Excluded Files and Whitelisted Boundaries

When a file is excluded (blacklisted):

1. **Zero AST Modification:** The excluded file remains 100% raw, untouched PHP code. No AST parsing or check injection occurs on the excluded file.
2. **Active Whitelisted Guard Rails:** If an excluded file calls a method inside an included/whitelisted file passing invalid data, **a `TypeError` is still thrown**. The type guard runs inside the whitelisted method, protecting the whitelisted code regardless of who called it.
3. **Caller Line Attribution:** Even though the blacklisted caller file was never modified, `ErrorFactory` inspects the call stack trace and attributes the `TypeError` file and line number directly to the exact call site inside the blacklisted file!

---

## AST Transformation and Injection

If the file is included, TypePHP parses the source code into an AST using `nikic/php-parser` and `phpstan/phpdoc-parser`.

`ContractVisitor` traverses the AST and injects single-line guard rails:

* **Function Entry:** Injects `RuntimeTypeChecker::setupScope()` at the top of the function to validate incoming parameters.
* **Function Return:** Wraps `return` statements with `RuntimeTypeChecker::checkReturn()`.
* **Local Assignments (`@var`):** Wraps `$x = $value` with `RuntimeTypeChecker::checkVariable()`.
* **Class Properties:** Wraps `$this->prop = $value` and PHP 8.4 Property Hooks with `RuntimeTypeChecker::checkProperty()`.
* **Callables & Iterators:** Wraps callbacks and generators in lazy proxies (`CallableWrapper`, `IterableWrapper`).

---

## Zero Line-Drift Formatting and Caching

A common issue with AST code injection is that adding new statements pushes subsequent code down, causing line numbers in error stack traces to drift.

TypePHP solves this using `TypePHPPrinter` and regex post-processing. Injected guard rails are squashed onto single lines and appended to existing code blocks (such as the opening `{` of a function signature). 

**Line numbers in your source files remain 100% identical before and after transformation.**

### Disk Caching

Once transformed, TypePHP saves the resulting code to disk in `sys_get_temp_dir() . '/typephp-cache/'`. On all subsequent requests:
* AST parsing runs **0 times**.
* PHP's **OPCache** compiles the cached file once into bytecode in RAM.
* Stream file reads execute natively at C-level speed inside Zend Engine.

---

## Typed Arrays and Array Shapes

TypePHP handles complex array structures through specialized validators in `TypeValidatorRegistry`:

### Array Shapes (`ArrayShapeValidator`)
For annotations like `array{id: positive-int, name: string, role?: 'admin'|'user'}`:
* **Required vs. Optional Keys:** Verifies that required keys (`id`, `name`) are present, while allowing optional keys (`role?`) to be omitted.
* **Sealed vs. Unsealed Shapes:** In sealed shapes (default), unexpected extra keys trigger a `TypeError`. Unsealed shapes (`array{id: int, ...<string, string>}`) validate extra key-value pairs against the unsealed type specification.

### Typed Arrays and Lists (`ArrayValidator` & `GenericValidator`)
For annotations like `int[]`, `User[]`, `list<string>`, or `array<string, int>`:
* **Sequential List Verification:** `list<T>` uses PHP's native `array_is_list()` to ensure keys are sequential 0-indexed integers.
* **Object Memoization:** When validating an array of objects (such as `User[]`), `TypeValidatorRegistry` memoizes previously checked object instances in a `\WeakMap`. If the same object instance appears multiple times in a collection, its type is checked once and retrieved in O(1) time on subsequent accesses.

---

## Lazy Proxies: Callables, Generators, and Iterators

TypePHP uses lazy wrappers to validate dynamic data structures upon invocation or iteration without forcing eager evaluation:

### Callable Wrapper (`CallableWrapper`)

When a function accepts a `callable(int): string` parameter, `RuntimeTypeChecker::wrapCallable()` wraps the callback in an interceptor closure:
* **Invocation Validation:** When the callback is called, its incoming arguments are validated against the declared parameter types.
* **Return Validation:** When the callback returns, its return value is validated against the declared return type.
* **Static Closure Constraints:** Enforces `static-closure` rules, rejecting closures bound to `$this`.

### Iterator Proxy (`IterableWrapper` & `IteratorProxy`)

When an iterable or generator is passed into a function accepting `Traversable<string, positive-int>`:
* **Lazy Item Validation:** Values and keys are validated on-the-fly during iteration inside `current()` or `yield`.
* **Rewindability:** `IteratorProxy` unwraps and preserves iterator rewindability, allowing you to iterate over the wrapped Traversable in multiple `foreach` loops cleanly.
* **Method & Countable Forwarding:** Forwards `Countable::count()` and custom method calls directly to the inner iterator using `__call()`.
* **Generator `TSend` Input Validation:** `checkSend()` intercepts values passed via `$gen->send()` and validates them against the declared `TSend` template parameter.

---

## Inheritance Tracking and In-Memory Reflection Caching

TypePHP resolves method and property contracts across complex Object-Oriented hierarchies (abstract classes, parent classes, interfaces, PHP 8.4 interface properties, and traits) using `HierarchyResolver`.

### Gap-Filling and Parameter Renaming

* **Gap-Filling:** If a child method defines a docblock for `$name` but leaves `$id` un-annotated, `ContractParser` traverses up the hierarchy to fill in the missing contract for `$id` from parent classes or interfaces.
* **Parameter Renaming:** Inherited parameters are mapped by **index position** rather than parameter name. If a child class renames `$id` to `$userId`, the contract declared on `$id` at index 0 is mapped and enforced on `$userId`.
* **Vendor Isolation:** Inherited docblocks from files matching `exclude` rules (such as `/vendor/`) are ignored to prevent third-party docblock bugs from affecting your application.

### In-Memory Static Reflection Caching

To avoid repeating expensive Reflection calls across multiple method invocations on the same class, `HierarchyResolver` caches resolved `ReflectionClass` and `ReflectionMethod` trees in static RAM arrays (`$methodHierarchyCache` and `$classHierarchyCache`).

The Reflection tree for a class is built **exactly once** and retrieved in O(1) nanoseconds on all subsequent calls.

---

## Lexical Scope Tracking (`ScopeManager`)

TypePHP tracks local `@var` annotations using `ScopeManager` during AST traversal.

To support block-level variable scope isolation and prevent type contract leakage:
* **Scope Stack Frames:** Entering a function, closure, or control block (`if`, `elseif`, `else`, `foreach`, `while`, `for`, `try/catch`) pushes a new scope frame (`pushScope()`) that inherits outer variable contracts.
* **Variable Shadowing:** Re-declaring a variable type inside an `if` block (e.g. `/** @var non-empty-string $z */`) applies strictly inside that block.
* **Scope Restoration:** Exiting the block (`popScope()`) restores outer variables back to their original type contracts. Unexecuted branches (such as `if (false)`) never pollute the outer scope.

---

## State Tracking Mechanics

TypePHP manages generic templates and call scopes using specialized memory tracking:

### Object Instance Generics (`WeakMap`)

When you instantiate a generic object (such as `Collection<User>`), `TemplateManager` binds template parameters (`T = User`) to that specific object instance using PHP's native `WeakMap`.

Because `WeakMap` uses weak references, when the object instance is garbage-collected by PHP, its generic state is automatically deleted from memory with **zero memory leaks**.

### Call Stack Scope Tracking (`ScopeCleaner`)

For function-level templates (`@template T`), TypePHP pushes a temporary call frame when entering the function and returns a `ScopeCleaner` object. When the function exits or throws an exception, `ScopeCleaner::__destruct()` automatically pops the call frame, keeping generic state clean across recursive calls.

---

Here is the updated, brief **Validation Error Messages and Trace Attribution** section for `docs/architecture/how-it-works.md`:

---

## Validation Error Messages and Trace Attribution

When a type contract fails, TypePHP constructs informative error messages through a 3-tier pipeline:

```
Raw Value + TypeNode AST / Template Context
        │
        ▼
1. Error Generation (Validators & TemplateManager)
        │
        ▼
2. Human-Readable Value Formatting (TypeFormatter)
        │
        ▼
3. Exception Packaging & Trace Attribution (ErrorFactory)
```

1. **Error Generation (`TypeValidatorRegistry` & `TemplateManager`):**
   * **Standard Types:** Strategy validators evaluate values against AST nodes (`IdentifierValidator`, `ArrayShapeValidator`, `ObjectShapeValidator`, etc.).
   * **Generics & Variance:** `TemplateManager` and `ParamChecker` construct generic error messages when template bounds (`template T = User`), class-strings (`class-string<T>`), or variance rules (`Producer<covariant Dog>`) are violated.
2. **Human-Readable Formatting (`TypeFormatter`):** Inspects raw PHP values and generates descriptive string representations (e.g. `negative int (-50)`, `empty string ('')`, `associative array`, or object FQCN `App\Models\Car`).
3. **Exception Packaging (`ErrorFactory`):** Packages messages into `TypePHP\Exception\TypeError` that extends native `TypeError`. For parameter and callback argument errors, it filters out internal library frames and sets `$e->file` and `$e->line` to match the exact caller line in your application code.

---

## Performance Model: Transparent Trade-Offs

TypePHP is designed to be as fast as possible in PHP user-land, but runtime type checking inherently introduces CPU and memory trade-offs that you should understand:

### Understanding the Overhead

1. **Array Iteration (O(N) Overhead):** Validating a large array (e.g., 10,000 items) requires iterating every element. While small arrays (10–100 items) validate in microseconds, validating massive arrays adds measurable CPU overhead.
2. **Generic State Tracking:** Prebinding generic templates (`Collection<User>`) allocates entries in `\WeakMap` memory and adds lookup overhead during method execution.
3. **AST Transformation:** Transforming a file for the first time takes a few milliseconds before the result is cached on disk.

### You Choose Where to Enforce Checks

TypePHP gives you granular control so you can choose where and when to pay the performance cost:

* **Selective Path Whitelisting:** Type-check only mission-critical domain logic (`app/Domain/**`) while bypassing non-critical files completely.
* **Granular Toggles:** Turn off array checking (`inline_vars.arrays => false`) or scalar checking (`inline_vars.scalars => false`) on high-frequency internal loops while maintaining strict parameter and return boundaries (`params => true`, `returns => true`).
* **Environment Master Switch:** Disable TypePHP completely in environment builds (`enabled => false`) for 100% un-transformed, native PHP execution speed.
