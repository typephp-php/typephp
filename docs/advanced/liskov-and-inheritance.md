# Liskov Substitution & DocBlock Inheritance

TypePHP respects the Liskov Substitution Principle (LSP). Child classes, implemented interfaces, traits, and abstract methods automatically inherit PHPDoc type contracts without requiring you to duplicate docblock annotations.

---

## Abstract Method & Interface Contract Inheritance

When a child class implements an interface or extends an abstract parent class, it inherits all `@param` and `@return` contracts declared on the parent methods:

```php
<?php

declare(strict_types=1);

namespace App\Services;

interface UserRepositoryInterface
{
    /**
     * Interface Contract
     *
     * @param positive-int $id
     * @return array{id: positive-int, username: non-empty-string}
     */
    public function findUser(int $id): array;
}

class UserRepository implements UserRepositoryInterface
{
    // No docblock declared here! Inherits contract from UserRepositoryInterface.
    public function findUser(int $id): array
    {
        return ['id' => $id, 'username' => 'Alice'];
    }
}

$repo = new UserRepository();

// Valid Call
$repo->findUser(42);

// Invalid Call ($id is negative)
$repo->findUser(-5);
// Throws: TypeError: UserRepository::findUser(): Argument $id must be of type positive-int
```

---

## Deep Multi-Level Inheritance Chains

TypePHP recursively traverses inheritance trees down to any depth across classes, interfaces, and traits:

### 4-Level Class Inheritance (`Level 1` $\rightarrow$ `Level 2` $\rightarrow$ `Level 3` $\rightarrow$ `Level 4`)

```php
abstract class DeepLevel1
{
    /**
     * @param positive-int $id
     * @return non-empty-string
     */
    abstract public function process(int $id): string;
}

abstract class DeepLevel2 extends DeepLevel1 {}
abstract class DeepLevel3 extends DeepLevel2 {}

class DeepLevel4Executor extends DeepLevel3
{
    // Level 4 concrete class with NO docblock! Inherits from Level 1 root.
    public function process(int $id): string
    {
        return "item_{$id}";
    }
}

$executor = new DeepLevel4Executor();

// $id = -50 violates Level 1 abstract parent's @param positive-int
$executor->process(-50);
// Throws: TypeError: DeepLevel4Executor::process(): Argument $id must be of type positive-int
```

### Deep Interface Chains (`RootInterface` $\leftarrow$ `MidInterface` $\leftarrow$ `ChildInterface`)

```php
interface RootInterface
{
    /**
     * @param positive-int $code
     */
    public function execute(int $code): bool;
}

interface MidInterface extends RootInterface {}
interface ChildInterface extends MidInterface {}

class InterfaceExecutor implements ChildInterface
{
    // Implementation with NO docblock! Inherits from RootInterface.
    public function execute(int $code): bool
    {
        return true;
    }
}

$executor = new InterfaceExecutor();

// $code = -10 violates RootInterface's @param positive-int
$executor->execute(-10);
// Throws: TypeError: InterfaceExecutor::execute(): Argument $code must be of type positive-int
```

---

## PHP 8.4 Interface Property & Hook Inheritance

In PHP 8.4, interfaces can declare property hooks (`{ get; set; }`). Implementing classes inherit property `@var` contracts directly from the interface:

```php
interface UserInterface
{
    /**
     * @var positive-int
     */
    public int $id { get; }

    /**
     * @var non-empty-string
     */
    public string $username { get; set; }
}

class User implements UserInterface
{
    // Inherits @var positive-int from UserInterface
    public int $id {
        get => $this->_id;
    }
    public int $_id = 10;

    // Inherits @var non-empty-string from UserInterface
    public string $username {
        get => $this->_username;
        set => $this->_username = trim($value);
    }
    public string $_username = 'Alice';
}

$user = new User();

// Invalid Read ($user->_id = -5 violates interface's inherited @var positive-int)
$user->_id = -5;
$value = $user->id;
// Throws: TypeError: Property User::$id must be of type positive-int

// Invalid Write ($username = '' violates interface's inherited @var non-empty-string)
$user->username = '';
// Throws: TypeError: Property User::$username must be of type non-empty-string
```

---

## Trait Instance & Static Property Inheritance

Instance and static properties declared in Traits inherit their `@var` docblock contracts when used by a class:

```php
trait IdentifiableTrait
{
    /**
     * @var positive-int
     */
    public int $traitId = 10;

    /**
     * @var non-empty-string
     */
    public static string $traitVersion = '1.0';

    public function setTraitId(int $val): void
    {
        $this->traitId = $val;
    }

    public static function setTraitVersion(string $val): void
    {
        self::$traitVersion = $val;
    }
}

class AppModel
{
    use IdentifiableTrait;
}

$model = new AppModel();

// $val = -50 violates Trait's @var positive-int
$model->setTraitId(-50);
// Throws: TypeError: Property AppModel::$traitId must be of type positive-int

// $val = '' violates Trait's @var non-empty-string
AppModel::setTraitVersion('');
// Throws: TypeError: Property AppModel::$traitVersion must be of type non-empty-string
```

---

## Trait Inheritance Across Parent-Child Classes

When a parent class uses a Trait (`ParentClass` uses `LoggerTrait`), any child class extending the parent (`ChildClass extends ParentClass`) automatically inherits all `@param`, `@return`, and `@var` contracts declared on the parent's Trait:

```php
trait LoggerTrait
{
    /**
     * @param positive-int $level
     * @return non-empty-string
     */
    public function logMessage(int $level, string $msg): string
    {
        return "log_{$level}_{$msg}";
    }
}

class ParentService
{
    use LoggerTrait; // Parent class uses trait
}

class ChildService extends ParentService
{
    // Child class inherits logMessage() without declaring a docblock
}

$child = new ChildService();

// Valid Call
$child->logMessage(10, 'boot');

// Invalid Call ($level = -50 violates inherited Trait's @param positive-int)
$child->logMessage(-50, 'boot');
// Throws: TypeError: ChildService::logMessage(): Argument $level must be of type positive-int
```

---

## Trait Method Aliasing (`use Trait { oldMethod as newMethod; }`)

When a class uses a Trait and renames a method using PHP's trait `as` alias syntax, TypePHP inspects trait alias mappings and automatically inherits the original Trait method's DocBlock contracts onto the aliased method:

```php
trait LoggerTrait
{
    /**
     * @param positive-int $level
     * @param non-empty-string $message
     */
    public function logEvent(int $level, string $message): bool
    {
        return true;
    }
}

class AuditService
{
    use LoggerTrait {
        logEvent as recordAuditLog; // Aliases method from trait!
    }
}

$service = new AuditService();

// Valid Call
$service->recordAuditLog(1, 'audit_ok');

// Invalid Call ($level = -1 violates inherited Trait's @param positive-int)
$service->recordAuditLog(-1, 'audit_ok');
// Throws: TypeError: Argument $level must be of type positive-int
```

---

## Trait Conflict Resolution (`insteadof`)

When a class uses multiple traits with identical method names, PHP requires resolving the collision with `insteadof`. TypePHP respects `insteadof` precedence, enforcing contracts strictly from the selected trait:

```php
trait PrimaryLogger
{
    /**
     * @param positive-int $level
     * @param non-empty-string $message
     */
    public function log(int $level, string $message): string
    {
        return "primary: {$level} - {$message}";
    }
}

trait SecondaryLogger
{
    /**
     * @param negative-int $level
     * @param string $message
     */
    public function log(int $level, string $message): string
    {
        return "secondary: {$level} - {$message}";
    }
}

class LoggingService
{
    // PrimaryLogger::log is selected instead of SecondaryLogger
    use SecondaryLogger, PrimaryLogger {
        PrimaryLogger::log insteadof SecondaryLogger;
        SecondaryLogger::log as secondaryLog;
    }
}

$service = new LoggingService();

// 1. Primary log() enforces positive-int and non-empty-string from PrimaryLogger
$service->log(10, 'server_boot'); // Valid
// $service->log(-5, 'server_boot'); // Throws: TypeError: Argument $level must be of type positive-int

// 2. Aliased secondaryLog() enforces negative-int from SecondaryLogger
$service->secondaryLog(-20, 'server_shutdown'); // Valid
// $service->secondaryLog(20, 'server_shutdown'); // Throws: TypeError: Argument $level must be of type negative-int
```

---

## Partial Parameter Overriding (Gap-Filling)

If a child class overrides a method and provides a docblock for **only some** parameters, TypePHP fills in the missing parameter contracts from the parent class or interface:

```php
class BaseService
{
    /**
     * Parent defines contracts for $id and $name
     *
     * @param positive-int $id
     * @param non-empty-string $name
     */
    public function update(int $id, string $name): bool
    {
        return true;
    }
}

class ChildService extends BaseService
{
    /**
     * Child overrides ONLY $name to restrict allowed string literals!
     *
     * @param 'Alice'|'Bob' $name
     */
    public function update(int $id, string $name): bool
    {
        return true;
    }
}

$service = new ChildService();

// Valid Call
$service->update(10, 'Alice');

// Invalid $id (-5 violates parent's inherited @param positive-int)
$service->update(-5, 'Alice');
// Throws: TypeError: ChildService::update(): Argument $id must be of type positive-int

// Invalid $name ('Charlie' violates child's local @param 'Alice'|'Bob')
$service->update(10, 'Charlie');
// Throws: TypeError: ChildService::update(): Argument $name must be of type ('Alice' | 'Bob')
```

---

## Parameter Renaming ($id → $userId) & Position Shift Disambiguation

When a child class, constructor, or trait implementation overrides an ancestor method, parameter positions may shift when new parameters are inserted, or parameter names may be renamed.

TypePHP resolves parameter contract inheritance using **3-Tier Name & Position Disambiguation**:

1. **Name-First Matching:** If a parameter name in the child method matches a parameter name in the parent class (e.g. `$container`), the parent's contract is mapped to that parameter regardless of its position index in the child.
2. **Position Fallback on Renamed Parameters:** If a parameter is renamed in the child class (e.g. `$id` $\rightarrow$ `$userId`), TypePHP maps the contract using its position index.
3. **Candidate Disambiguation (Shift Protection):** If a child class inserts a new parameter at index 0 (shifting all subsequent parameters down), TypePHP **verifies that the candidate child parameter does not already exist in the parent under its own name**. This prevents parent parameter contracts from accidentally mis-mapping onto shifted child parameters!

```php
class BaseRegistry
{
    /**
     * Parent constructor has 3 params:
     * Index 0: $container
     * Index 1: $definitions
     * Index 2: $repositoryMap
     *
     * @param array<string, string> $definitions
     * @param array<string, string> $repositoryMap
     */
    public function __construct(
        ContainerInterface $container,
        array $definitions,
        array $repositoryMap
    ) {}
}

class SalesChannelRegistry extends BaseRegistry
{
    /**
     * Child inserts $prefix at Index 0 (shifting $container to Index 1),
     * and renames $definitions -> $definitionMap at Index 2!
     *
     * @param array<string, string> $definitionMap
     * @param array<string, string> $repositoryMap
     */
    public function __construct(
        string $prefix,
        ContainerInterface $container,
        array $definitionMap,
        array $repositoryMap
    ) {
        parent::__construct($container, $definitionMap, $repositoryMap);
    }
}

// TypePHP correctly keeps $container (Index 1 in child) untouched,
// rather than mis-mapping parent's @param array $definitions (Index 1 in parent) onto it!
new SalesChannelRegistry('sales_channel.', new Container(), ['prod' => 'ProductDef'], ['prod' => 'ProductRepo']);
```

---

## Trait & Interface Contract Fusion

When a class implements an Interface and fulfills its methods by using a Trait:

```php
interface ExecutorInterface
{
    /**
     * @param positive-int $code
     * @return non-empty-string
     */
    public function execute(int $code): string;
}

trait ExecutorTrait
{
    // Trait method fulfills the Interface with NO docblock
    public function execute(int $code): string
    {
        return "code_{$code}";
    }
}

class AppExecutor implements ExecutorInterface
{
    use ExecutorTrait; // Trait method fulfills Interface contract
}

$app = new AppExecutor();

// $code = -5 violates ExecutorInterface's @param positive-int
$app->execute(-5);
// Throws: TypeError: AppExecutor::execute(): Argument $code must be of type positive-int
```

---

## In-Memory Inheritance Caching Performance

To ensure that resolving complex inheritance chains introduces zero perceptible latency, TypePHP uses a **3-tier in-memory static caching architecture**:

1. **End-Result Contract Cache (`ContractParser::$cache`):** Caches the fully resolved parameter, return, template, and alias metadata per method string (e.g. `"UserRepository::find"`).
2. **Reflection Hierarchy Cache (`HierarchyResolver`):** Caches the class, parent, interface, and trait Reflection tree (`[Child, Parent, GrandParent, Interface1, Interface2]`). If a class has 20 methods, its inheritance tree is inspected **only once**.
3. **Property Contract Cache (`ContractParser::$propertyCache`):** Caches resolved property `@var` types (`"UserProfile::$id"`).

### How It Executes at Runtime

When you call `$userRepo->find(42)` 1,000 times in a loop:
* **Invocation #1:** TypePHP builds the `UserRepository` inheritance tree, parses the docblocks, merges parent gaps, and caches the resolved contract in static RAM.
* **Invocations #2 through #1,000:** TypePHP fetches the pre-resolved contract directly from static RAM in **$O(1)$ constant nanoseconds**—zero Reflection traversal occurs!

---

## Vendor DocBlock Isolation

TypePHP protects your application from third-party vendor docblock bugs using **Vendor Isolation**:

* If a parent class or interface is located inside an excluded folder (such as `/vendor/`), TypePHP **ignores its inherited docblocks**.
* This prevents third-party package docblock errors or outdated annotations from causing unexpected `TypeError` exceptions in your application code.
```
