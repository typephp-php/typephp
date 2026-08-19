# Contributing to TypePHP

Thank you for showing interest in contributing to TypePHP! Contributions are essential for building a robust, type-safe ecosystem for the PHP community.

This library is designed to be a reliable foundation for high-performance applications. To achieve this, it maintains rigorous standards for code quality, developer experience, and static analysis compatibility.

---

## Development Workflow

To ensure consistency across the codebase, this repository requires the following workflow:

1. **Fork and Branch**: Fork the repository and create a feature branch from `main`.
2. **Dependencies**: Install development tools using `composer install`.
3. **Linting & Code Formatting Authority (Laravel Pint)**: This project follows strict PSR-12 standards. Laravel Pint is the **sole authoritative linter and formatter** for the entire codebase:
   ```bash
   ./vendor/bin/pint
   ```
4. **Static Analysis Authority (PHPStan)**: Code must pass **PHPStan at Level MAX** (`treatPhpDocTypesAsCertain: false`):
   ```bash
   ./vendor/bin/phpstan analyse
   ```
5. **Testing**: This project uses Pest. Ensure all tests pass completely:
   ```bash
   ./vendor/bin/pest
   ```
6. **Strict Typing**: Every PHP file must begin with `declare(strict_types=1);`.

---

## Tooling Authority & Interoperability Policy

* **Laravel Pint is the Authoritative Linter & Formatter**: All code styling and linting rules are defined strictly in `pint.json`. No external style linter overrides Pint.
* **PHPStan is the Authoritative Static Analyzer**: PHPStan configured at Level MAX is the official gatekeeper for type safety and code quality in TypePHP. All contributions must pass PHPStan checks without errors.
* **Tooling Interoperability (Psalm, Mago, Rector, PHP-CS-Fixer, etc.)**: Secondary analyzers and tools (such as Psalm, Mago, Rector, and PHP-CS-Fixer) are integrated into the test environment solely for **interoperability verification** and ensuring that TypePHP's runtime stream wrapper and AST transformations stand down properly and do not deadlock or conflict with external static analysis engines.

---

## Pull Request Process

1. **Start with an Issue**: Before writing code, please open an issue to discuss the bug or proposed feature.
2. **Tests are Required**: Every Pull Request must include automated Pest tests that cover the new logic and prevent regressions.
3. **Keep Code Clean**: Run `./vendor/bin/pint`, `./vendor/bin/phpstan analyse`, and `./vendor/bin/pest` before submitting your PR.

---

The TypePHP ecosystem thanks you for your time and effort!