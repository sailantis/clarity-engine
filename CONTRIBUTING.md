# Contributing to Clarity

Thanks for your interest in improving Clarity. This document covers the day-to-day
workflow.

## Requirements

- PHP 8.2 or newer with `ext-mbstring`
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

## Where the code lives

`Clarity\Engine\Tokenizer` and `Clarity\Engine\Compiler` are public facades;
their behavior lives in traits under `Clarity\Engine\Tokenizer\` and
`Clarity\Engine\Compiler\`. Edit the trait that owns the behavior. The facades
hold shared constants, state, and configuration, and compose the traits with
`use`.

Tokenizer (`src/Engine/Tokenizer/`):

| Trait                    | Responsibility                                                                                            |
| ------------------------ | --------------------------------------------------------------------------------------------------------- |
| `SegmentScannerTrait`    | source text → typed segments                                                                              |
| `ExpressionCoreTrait`    | expression loop: token dispatch, ternary + keyword map, function calls, dynamic `${expr}` lookups         |
| `ExpressionSupportTrait` | identifier grammar, `?`-gluing / `:`-ternary disambiguation, pipeline splitting, stateless string helpers |
| `VarChainTrait`          | var chains → segments and → PHP (read + write halves, optional-access guards)                             |
| `FilterCompilerTrait`    | filter pipelines, argument lists and the one call emitter                                                 |
| `CallableTrait`          | lambdas + filter references (`map`/`filter`/`reduce`)                                                     |
| `OperatorTestTrait`      | `in` / `is …` operator tests                                                                              |
| `CollectionLiteralTrait` | array/object literals + postfix property/index access                                                     |
| `PhpConstructTrait`      | class names: `new Foo(...)`, `Foo::member`, the `instanceof` operand                                      |

Compiler (`src/Engine/Compiler/`):

| Trait                   | Responsibility                                                        |
| ----------------------- | --------------------------------------------------------------------- |
| `CompilerCoreTrait`     | `compile()` entry point, template loading, source map + error mapping |
| `DirectiveSupportTrait` | variable scope, macros and `{% php %}` tags                           |
| `InheritanceTrait`      | static `{% extends %}` / `{% block %}` merge                          |
| `BodyCompilerTrait`     | segment loop + directive dispatch (re-entry point)                    |
| `ControlFlowTrait`      | for / if / else / set / include                                       |
| `CodeBuilderTrait`      | final class wrapper + text/context helpers                            |

Traits share the facade's `$this`. Keep public constants (`Tokenizer::TEXT`,
`Compiler::COMPILER_VERSION`, …) on the facade and reference them in traits as
`self::CONST`; declare trait-local constants in the trait that uses them. Trait
constants require **PHP 8.2**, which sets the engine's minimum version.

### Where the rule checks live

`Clarity\Engine\Policy` is the single source of truth for what a template may
reach. The Tokenizer and Compiler use the same instance for every compile-time
check.

When adding a check:

- Check the policy, not a separate flag: use `$this->allows('rawPhp')` in the
  tokenizer or `$this->policy->allows('rawPhp')` in the compiler.
- Name the required rule in the error message, for example:
  `… is not allowed by this policy. Grant the 'X' rule to allow it.`
- If the check decides **emitted code**, the policy digest already covers it — do
  **not** bump `COMPILER_VERSION` for a policy change, and do bump it for a
  grammar change.
- Add a `PolicyRuleTest` case that grants the new rule alone and
  confirms related rules remain refused. Enabling every rule would
  not catch accidental coupling.

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
