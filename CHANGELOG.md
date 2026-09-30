# Changelog

All notable changes to `sailantis/clarity-engine` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Internal architecture: the compiler and tokenizer are composed from
  traits.** `Clarity\Engine\Tokenizer` (previously a single ~4 200-line class)
  and `Clarity\Engine\Compiler` (previously ~2 100 lines) keep their public API,
  their constants and their mutable state, but the behaviour now lives in eight
  and six focused traits under `Clarity\Engine\Tokenizer\` and
  `Clarity\Engine\Compiler\`. The expression loop, its support grammar, the
  var-chain parser/emitter, the filter & callable compiler and the operator
  tests each live in their own trait; the compiler's phases are split into
  `CompilerCoreTrait`, `DirectiveSupportTrait`, `InheritanceTrait`,
  `BodyCompilerTrait`, `ControlFlowTrait` and `CodeBuilderTrait`. Every source
  file is now well under 50 KB. The public API, the constants
  (`Tokenizer::TEXT`, `Compiler::COMPILER_VERSION`, …) and the emitted PHP are
  unchanged, so this is transparent to consumers and does not bump
  `COMPILER_VERSION`.
- **Cold-deploy memory drops by ~40%.** The process high-water mark of the
  first request after a deploy is set by the largest single source file PHP
  compiles, so splitting the two monolithic files lowers it: measured with the
  view-engine harness on the bench VM (fresh process, OPcache on, cold template
  cache), Clarity's peak fell from 2.64 MB to 1.59 MB on the sample page —
  now the joint-lowest of the engines compared, level with the non-compiling
  baseline — while steady-state render time is unchanged. The one-off first
  render rises by about a millisecond (the extra wiring is ~2.5% more source).
- **Minimum PHP is now 8.2 (was 8.1).** Traits can only declare constants as of
  PHP 8.2, and the tokenizer/compiler traits carry their own private constants
  (`OPERATOR_TESTS`, `RE_FOR_IN`, `RESERVED_NAMES`, …). `composer.json`, the
  README, the getting-started and troubleshooting guides and the CI matrix were
  updated to match; the PHP 8.1 job was dropped.
- Documentation consistently calls the non-sandboxed mode **PHP mode** (open
  mode), the term the README and the API reference already used. The remaining
  cross-links now point at the `PHP Mode` heading. The source docblocks say
  "PHP mode" too, and the generated API reference was regenerated from them.
  The runtime guardrail message reads "blocked in PHP mode" as well.

### Fixed

- The getting-started guide named the wrong Composer package
  (`clarity/engine`); it now matches the real package name,
  `sailantis/clarity-engine`.
- The API-reference generator now links a method to the file it is **declared**
  in. A method composed from a trait is declared in the trait's file, so the
  previous class-relative anchor pointed at the wrong line.

## [0.1.1]

### Fixed

- The Composer package archive no longer excludes `.phpstorm.meta.php`, so
  editor completion, hover and signature help for Clarity filters, functions and
  directives now reach projects that install the engine from Packagist. The file
  was previously stripped from the distribution by an over-broad `export-ignore`.

## [0.1.0]

### Added

- **`{% for %} … {% else %}`** — a loop may declare a branch that renders when
  the sequence is empty, matching Twig. Works for arrays, mappings and ranges.
  The compiler adds the "did it iterate" flag only when the branch is present,
  so loops without it compile exactly as before.
- **Twig-style operator tests** — `in` / `not in`, `is defined`, `is null`,
  `is empty`, `is iterable`, `is even`, `is odd`, `starts with`, `ends with`,
  `matches`, `divisible by` and `same as`. The `defined`, `null` and `empty`
  tests tolerate an absent left operand and answer instead of throwing.
- **Whitespace control** — `{%- … -%}` (and `{{- … -}}`, `{#- … -#}`) suppress
  the whitespace on the chosen side of a tag.
- **Collection functions** — `range(low, high, step?)`,
  `cycle(values, position)` and `attribute(subject, name, default?)`.
- Compiler tests for the above.

### Changed

- `in` matches a list by value and a mapping by key, mirroring Twig.
- The `loop` variable is no longer documented: it was documented but never
  implemented. Use `{% for key, value in items %}` for the index.

### Fixed

- Documentation: corrected the filter/function call model, object access, and
  removed the documented-but-unimplemented `loop` object.
- Removed a stale docblock reference to a `castToArray()` that no longer exists.

[Unreleased]: https://github.com/sailantis/clarity-engine/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/sailantis/clarity-engine/releases/tag/v0.1.1
[0.1.0]: https://github.com/sailantis/clarity-engine/releases/tag/v0.1.0
