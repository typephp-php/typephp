---
layout: home

hero:
  name: "TypePHP"
  text: "Transparent Runtime Type Enforcement"
  tagline: "No transpilation. No build steps. No C-extensions. Just 100% pure PHP that makes your existing DocBlocks scream the moment types fail."
  actions:
    - theme: brand
      text: "Get Started →"
      link: /getting-started/installation
    - theme: alt
      text: "View on GitHub"
      link: https://github.com/typephp-php/typephp

features:
  - title: "Zero Production Overhead"
    details: "Install as a development dependency to enforce strict types during local testing and CI/CD pipelines, guaranteeing absolute zero performance cost in live production environments."
  - title: "No Transpilation or C-Extensions"
    details: "Operates 100% in pure PHP user-land using native stream wrappers and AST transformations. No build scripts, Node.js tools, or C-extensions required."
  - title: "True Runtime Generics"
    details: "Binds generic template types to specific object instances dynamically using native WeakMap memory tracking."
  - title: "Arrays, Shapes & Extractions"
    details: "Deeply validates sequential lists, typed arrays, array shapes, and key-of / value-of constant extractions out of the box."
---

::: tip Pure PHP • Zero Transpilation • Zero Build Steps
**You don't have to change a single line of code, and you don't need a compilation build toolchain.** TypePHP operates entirely in native PHP user-land and no custom PHP binaries, C-extensions, or Node.js transpilers needed. Drop TypePHP into your existing project, run your code, and your DocBlocks will instantly start screaming at runtime when dynamic data violates a type contract.
:::

## See It In Action

TypePHP operates entirely in user-land using native stream wrappers and AST transformations. Because it requires no C-extensions or FFI, you can drop it into any PHP 8.1+ project or web framework effortlessly. It reads your existing PHPDoc annotations and enforces them the moment your code runs.

### Real-World Framework Guard Rails (Laravel / Symfony)
Prevent dynamic data bugs from leaking into database queries or API responses:

```php
namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    /**
     * @return list<int>
     */
    public function assignableRoles(): array
    {
        if ($this->isSuperAdmin()) {
            // Bug! Returns an array of Role Enum instances instead of integers:
            return Role::cases(); 
        }

        return [Role::STAFF->value];
    }
}

// Executing $user->assignableRoles() throws:
// TypePHP\Exception\TypeError: User::assignableRoles(): Return value[0] must be of type int, App\Enums\Role returned
```

---

### True Runtime Generics
Define generic templates and TypePHP will track their state in memory per object instance:

```php
/**
 * @template T
 */
class Collection 
{
    /** @param T $item */
    public function add(mixed $item): void { /* ... */ }
}

// Prebind T = User to this specific object instance
/** @var Collection<User> $users */
$users = new Collection();

$users->add(new User('Alice')); // Valid

$users->add(new Product('SKU-100')); 
// Throws TypeError: Argument $item (template T = User) must be of type User, Product given
```

---

### Array Shapes & Key/Value Extractions
Enforce strict associative array structures and constant extractions:

```php
namespace App\Services;

use App\Database\DriverManager;

/**
 * @phpstan-type ConnectionParams array{
 *     driver: key-of<DriverManager::DRIVER_MAP>,
 *     driverClass?: value-of<DriverManager::DRIVER_MAP>
 * }
 */
class DatabaseService
{
    /**
     * @param ConnectionParams $params
     */
    public function connect(array $params): void
    {
        // ...
    }
}

$service = new DatabaseService();

$service->connect(['driver' => 'pdo_mysql']); // Valid

$service->connect(['driver' => 'pdo_invalid']);
// Throws TypeError: Argument $params['driver'] must be a key of DriverManager::DRIVER_MAP
```

---

## Precise Call-Site Trace Attribution

A common problem with AST code injection is that adding new statements pushes subsequent code down, causing line numbers in stack traces to drift out of sync.

TypePHP solves this with **Zero Line-Drift Formatting**. Injected guard rails are squashed onto single lines and appended directly to existing code blocks. **Line numbers in your source files remain 100% identical before and after transformation.**

When a type contract fails, web exception handlers (**Laravel Ignition, Whoops, Symfony ErrorHandler**) and CLI test runners (**Pest, PHPUnit**) point **directly to the exact line number** where the invalid assignment or return value occurred in your application code:

### Web Framework Trace (Laravel Ignition)
![Laravel Ignition Exception Trace](/laravel-error-screen.png)

### CLI Test Runner Trace (Pest PHP)
![Pest CLI Exception Trace](/pest-error-screen.png)
