**Project Overview**
- **Language:** PHP (>= 8.1)
- **Purpose:** A small, fast template engine that compiles `.clarity.html` templates to cached PHP classes.
- **Main namespaces:** `Clarity\` → `src/`; tests under `Clarity\Tests\` → `tests/`.

**Run & Test**
- **Install dependencies:** `composer install`
- **Run test suite:** `composer test` (runs `phpunit` via `scripts.test`).
- **Alternative:** `php vendor/bin/phpunit` or on Windows `php vendor\\bin\\phpunit`.
- **Run a single test file:** `php vendor/bin/phpunit tests/ClarityEngineTest.php`

**Key Files to Inspect**
- **Engine entry:** src/ClarityEngine.php
- **Compiler / tokenizer:** src/Engine/Compiler.php, src/Engine/Tokenizer.php
- **Cache handling:** src/Engine/Cache.php
- **Function & filter registry:** src/Engine/FunctionRegistry.php
- **Tests:** tests/ClarityEngineTest.php

**Coding Conventions & Guidance**
- **Style:** Prefer PSR-12-style formatting and explicit type hints on parameters and return values.
- **APIs:** Avoid changing public APIs without adding or updating tests.
- **Exceptions:** Use `ClarityException` for template-related errors; keep error messages user-friendly and include template path/line where applicable.
- **Small, focused PRs:** Changes should be minimal and include tests for behavior changes.

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

**Contact / Next Steps**
- If you want, I can also add a short `README-test.md` with exact commands for Windows and CI.
