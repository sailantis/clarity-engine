# Changelog

All notable changes to `sailantis/clarity-engine` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **The policy API: a template's reach is now a set of capabilities, not one
  boolean.** A `Clarity\Engine\Policy` is a set of capabilities plus two
  allowlists, and every decision it makes is made **at compile time** — there is
  no policy object on the render path, so none of this costs anything to
  enforce.

  | Capability          | Default | What it grants                                                                                    |
  | ------------------- | ------- | ------------------------------------------------------------------------------------------------- |
  | `rawPhp`            | `false` | `{% php CODE %}`                                                                                  |
  | `methodCalls`       | `false` | `obj.method(args)` / `$obj->method(args)`, with arguments and dynamic names                        |
  | `superglobals`      | `false` | `$_SERVER`, `$_GET`, `$_ENV`, … as chain roots                                                    |
  | `phpVariables`      | `false` | the render scope seeded as PHP locals — what makes `$title` and `{% php echo $title; %}` one name |
  | `variableVariables` | `true`  | `$$name` / `${expr}`                                                                              |
  | `newExpressions`    | `false` | `new Foo(args)`                                                                                   |
  | `staticCalls`       | `false` | `Foo::method(args)`, `Foo::CONST`, `Foo::class`, `Foo::$prop`                                     |

  | Allowlist   | Default | What it governs                                            |
  | ----------- | ------- | ---------------------------------------------------------- |
  | `functions` | `[]`    | bare calls and filter steps that resolve to a PHP function |
  | `filters`   | `[]`    | names accepted after `\|>`, which need not be functions    |

  **An empty allowlist is no restriction; a non-empty one is the complete set** —
  only the listed names resolve, and anything else is a compile-time error. That
  is what makes `Policy::unrestricted()` the engine's former PHP mode exactly, rather
  than a mode that happens to deny everything.

  ```php
  $engine->setPolicy(Policy::restricted());    // the default
  $engine->setPolicy(Policy::unrestricted());  // every capability on
  $engine->setPolicy(Policy::trusted());       // trusted, but no `new`/`::`
  $engine->setPolicy(Policy::default()         // the default plus named grants
      ->allowCapability('methodCalls')
      ->allowFunctions('strtoupper', 'count'));
  $engine->setPolicy(['capabilities' => ['rawPhp' => true]]);   // config form
  ```

  The gain over the boolean is that a grant is **independent**: an application
  that needs `strtoupper` in a template no longer has to give up every
  compile-time guarantee to get it, and granting `methodCalls` does not
  incidentally grant `rawPhp`. `Policy::fromArray()`/`toArray()` round-trip, so a
  policy can live in a config file, and `denyFunctions()` applies last and wins,
  which is the one thing an allowlist cannot express ("everything except
  `exec`").

- **`new Foo(...)` and `Foo::bar()` now compile instead of producing invalid
  PHP.** Neither was a capability that could be relaxed — the tokenizer emitted
  the leading `\` of a fully qualified name as a stray character, so
  `new DateTime()` and `DateTime::createFromFormat(...)` produced
  `syntax error, unexpected fully qualified name`, and a bare `Foo\Bar` was
  silently treated as a chain. Class names are now read by a dedicated handler
  that compiles them to a real **fully qualified** form, which is what makes the
  emitted code independent of where the engine happens to live. Gated on
  `newExpressions` and `staticCalls` respectively.
- **`x instanceof Foo`** is supported and, unlike the two above, needs no
  capability: it takes a class name because that is what the operator means, and
  it reaches nothing the scope did not already hold.
- **`dump` is now a filter as well as a function.** `{{ x |> dump }}` emits the
  dumped value at that point in the pipeline and passes `x` through unchanged, so
  the documented form `{{ items |> filter(i => i:active) |> dump |> slice(0, 5) }}`
  compiles and the trailing steps still see the value. In production the step is
  eliminated to its input, exactly as `dump(x)` is pruned to `''`.

### Changed

- **A template's physical path now travels with its source, from the loader that
  knows it.** `TemplateSource` gains a `$path`, which `FileLoader` fills in and a
  custom loader may fill in for whatever it reads from; `CompiledTemplate` gains
  the parallel `$sourcePaths`, which the compiler bakes into the compiled class
  beside `$sourceFiles`. Error reporting therefore reads the path off the class
  instead of re-deriving it from the active loader, which is both cheaper and
  correct for an error in an inlined include or a layout — those name their own
  file. It also survives a swapped-out loader. A loader with no file to name
  reports `null`, and the entry is `''` rather than absent, so the list stays
  index-aligned with `$sourceFiles`.
- **`ClarityException::$templateFile` is renamed `$templateName`, and a
  `$templatePath` is added for the physical file.** The old name was a misnomer:
  it held a *logical* name (`pages/home`) and never a file, which is what made
  `getFile()`'s former uselessness look like a mapping bug rather than the
  naming problem it was. `$templateName` is the logical name the loader was asked
  for, `$templatePath` the file it resolved to (`''` when the loader has none —
  `ArrayLoader`, `StringLoader`, a database loader), and `getFile()` prefers the
  path because that is the form an editor can open. The constructor gains
  `$templatePath` as its **fourth** parameter, ahead of `$previous`, so pass the
  previous throwable by name (`previous: $e`).
- **The policy presets are named for what they grant.** `restricted()` (the
  default), `trusted()` and `unrestricted()` each describe a policy's reach
  rather than borrowing the words "sandbox" and "open", which read as
  "closed"/"available" rather than "nothing reaches PHP"/"everything does" —
  `unrestricted()` in particular is the one name that must not be reached for by
  mistake. `default()` replaces `custom()` because it is not a blank slate: it
  starts from `restricted()` and every capability you do not name stays off.
  `isUnrestricted()`/`isSandboxed()` are unchanged, and `Compiler::sandboxed()` keeps its
  name as the compiler-side constructor.
- **`setSandboxMode()` and `isSandboxed()` are replaced by
  `setPolicy()`/`getPolicy()`.** This is the **breaking** part: the boolean was
  one way to say what a policy now says precisely, and keeping it as an alias
  would be a second way to say one thing — which is a way for the two to
  disagree. `isSandboxed()` survives on the engine as a coarse convenience
  reading the policy, and `getPolicy()` is always a real object.
  `['sandbox' => false]` in the constructor is replaced by `['policy' => …]`.
  Migration: `setSandboxMode(false)` → `setPolicy(Policy::unrestricted())`.
  `setDeniedFunctions([...])` → `setPolicy(Policy::unrestricted()->denyFunctions(...))` —
  the deny-list is now part of the policy, so changing it recompiles correctly.
- **A policy change invalidates the compiled cache.** A compiled template records
  a **digest** of the effective policy, and the loader recompiles on a mismatch.
  This is strictly better than what it replaced: the old `$sandboxCompiled` flag
  covered only the boolean, so changing the deny-list did **not** invalidate
  cached templates and left stale ones calling now-denied functions. A digest
  rather than the policy itself, because the compiled file ships to a server and
  should not carry a readable inventory of what a template may call.
- **An allowlist NARROWS; it never opens a door.** `allowsPhp()` is now decided by
  capabilities alone, so `Policy::restricted()->allowFunctions('count')` stays
  sandboxed: the allowlist filters which PHP functions a construct may call, and
  with no construct reaching PHP there is nothing for it to filter. Pair a grant
  with a capability for it to apply to
  (`Policy::restricted()->allowCapability('methodCalls')->allowFunctions('count')`).
  Unlisted names are still refused, by name.
- **`superglobals` is a genuinely independent capability.** Without it, a
  superglobal name is an ordinary scope read, so `{{ _SERVER }}` throws **even
  when `phpVariables` is granted** — previously the two were inseparable, and
  PHP mode reached every superglobal as a side effect of scope seeding. With it,
  the name means PHP's own variable and never resolves to a scope entry.
- **Every rejection message names the grant that would fix it.** `Function 'x' is
blocked in PHP mode. Allow it by removing it from the deny-list.` becomes
  `Function 'x' is not allowed by this policy: it is not in the function
allowlist, or it is denied. Add it with allowFunctions().` — and
  `'{% php %}' … Call setSandboxMode(false) to allow raw PHP.` becomes
  `'{% php %}' is not allowed by this policy. Grant the 'rawPhp' capability to
allow it.` A separate message names an unlisted _filter_, because the remedy
  differs (a filter allowlist, or a registration).
- `COMPILER_VERSION` 18 → 19, for the new grammar (`new`/`::`/`instanceof`).

- **`is defined` now answers the same in both modes.** A name holding an
  explicit `null` used to count as **defined** in sandbox mode (the probe used
  `array_key_exists`) but as **not defined** in PHP mode (the same name compiles
  to a PHP local, and a local cannot be tested for existence without losing
  `null`). A template cannot see the mode, so the answer it got depended on a
  setting it could not read. The probe is now `isset()` everywhere, which makes
  the contract one sentence: **a name is defined when it holds a value other than
  `null`**. `is null` still reports a present-but-null name, so the two together
  separate absent, null, and present. **Behaviour change:** `user is defined` is
  now `false` when `user` was passed as `null`. See `docs/01-template-syntax.md`
  and `tests/Engine/OperatorTest.php`.

- **An unregistered filter is now a compile-time error with an actionable
  message.** In sandbox mode `{{ name |> strtoupper }}` compiled to a lookup in
  the runtime callable table and failed on the first render, reporting
  `Variable "strtoupper" is not defined in this context` — naming a variable the
  template never wrote and surfacing on a request rather than at the deploy. It
  is now rejected while compiling, with a message that names both remedies:
  `Filter 'strtoupper' is not registered, and the sandbox is enabled, so there is
nothing for it to resolve to. Register it with addFilter(), or call
setSandboxMode(false) to let a PHP function of the same name be used.` The
  rejection is possible at compile time because sandbox mode leaves nothing for
  an unregistered name to fall back to — the open-mode branch is the only other
  resolution path, and it is unreachable while the sandbox is on.

### Removed

- **The `{% php %}…{% endphp %}` block spelling is gone; `{% php <code> %}` is
  now the only raw-PHP form.** The two spellings compiled through the same
  extraction and the same sentinel, so the block form bought almost nothing — a
  tag could already carry several statements, because the body pattern is `/s`
  and spans lines:

  ```twig
  {% php
  $total = 0;
  foreach ($items as $item) { $total += $item['qty']; }
  echo $total;
  %}
  ```

  The one thing it did buy was a body containing a literal `%}`; spell that
  `'%' . '}'` instead. What the removal buys back is the two-pass extraction,
  whose ordering caveat existed only because the block opener is
  indistinguishable from an empty `{% php %}` tag. `{% endphp %}` is no longer a
  keyword and a bare `{% php %}` is now a compile-time error naming what it
  needs, rather than a half-sentence about a missing `{% endphp %}`.
  `COMPILER_VERSION` 19 → 20, so every cached template recompiles.

  ```twig
  {# before #}                          {# after #}
  {% php %}echo $x;{% endphp %}          {% php echo $x; %}
  {% php %}…multi-line…{% endphp %}      {% php …multi-line… %}
  ```

### Security

- **A template name can no longer address a file outside the view path.**
  `FileLoader::resolveName()` accepted an absolute path (leading `/`, a Windows
  drive, a UNC share) or a `./`-relative path verbatim, and the compiler's
  character check allowed `.` and `/`, so `{% include "../../../../etc/passwd" %}`
  and `{% include "C:/secrets/app" %}` both **compiled**, reading the file at
  compile time and baking its contents into the cached class. A template name is
  often derived from a request, and a template can be stored in a database, so
  this made a name an arbitrary file reader — with the sandbox **on**. Resolving
  now splits the name on `/` (with `.` and `\` as the same separator) and refuses
  any absolute name or any empty, `.` or `..` segment, so the result cannot leave
  the base path however it is spelled. **Breaking:** absolute template names are
  no longer supported; a loader rooted elsewhere is configured as
  `new FileLoader('/their/root')`, where the base path is the root. See
  `docs/09-policy-api.md` and `tests/Engine/LoadPathSecurityTest.php`.

- **`dump()` can no longer print unmasked values, in any form.** The registry
  carried its own fallback debug formatter — `print_r` inside a `<pre>`, with no
  masking — and two separate paths reached it. Calling `setDebugMode(true)`
  instead of `enableDebug()` printed secrets that the renderer would have masked,
  and a quoted callable reference such as `{{ map(items, "dump") }}` reached it
  **even with debug off**, printing into production output. The registry now owns
  no debug formatting: the renderers and their masking live in one place
  (`Clarity\Debug\DebugRuntime`), `dump()` is a no-op until the engine installs
  them, and `dd()` — the one form that is never pruned — throws with debug off
  rather than falling back to an unmasked `var_dump`. Every `dump` form (call,
  filter step, quoted reference) is eliminated in production. See
  `tests/Engine/DebugDumpTest.php` and `tests/Engine/CallSyntaxTest.php`.

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

- **A compile-time policy refusal now points at the template line that contains
  the offending construct.** `{% php %}` on a policy that denies `rawPhp` was
  raised during the pre-scan — the pass that runs *before* the source map cursor
  starts tracking, and the reason the report claimed the engine file
  (`Compiler/DirectiveSupportTrait.php:222`) with no template at all. The refusal
  now resolves its position from the `{# @source … #}` markers the merge already
  emits, so it is correct even when the tag was reached through `{% extends %}`
  or `{% include %}` — an included template is named as the included file, not as
  its host.
- **`ClarityException` mirrors its location onto `$file` / `$line`.** The
  uncaught-fatal line, xdebug's develop-mode error page and every error handler
  read `getFile()` / `getLine()`; they now name the template instead of the engine
  frame, which is what Smarty does for the same reason. The real call path stays
  in `getTrace()`.
- The getting-started guide named the wrong Composer package
  (`clarity/engine`); it now matches the real package name,
  `sailantis/clarity-engine`.
- The API-reference generator now links a method to the file it is **declared**
  in. A method composed from a trait is declared in the trait's file, so the
  previous class-relative anchor pointed at the wrong line.

- **The two debug modes are one.** `setDebugMode(bool)` (compiler-level
  assertions plus a bare `dump()`) and `enableDebug(DumpOptions)` (renderers,
  bus, panel) were introduced four days apart and had drifted into one feature
  and its degraded subset, with side effects the other did not undo — calling
  `enableDebug()` and then `setDebugMode(false)` left the bus and panel wired
  while `isDebugMode()` reported `false`. `setDebugMode()` is now the single
  switch and does the whole job in both directions:

  ```php
  $engine->setDebugMode(true);                       // everything on
  $engine->setDebugMode(new DumpOptions(maxDepth: 3)); // on, with options
  $engine->setDebugMode(false);                      // everything off
  ```

  `enableDebug()` is kept as a deprecated alias. `dump()`/`dd()` are declared
  once in the registry (so there is one name to resolve, whichever syntax is
  used), and the debug renderers, masking and `DumpOptions` moved into a new
  `Clarity\Debug\DebugRuntime` that the engine installs — the registry's own
  `print_r` fallback is gone. See `docs/04-advanced-topics.md`.

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
