# Changelog

All notable changes to `sailantis/clarity-engine` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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

## [0.1.0]

Initial development release.
