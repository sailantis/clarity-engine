# Contributing to Clarity

Thanks for your interest in improving Clarity. This document covers the day-to-day
workflow.

## Requirements

- PHP 8.1 or newer with `ext-mbstring`
- Composer

## Setup

```bash
composer install
```

## Running the tests

```bash
composer test
```

or directly:

```bash
php vendor/bin/phpunit
```

Run a single file while iterating:

```bash
php vendor/bin/phpunit tests/Engine/ControlFlowTest.php
```

### A note on the compiled-template cache

Clarity compiles templates to PHP classes and caches them. The test bootstrap
uses a temporary cache directory, but if you are debugging engine internals by
hand and a change appears not to take effect, clear it:

```bash
# Windows
Remove-Item "$env:TEMP\clarity_test_cache_static" -Recurse -Force

# macOS / Linux
rm -rf /tmp/clarity_test_cache_static
```

The per-template cache is invalidated by template source changes and by
`Compiler::COMPILER_VERSION`. Editing engine source alone does not invalidate an
already-compiled template, so bump `COMPILER_VERSION` when you change emitted
semantics.

## Code style

- Follow the surrounding code; match its naming and comment density.
- Keep engine internals prefixed with `__c_` (`Compiler::INTERNAL_PREFIX`).
- Add a test for every behaviour change. Tests live in `tests/Engine/`.

## Adding a filter or function

1. Register it in `Registry::registerBuiltins()`.
2. Add an entry to `.phpstorm.meta.php` so editors autocomplete it.
3. Update `docs/02-filters-and-functions.md`.

`RegistryConsistencyTest` fails if the registry and the meta file drift apart.

## Documentation

- User-facing guides live in `docs/`.
- The API reference under `docs/api/` is **generated**:

  ```bash
  php scripts/generate-api-docs.php
  ```

  Do not edit `docs/api/` by hand.

## Submitting changes

- Keep commits focused and describe the _why_ in the message.
- Make sure `composer test` is green.
- Open a Pull Request against `main`.

## Reporting bugs

Open an issue at <https://github.com/sailantis/clarity-engine/issues> with a
minimal template that reproduces the problem and the exact error output.
