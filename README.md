<h1 align="center">TypePHP</h1>

<p align="center">
  <b>No transpilation. No build steps. No C-extensions.<br>
  Drop TypePHP into your existing codebase and let your DocBlocks scream when types fail.</b>
</p>

<p align="center">
	<a href="https://github.com/typephp-php/typephp/actions"><img src="https://github.com/typephp-php/typephp/actions/workflows/ci.yml/badge.svg" alt="Build Status"></a>
	<a href="https://packagist.org/packages/typephp/typephp"><img src="https://img.shields.io/packagist/v/typephp/typephp.svg?style=flat&color=blue" alt="Latest Stable Version"></a>
	<a href="https://packagist.org/packages/typephp/typephp"><img src="https://img.shields.io/packagist/dt/typephp/typephp.svg?style=flat&color=green" alt="Total Downloads"></a>
	<a href="https://choosealicense.com/licenses/mit/"><img src="https://img.shields.io/packagist/l/typephp/typephp.svg?style=flat" alt="License"></a>
	<a href="https://packagist.org/packages/typephp/typephp"><img src="https://img.shields.io/packagist/php-v/typephp/typephp.svg?style=flat" alt="PHP Version"></a>
	<a href="https://phpstan.org/"><img src="https://img.shields.io/badge/PHPStan-Level%20MAX-brightgreen.svg?style=flat" alt="PHPStan Level MAX"></a>
</p>

------

TypePHP is a transparent, pure-PHP runtime type checker. You don't have to refactor a single line of your codebase, setup complex build toolchains, or compile C-extensions and simply run your existing code, and TypePHP will enforce your extended PHPDoc contracts (generics, array shapes, `key-of`/`value-of` extractions, and scalar refinements) dynamically at runtime.


**[Read the full TypePHP documentation »](https://typephp-php.github.io/typephp/)**

**[Quick Start Guide »](https://typephp-php.github.io/typephp/getting-started/quick-start)**

## Documentation

All the documentation lives on the [typephp-php.github.io/typephp website](https://typephp-php.github.io/typephp/):

* [Getting Started & Installation Guide](https://typephp-php.github.io/typephp/getting-started/installation)
* [Quick Start Guide](https://typephp-php.github.io/typephp/getting-started/quick-start)
* [Architecture: How It Works](https://typephp-php.github.io/typephp/architecture/how-it-works)
* [Core Concepts: Function Contracts](https://typephp-php.github.io/typephp/core-concepts/function-contracts)
* [Core Concepts: Generics & Bounds](https://typephp-php.github.io/typephp/core-concepts/generics-and-bounds)
* [Supported Types: Arrays & Shapes](https://typephp-php.github.io/typephp/supported-types/arrays-and-shapes)
* [Troubleshooting & FAQ](https://typephp-php.github.io/typephp/advanced/troubleshooting)

## Inspiration

TypePHP is conceptually inspired by Python's [Beartype](https://github.com/beartype/beartype), but bringing transparent runtime type enforcement for type annotations to the PHP ecosystem without any decorators or attributes.

## Sponsors

Want to support the open-source development and maintenance of TypePHP? [Sponsor TypePHP me on GitHub »](https://github.com/sponsors/rcalicdan)

## Contributing

Any contributions are welcome. Feel free to open issues or submit pull requests on GitHub.

## License

TypePHP is open-source software licensed under the [MIT License](https://choosealicense.com/licenses/mit/).
