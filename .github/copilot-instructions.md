**Project Overview**

- **Language:** PHP (>= 8.1)
- **Purpose:** A small, fast template engine that compiles `.clarity.html` templates to cached PHP classes.
- **Main namespaces:** `Clarity\` → `src/`; tests under `Clarity\Tests\` → `tests/`.

**Run & Test**

- **Install dependencies:** `composer install`
- **Run test suite:** `composer test` (runs `phpunit` via `scripts.test`).
- **Alternative:** `php vendor/bin/phpunit` or on Windows `php vendor\\bin\\phpunit`.
- **Run a single test file:** `php vendor/bin/phpunit tests/Engine/FiltersFunctionsTest.php`

**Key Files to Inspect**

- **Engine entry:** src/ClarityEngine.php + src/ClarityEngineTrait.php
- **Compiler / tokenizer:** src/Engine/Compiler.php, src/Engine/Tokenizer.php
- **Cache handling:** src/Engine/Cache.php
- **Filter, function & block registry:** src/Engine/Registry.php
- **Module interface:** src/ModuleInterface.php
- **Template loaders:** src/Template/FileLoader.php, DomainRouterLoader.php, CompositeLoader.php, ArrayLoader.php, StringLoader.php
- **Built-in localization modules:** src/Localization/IntlFormatModule.php, TranslationModule.php, LocaleService.php
- **Tests:** tests/Engine/ (ControlFlowTest, FiltersFunctionsTest, RenderingTest, ModulesTest, LocalizationTest, …)

**Key Features (as of March 2026)**

- **Macros:** `{% macro @name(p1, p2) %}...{% endmacro %}` / call: `{% @name(arg1, arg2) %}`
- **Loop key variable:** `{% for value, key in collection %}` (first = value, second = key)
- **Named filter args:** `{{ v |> truncate(length:50) }}` — `:` syntax, emitted as PHP named args
- **Context-aware escaping:** auto-detects `<script>`/`<style>` blocks; override with `{# @context js|css|html #}`
- **Module system:** `$engine->use(ModuleInterface $module)` — self-registering plugins
- **Debug mode:** `$engine->setDebugMode(bool)` — adds runtime range-loop safety checks
- **Inline filters:** `$engine->addInlineFilter(name, definition)` — zero-runtime-overhead filters
- **Custom block directives:** `$engine->addBlock(keyword, callable)` — extend the compiler
- **Services:** `$engine->addService(key, object)` — shared mutable state for modules
- **Localization:** IntlFormatModule (intl-backed), TranslationModule (YAML/JSON/PHP catalogs)
- **Template loaders:** `TemplateLoader` interface has `load(?TemplateSource)` and `getSubLoaders()` (no `exists()`). `FileLoader` handles plain names only — no `namespace::` routing. Domain routing is done by `DomainRouterLoader`; first-match chaining by `CompositeLoader`. `setExtension()` on the engine propagates to all nested `FileLoader` instances automatically.

**Coding Conventions & Guidance**

- **Style:** Prefer PSR-12-style formatting and explicit type hints on parameters and return values.
- **APIs:** Avoid changing public APIs without adding or updating tests.
- **Exceptions:** Use `ClarityException` for template-related errors; keep error messages user-friendly and include template path/line where applicable.
- **Small, focused PRs:** Changes should be minimal and include tests for behavior changes.
- **Filter names:** The sprintf-style filter is `sprintf` (not `format`). The `format` key exists in the registry as a placeholder but has no backing inline definition — do not use it.

**Template & Cache Notes**

- **Default extension:** `.clarity.html` (configurable via `setExtension()`).
- **Cache model:** Templates compile to PHP classes and are written to the cache directory.
- **Cache invalidation:** When regenerating or overwriting cache files, ensure PHP's file caches are cleared to avoid stale bytecode. Call `clearstatcache(true, $path)` and `opcache_invalidate($path, true)` before requiring newly-written cache files when running under OPcache.

**Testing Troubleshooting**

- If `composer test` returns an error: ensure `vendor/bin/phpunit` exists and `composer install` completed successfully.
- If tests fail to run on Windows, invoke PHPUnit via `php vendor\\bin\\phpunit` to avoid execution-permission issues.
- Run `composer dump-autoload` if autoloading issues occur after adding classes.

**How I (the assistant) should work in this repo**

- When making edits: run the test suite locally and keep changes minimal.
- If adding a dependency: update `composer.json` and run `composer install` in instructions.
- When debugging template errors: inspect `src/ClarityEngine.php` → `loadCachedClass()` and `mapCompiledErrorLine()` to map PHP errors back to template lines.
- When adding filters: prefer `addInlineFilter` for pure-expression filters, `addFilter` for callable ones. Document named parameters in `params` and their PHP defaults in `defaults`.
