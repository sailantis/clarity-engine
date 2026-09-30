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

`Clarity\Engine\Tokenizer` and `Clarity\Engine\Compiler` are the public entry
points, but the bulk of their behaviour lives in **traits** under
`Clarity\Engine\Tokenizer\` and `Clarity\Engine\Compiler\`. The facades keep the
constants, the mutable state and the configuration setters, and compose the
traits with `use`. When changing behaviour, edit the trait that owns it; the
facade only declares what the traits share.

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
| `DirectiveSupportTrait` | variable scope, macros and `{% php %}` regions                        |
| `InheritanceTrait`      | static `{% extends %}` / `{% block %}` merge                          |
| `BodyCompilerTrait`     | segment loop + directive dispatch (re-entry point)                    |
| `ControlFlowTrait`      | for / if / else / set / include                                       |
| `CodeBuilderTrait`      | final class wrapper + text/context helpers                            |

Each trait is a plain `trait`; the facade, the traits and the shared state all
run against the same `$this`, so the split is transparent. The public constants
(`Tokenizer::TEXT`, `Compiler::COMPILER_VERSION`, …) stay on the facade and are
read from the traits as `self::CONST`; trait-local constants are declared in the
trait that uses them (a trait constant requires **PHP 8.2**, which is why the
engine's minimum is 8.2 rather than 8.1).

### Where the capability checks live

`Clarity\Engine\Policy` is the one object that answers _what may this template
reach_. Every compile-time check asks it — the Tokenizer keeps a reference and
the Compiler passes the same instance to it, so a capability cannot be granted in
one half of the compiler and missed in the other.

When adding a check:

- Ask the POLICY, not a flag. `$this->allows('rawPhp')` in the tokenizer or
  `$this->policy->allows('rawPhp')` in the compiler. There is no sandbox boolean
  any more, and a new one would be a second source of truth.
- Make the message name the grant that would fix it, in the form
  `… is not allowed by this policy. Grant the 'X' capability to allow it.`
- If the check decides **emitted code**, the policy digest already covers it — do
  **not** bump `COMPILER_VERSION` for a policy change, and do bump it for a
  grammar change.
- `Policy` has its own test file; a new capability also needs a case in
  `PolicyCapabilityTest` that grants it alone and asserts its neighbours are still
  refused. The point of the split is that a grant is independent, so a test that
  grants everything proves nothing.

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
