# Exception Handling

If you run TypePHP in live production applications especially for validating external HTTP API payloads, webhooks, or dynamic database records and catching `TypePHP\Exception\TypeError` gives you a clean way to intercept data validation failures gracefully without letting exceptions crash your application.

TypePHP provides a custom exception class, `TypePHP\Exception\TypeError`, designed for both polymorphic compatibility with PHP's native type system and surgical error handling at application boundaries.

---

## The Exception Class Hierarchy

All contract violations in TypePHP instantiate `TypePHP\Exception\TypeError`, which extends PHP's native `\TypeError`:

```
\Throwable
    └── \Error
          └── \TypeError
                └── TypePHP\Exception\TypeError
```

---

## Dual Catching Modes

Because `TypePHP\Exception\TypeError` extends native `\TypeError`, you can choose how broadly or narrowly to catch type failures:

### 1. Polymorphic Catching (`catch (\TypeError $e)`)

Catches both native PHP engine type errors (such as passing a string into a native `int` type hint) and TypePHP contract failures:

```php
try {
    processUser(-50);
} catch (\TypeError $e) {
    // Catches both native PHP TypeErrors and TypePHP contract failures!
}
```

### 2. Specific Contract Catching (`catch (\TypePHP\Exception\TypeError $e)`)

Specifically catches TypePHP contract violations while allowing native PHP engine errors to bubble up separately:

```php
use TypePHP\Exception\TypeError as TypePHPTypeError;

try {
    processUser(-50);
} catch (TypePHPTypeError $e) {
    // Catches ONLY TypePHP contract violations!
} catch (\TypeError $e) {
    // Catches native PHP engine type errors!
}
```

---

## Call-Site Trace Attribution

When a function parameter or callback argument fails type validation, TypePHP automatically rewrites the exception's file and line attributes.

Instead of blaming internal library files, `ErrorFactory` filters out internal frames and attributes `$e->file` and `$e->line` directly to **the exact line of code in the caller file where the invalid argument was passed**, matching native PHP engine behavior.

---

## HTTP API Payload Validation (Framework-Agnostic 422 Responses)

`TypePHP\Exception\TypeError` is especially useful when validating external HTTP request payloads at application boundaries. 

When dynamic request data fails contract validation, you can catch `TypePHPTypeError` and return a clean HTTP `422 Unprocessable Entity` response:

```php
namespace App\Http;

use App\Services\UserService;
use TypePHP\Exception\TypeError as TypePHPTypeError;

class UserApiController
{
    public function __construct(private UserService $userService) {}

    public function handleRequest(array $requestData): array
    {
        try {
            // UserService enforces @param array{id: positive-int, email: non-empty-string}
            $this->userService->registerUser($requestData);

            return [
                'status' => 200,
                'body' => ['message' => 'User registered successfully'],
            ];
        } catch (TypePHPTypeError $e) {
            // Convert TypePHP contract failure into an HTTP 422 response
            return [
                'status' => 422,
                'body' => [
                    'error' => 'Unprocessable Entity',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }
}
```

---

## Human-Readable Error Formatting

TypePHP's `TypeFormatter` formats invalid values into descriptive human-readable strings inside exception messages:

* **Integers:** `negative int (-50)`, `zero int (0)`, `int (42)`
* **Strings:** `empty string ('')`, `string 'this_is_a_very_lo...'`
* **Booleans:** `bool (true)`, `bool (false)`
* **Arrays:** `empty array ([])`, `list (3 items)`, `associative array (key 'id')`
* **Objects:** Class FQCN (e.g., `App\Models\Product`)
