# Type Aliases 

TypePHP supports declaring local type aliases (`@phpstan-type` / `@psalm-type`) and importing type aliases from other classes (`@phpstan-import-type` / `@psalm-import-type`). This allows you to centralize and reuse complex array shapes, unions, and generic structures across your application.

> **Tooling Compatibility:** Both PHPStan syntax (`@phpstan-type`, `@phpstan-import-type`) and Psalm syntax (`@psalm-type`, `@psalm-import-type`) are parsed identically and enforced at runtime.

---

## Local Type Aliases (`@phpstan-type` / `@psalm-type`)

Declare a local type alias above a class or interface definition using `@phpstan-type` or `@psalm-type`. Once declared, you can reference the alias in any parameter, return, or `@var` docblock within that class:

```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Declare local type aliases for this class
 *
 * @phpstan-type UserShape array{id: positive-int, username: non-empty-string, role: 'admin'|'editor'|'viewer'}
 * @psalm-type UserStatus 'active'|'pending'|'archived'
 */
class UserService
{
    /**
     * @param UserShape $user
     * @param UserStatus $status
     */
    public function updateUser(array $user, string $status): bool
    {
        return true;
    }
}

$service = new UserService();

// Valid Call
$service->updateUser(['id' => 10, 'username' => 'Alice', 'role' => 'admin'], 'active');

// Invalid Call ($id is negative, violating UserShape)
$service->updateUser(['id' => -5, 'username' => 'Alice', 'role' => 'admin'], 'active');
// Throws: TypeError: UserService::updateUser(): Argument $user['id'] must be of type positive-int
```

---

## Imported Type Aliases (`@phpstan-import-type` / `@psalm-import-type`)

To share type aliases across multiple classes, declare your aliases in a central class (e.g. `GlobalTypes`) and import them into other classes using `@phpstan-import-type` or `@psalm-import-type`:

### Central Type Definitions (`GlobalTypes.php`)

```php
namespace App\Types;

/**
 * Shared Type Definitions
 *
 * @phpstan-type SharedUserShape array{id: positive-int, email: non-empty-string}
 * @psalm-type SharedRole 'admin'|'user'
 */
class GlobalTypes
{
}
```

### Importing the Shared Type Alias (`UserApi.php`)

```php
namespace App\Api;

use App\Types\GlobalTypes;

/**
 * Import shared types from GlobalTypes
 *
 * @phpstan-import-type SharedUserShape from GlobalTypes
 * @psalm-import-type SharedRole from GlobalTypes
 */
class UserApi
{
    /**
     * @param SharedUserShape $user
     * @param SharedRole $role
     */
    public function saveUser(array $user, string $role): bool
    {
        return true;
    }
}

$api = new UserApi();

// Valid Call
$api->saveUser(['id' => 42, 'email' => 'alice@example.com'], 'admin');

// Invalid Call ($email is empty string)
$api->saveUser(['id' => 42, 'email' => ''], 'admin');
// Throws: TypeError: UserApi::saveUser(): Argument $user['email'] must be of type non-empty-string
```

---

## Importing with Local Alias Renaming (`as`)

Use the `as` keyword to rename an imported type alias locally to prevent naming collisions or improve local code clarity:

```php
namespace App\Services;

use App\Types\GlobalTypes;

/**
 * Import and rename the shared type alias
 *
 * @phpstan-import-type SharedUserShape from GlobalTypes as LocalUserShape
 */
class AccountService
{
    /**
     * @param LocalUserShape $payload
     */
    public function createAccount(array $payload): void
    {
        // ...
    }
}
```

---

## Naming Collisions (When `as` is Omitted)

If a class defines a local `@phpstan-type Status` AND imports a type alias with the exact same name (`@phpstan-import-type Status from GlobalTypes`) **without using the `as` keyword**:

1. **Resolution Priority:** The imported type alias will **overwrite** the local type alias.
2. **Best Practice:** Always use the `as` keyword whenever an imported alias name collides with a local alias name to make your type contracts explicit:

```php
/**
 * Local alias: 'active'|'pending'
 * @phpstan-type Status 'active'|'pending'
 *
 * Imported alias renamed to GlobalStatus to prevent overwriting local 'Status'
 * @phpstan-import-type Status from GlobalTypes as GlobalStatus
 */
class OrderService
{
    // ...
}
```

---

## Chained Type Alias Imports

TypePHP recursively resolves multi-level type alias import chains down to the root definition:

* **Level 1 (`GlobalTypes`):** Defines `@phpstan-type UserShape array{id: positive-int}`.
* **Level 2 (`MidService`):** Imports `@phpstan-import-type UserShape from GlobalTypes`.
* **Level 3 (`FinalService`):** Imports `@phpstan-import-type UserShape from MidService as LocalShape`.

When `FinalService` validates `$payload` against `LocalShape`, TypePHP automatically follows the 3-class import chain back to `GlobalTypes` and enforces `array{id: positive-int}`!

```php
$service = new FinalService();

// Valid Call
$service->process(['id' => 100]);

// Invalid Call (id is negative)
$service->process(['id' => -5]);
// Throws: TypeError: FinalService::process(): Argument $payload['id'] must be of type positive-int
```
