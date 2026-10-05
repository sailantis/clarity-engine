# Changelog

All notable changes to `sailantis/clarity-engine` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Compiled-template properties renamed to `functions` / `services`.** A compiled
  class was generated with `public function __construct(private array $__c_fn, private
  array $__c_sv)`, so the only spelling available to a module author emitting directive
  or inline-filter PHP was the cryptic `$__c_sv['key']`. The constructor properties are
  now `$functions` and `$services`, which reads as `$this->services['locale']`:

  ```php
  public function __construct(private array $functions, private array $services) {}
  ```

  The render-frame **locals keep the `__c_` spelling** (`$__c_fn`, `$__c_sv`), and this
  is not an oversight: lambda bodies and quoted filter references compile to `static`
  closures, where `$this` is unbound, so `$__c_sv` is the only form reachable there.
  `render()` unpacks each local from its property only when the compiled body actually
  references it, so a template that touches neither pays nothing. Use
  `$this->services['key']` in a directive handler (which always emits into `render()`)
  and `$__c_sv['key']` in an inline filter that may also be referenced as a quoted name.
  The `'this'` variable can still not be reached from a template: the `__c_` prefix
  remains reserved, and `services`/`functions` are ordinary property names that
  `extract($__c_va, EXTR_SKIP)` cannot collide with. `COMPILER_VERSION` moves 26 → 27,
  so previously-cached templates are recompiled automatically.

- **Directive handler signature: `$sourcePath` + `$tplLine` → one `TemplateLocation`.**
  A handler was passed the logical template name and the line as two separate arguments,
  neither of which is the thing a person can open, and neither of which is the physical
  path. The two are now one {@see \Clarity\Template\TemplateLocation} (name, line, path):

  ```php
  // was
  function (string $rest, string $path, int $line, callable $processExpr): string
  // now
  function (string $rest, TemplateLocation $at, callable $processExpr): string
  ```

  The unused fifth `Compiler` argument is gone with it. This is a breaking change for any
  handler registered against the old shape — the type error is loud, not silent — but the
  old form bought the handler nothing it could actually use, so the migration is a
  signature edit and, where the location was discarded, a `throw new ClarityException(…,
  $at)`. In-repo: `LocaleService`, `TranslationModule`. See `docs/04-advanced-topics.md`.

### Added

- **Directive argument lists: `$processExpr($rest, true)`.** A directive handler
  receives the raw text after its keyword, so a tag with several arguments
  (`{% cache "user_" ~ id, ttl: 300, tags: ["user"] %}`) forced every author to
  re-implement the comma split and the named-argument rule that the filter syntax already
  owns. The callable handed to a handler now takes a second `bool $asList = false`
  parameter; with `true` it compiles the whole list with the filter grammar
  (`[name: ] expr [, [name: ] expr ...]`) and returns `[positional, named]`, both lists
  of compiled PHP expressions keyed by numeric index and by name:

  ```php
  [$positional, $named] = $processExpr($rest, true);
  $key = $positional[0] ?? null;
  $ttl = $named['ttl']  ?? '300';
  ```

  Positional-after-named, a repeated name, and an empty argument are compile-time errors.
  The single-expression form is unchanged, and an extra argument is ignored by an
  existing single-parameter closure, so no handler needs an edit. The tokenizer gained
  `processArgumentList()` as the single shared implementation, and
  `compileFilterArguments()`/`compileArgList()` now reject an empty argument and a
  duplicate named argument — so filters and call syntax inherit the same guards.

- **A `ClarityException` thrown inside a directive handler is now located.** The
  registry dispatch runs inside the compiler's `withLocation()`, the safety net every
  other compile step already had. `throw new ClarityException('…')` from a handler used
  to escape naming the closure's own file and line (`Handler.php:37`); it now carries the
  template name, the line, and — for a file-backed loader — the physical path, so an
  uncaught fatal and an IDE frame both land on the template. An explicit location passed
  by the handler still wins, and a non-`ClarityException` throwable still passes through
  untouched. The paired-construct errors raised by the compiler ride the same wrapper.

- **`Clarity\Template\TemplateLocation`, and a handler that is handed the whole
  location.** A directive handler can now raise a located exception without being given
  anything else, because it receives where its tag sits:

  ```php
  $engine->addDirective('cache', function (string $rest, TemplateLocation $at, callable $processExpr): string {
      if (trim($rest) === '') {
          throw new ClarityException('cache needs a key', $at);
      }
      return "\$this->services['cache']->begin({$processExpr(trim($rest))});";
  });
  ```

  `$at` carries the logical template name, the line, and — for a file-backed loader — the
  physical file, which a handler could not otherwise know: the active loader is the only
  authority on it. `ClarityException`'s second parameter therefore accepts either a
  `TemplateLocation` or the logical name as before, so the three-value form and the bare
  `new ClarityException('msg')` are both unchanged. An explicit `$line`/`$path` still
  overrides the corresponding field of the location.

  This also removes the nested-exception case the earlier wrapper could produce. A handler
  that throws with `$at` raises a COMPLETE exception, so the compiler's `withLocation()`
  finds nothing missing and rethrows it untouched — the path is no longer lost to a name
  that was already present.

- **Paired custom directives are validated at compile time.** A directive that wraps
  a body can now declare its member tags on the opening registration, using a
  `keyword => role` map — `'required'` for the closing tag, `'allowed'` for an optional
  branch tag:

  ```php
  $engine->addDirective('cache', $openHandler, [
      'endcache'  => 'required',
      'cacheelse' => 'allowed',
  ]);
  $engine->addDirective('endcache',  $closeHandler);
  $engine->addDirective('cacheelse', $branchHandler, ['cache' => 'owner']); // optional assertion
  ```

  The compiler then refuses to emit the broken PHP that used to compile silently:
  an unclosed `{% cache %}` (which leaked an output buffer into the next render in the
  same request), a stray `{% endcache %}` (which swallowed the engine's own render
  buffer), a close that crosses an inner `{% if %}`/`{% for %}` or another construct, a
  branch tag used outside its construct, and a construct that spans an `{% include %}`.
  Errors name the template and line, and an unclosed construct is reported at its own
  opening line. A member's optional `['owner' => '<opener>']` entry is an assertion, not a
  second declaration: it is checked at the start of every compile, which catches
  "registered the close but forgot the opener". Directives registered without a pairing
  behave exactly as before, so the feature is opt-in per construct. The built-in
  `{% else %}`/`{% elseif %}`/`{% endif %}` now also report a clear error when used
  directly inside an open custom construct instead of emitting an unbalanced built-in
  tag. `COMPILER_VERSION` moves 25 → 26 so previously-cached templates are recompiled and
  now fail loudly.

- **A directive's construct role is now a `Directive` value, not a
  `keyword => role` array.** The array encoded two different ideas in the same shape:
  `['endcache' => 'required']` said "this tag is my closing tag", while
  `['cache' => 'owner']` said "this tag belongs to cache" — the direction of the mapping
  flipped between the two forms, and the role was a magic string that had to be spelled
  exactly right. A {@see \Clarity\Engine\Directive} factory names the role instead:

  ```php
  // was
  $engine->addDirective('cache',      $openHandler,   ['endcache' => 'required', 'cacheelse' => 'allowed']);
  $engine->addDirective('endcache',   $closeHandler,  ['cache' => 'owner']);
  $engine->addDirective('cacheelse',  $branchHandler, ['cache' => 'owner']);
  // now
  $engine->addDirective('cache',      $openHandler,   Directive::opens('endcache', 'cacheelse'));
  $engine->addDirective('endcache',   $closeHandler,  Directive::closes('cache'));
  $engine->addDirective('cacheelse',  $branchHandler, Directive::branches('cache'));
  ```

  Four factories cover two independent ideas. `opens`/`branches`/`closes` describe
  **structure** — the tag changes what the compiler has open, so the role is also what a
  formatter needs to indent a body. `inside()` is a preposition, not a verb, because a
  containment-only tag changes nothing; it is an ordinary leaf that is merely invalid
  outside its owner, and it is now checked at compile time against the innermost open
  construct:

  ```php
  $engine->addDirective('cache_control', $leafHandler, Directive::inside('cache'));
  ```

  `opens()` takes the closing keyword as a single named argument, so "exactly one closer"
  is guaranteed by the shape of the call instead of by a counter; the variadic tail is the
  optional branch tags. A member's `branches()`/`closes()` remains an assertion against
  the opener and must now match its **role** too, not just its owner — declaring a tag a
  branch while the opener calls it the closer is a registration error, not a silent
  override. `Directive::inside()` is allowed across an `{% include %}` boundary (an
  include is inlined into the same render body), unlike a close. The old array form is
  removed, not deprecated. `COMPILER_VERSION` moves 27 → 28.

- **The policy API: a template's reach is now a set of rules, not one
  boolean.** A `Clarity\Engine\Policy` is a set of rules plus two
  allowlists, and every decision it makes is made **at compile time** — there is
  no policy object on the render path, so none of this costs anything to
  enforce.

  | Rule          | Default | What it grants                                                                                    |
  | ------------------- | ------- | ------------------------------------------------------------------------------------------------- |
  | `phpFunctions`      | `false` | bare calls (`strtoupper(name)`) and filter steps that resolve to a PHP function                   |
  | `rawPhp`            | `false` | `{% php CODE %}`                                                                                  |
  | `methodCalls`       | `false` | `obj.method(args)` / `$obj->method(args)`, with arguments and dynamic names                        |
  | `superglobals`      | `false` | `$_SERVER`, `$_GET`, `$_ENV`, … as chain roots                                                    |
  | `phpVariables`      | `false` | the render scope seeded as PHP locals — what makes `$title` and `{% php echo $title; %}` one name |
  | `variableVariables` | `true`  | `$$name` / `${expr}`                                                                              |
  | `newExpressions`    | `false` | `new Foo(args)`                                                                                   |
  | `staticCalls`       | `false` | `Foo::method(args)`, `Foo::CONST`, `Foo::class`, `Foo::$prop`                                     |
  | `strictTypes`       | `true`  | `declare(strict_types=1)` in the compiled template                                                |

  | Allowlist   | Default | What it governs                                            |
  | ----------- | ------- | ---------------------------------------------------------- |
  | `functions` | `[]`    | names the `phpFunctions` rule may resolve to a PHP function   |
  | `filters`   | `[]`    | names accepted after `\|>`, which need not be functions    |

  **An empty allowlist is no restriction; a non-empty one is the complete set** —
  only the listed names resolve, and anything else is a compile-time error. That
  is what makes `Policy::unrestricted()` the engine's former PHP mode exactly, rather
  than a mode that happens to deny everything. A filter allowlist only narrows;
  `allowFunctions()`, by contrast, turns the `phpFunctions` rule on as it grants,
  so `default()->allowFunctions('count')` reaches the sandbox on its own. An
  empty `allowFunctions()` call is a no-op, so it cannot become an accidental
  grant of every function.

  ```php
  $engine->setPolicy(Policy::restricted());    // the default
  $engine->setPolicy(Policy::unrestricted());  // every rule on
  $engine->setPolicy(Policy::trusted());       // trusted, but no `new`/`::`
  $engine->setPolicy(Policy::default()         // the default plus named grants
      ->allowRule('methodCalls')
      ->allowFunctions('strtoupper', 'count'));
  $engine->setPolicy(['rules' => ['rawPhp' => true]]);   // config form
  ```

  The gain over the boolean is that a grant is **independent**: an application
  that needs `strtoupper` in a template no longer has to give up every
  compile-time guarantee to get it, and granting `methodCalls` does not
  incidentally grant `rawPhp`. `Policy::fromArray()`/`toArray()` round-trip, so a
  policy can live in a config file, and `denyFunctions()` applies last and wins,
  which is the one thing an allowlist cannot express ("everything except
  `exec`").

- **`new Foo(...)` and `Foo::bar()` now compile instead of producing invalid
  PHP.** Neither was a rule that could be relaxed — the tokenizer emitted
  the leading `\` of a fully qualified name as a stray character, so
  `new DateTime()` and `DateTime::createFromFormat(...)` produced
  `syntax error, unexpected fully qualified name`, and a bare `Foo\Bar` was
  silently treated as a chain. Class names are now read by a dedicated handler
  that compiles them to a real **fully qualified** form, which is what makes the
  emitted code independent of where the engine happens to live. Gated on
  `newExpressions` and `staticCalls` respectively.
- **`x instanceof Foo`** is supported and, unlike the two above, needs no
  rule: it takes a class name because that is what the operator means, and
  it reaches nothing the scope did not already hold.
- **`dump` is now a filter as well as a function.** `{{ x |> dump }}` emits the
  dumped value at that point in the pipeline and passes `x` through unchanged, so
  the documented form `{{ items |> filter(i => i:active) |> dump |> slice(0, 5) }}`
  compiles and the trailing steps still see the value. In production the step is
  eliminated to its input, exactly as `dump(x)` is pruned to `''`.
- **The `strictTypes` rule: a template can now declare PHP's strict types.**
  `declare(strict_types=1)` is per-file, and every compiled template is its own
  file whose `<?php` the engine emits — so a caller could not opt a template into
  strict types from their own code, and a typed filter (`fn(string $s)`) silently
  received `42` as `"42"`. When the rule is granted the compiled file
  carries the declaration, and a mismatched argument to a registered filter or
  function throws a `ClarityException` naming the template line instead of being
  coerced. It also makes a fractional float passed to an `int` parameter throw
  rather than truncate.

  It is **not** a reach rule: it grants no construct and names no class, so
  `allowsPhp()` does not count it and a strict template is no less sandboxed than
  a weak one. It is the only rule that defaults **on**, in every preset including
  `restricted()`. `COMPILER_VERSION` moves 21 → 23, because both the declaration
  and the cast changes alter the emitted file for every template, and a policy
  digest cannot express either.

- **`{% parent %}` is the canonical spelling for inlining a parent block.** The
  placeholder that a child override uses to include its parent's block content was
  only spellable as `{% @parent %}`. The bare form is now the documented spelling;
  the `@`-prefixed one was kept as a synonym in the release that introduced it and
  is **removed** by the macro-sigil change below.

  Clarity's answer to Twig's `{{ parent() }}`, with the same rules: valid only in
  an overriding child block, repeatable, and scoped to the **immediate** parent
  block. The difference is *when* it resolves: Twig calls a function at render
  time, while Clarity splices the parent fragment in during compilation, so there
  is no render-time dispatch — and a compile error raised inside the parent's own
  content points at the parent template and line rather than the child.

### Changed

- **`context()` is renamed `vars()`, and it now reports the whole scope.** The old
  name collided with two other things in the engine: the output-escaping mode
  (`{# @context js #}`, `setEscapeContext()`) and the surrounding source quoted in
  a compile error ("… in context 'foo()'"). What the function actually returns is
  the template's variables — the same thing the engine otherwise calls `$vars` —
  so `vars()` is the name that says so.

  `context()` still compiles as a **deprecated alias**; nothing has to change to
  keep working. Prefer `vars()` in new templates.

  The rename also fixes a real omission. A `{% for %}` variable and a macro
  parameter are binding **PHP locals**, not entries of the scope array, and a
  `{% set %}` or `{% php %}` assignment in open mode writes a local too — so a
  snapshot that read only `$__c_va` silently left them out:

  | Template (inside `{% for user in users %}`) | Before           | Now                        |
  | ------------------------------------------- | ---------------- | -------------------------- |
  | `{{ vars() \|> json \|> raw }}`              | `{"users":[…]}`  | `{"users":[…],"user":"a"}` |

  The snapshot is now compile-time scoped — a variable is visible exactly where a
  read of it would resolve — and engine internals (`__c_…`) are filtered out.
  `COMPILER_VERSION` moves 23 → 24, because the emitted call changes for every
  template that uses it.

- **Strict types are on by default, and coercion is now something you opt out
  of.** `strictTypes` is enabled in every preset, `restricted()` included, so an
  unconfigured engine — and every policy built from a config array — compiles
  templates with `declare(strict_types=1)`. A project that relied on coercion
  gets the old behaviour back with one line:

  ```php
  $engine->setPolicy(Policy::default()->denyRule('strictTypes'));
  ```

  The reasoning is that the failure being replaced is invisible. A silently
  coerced value and a type error are the same event except that only one of them
  can be debugged, and coercion hides real mistakes:

  | Template                                | Weak mode          | Default (strict) |
  | --------------------------------------- | ------------------ | ---------------- |
  | `{{ 42 \|> upper }}`                    | `'42'`             | `TypeError`      |
  | `{{ null \|> upper }}`                  | `''` + deprecation | `TypeError`      |
  | `{{ ' 3.14 ' \|> trim \|> number(1) }}` | `'3.1'`            | `TypeError`      |
  | `{{ 42 }}`                              | `'42'`             | `'42'`           |

  What this does **not** change: `strictTypes` grants no construct, so
  `allowsPhp()` still reports the sandbox as sandboxed — it hardens the boundary
  without moving it — and the output cast in `{{ … }}` stays, so a non-string
  still renders. This is the change most likely to affect an existing
  application: the second and third rows are ordinary template mistakes that
  previously rendered something plausible.

  The one built-in filter that keeps a cast is `number`: `number_format()` takes a
  `float`, so a numeric string is a type error there, and casting is what keeps
  `{{ ' 3.14 ' |> trim |> number(1) }}` — a pipeline whose preceding step yields a
  string — working under strict types. Every other built-in hands its value to a
  `string` parameter and relies on the mode.

- **Policy "capabilities" are now Policy "rules".** The set grew a member that
  grants nothing: `strictTypes` adds no construct and names no class, it changes
  the contract at a call boundary. Calling that a *capability* forced every
  description of it to open with a caveat ("not a reach capability…"), which is
  the sign of the wrong noun. The type is unchanged; only the name is.

  | Before                  | After              |
  | ----------------------- | ------------------ |
  | `Policy::CAPABILITIES`  | `Policy::RULES`    |
  | `$policy->capabilities()` | `$policy->rules()` |
  | `allowCapability('x')`  | `allowRule('x')`   |
  | `denyCapability('x')`   | `denyRule('x')`    |
  | `['capabilities' => …]` | `['rules' => …]`   |
  | `$policy->allows('x')`  | unchanged          |

  `allows()` keeps its name deliberately: `hasRule('x')` would be ambiguous — it
  could mean "the policy contains such a rule" (always true, the name is in the
  table) or "the rule is in force" — whereas `allows()` asks about the thing, not
  the rule's existence. `strictTypes()`, `allowsPhp()`, `isSandboxed()` and the
  `ALLOWLISTS` constant are unchanged. The API is unreleased, so there is no
  migration path to keep.

- **The built-in inline filters no longer cast their input.** Every
  `(string)`/`(float)`/`(int)`/`(array)` wrapper came out of the filter templates:
  `upper` is now `\mb_strtoupper({1})`, not `\mb_strtoupper((string) {1})`. The
  coercion those casts performed still happens in weak mode, but weak mode is no
  longer the default (see above), so these are the two things the removal changes:

  | Template                | Before                                | After                                            |
  | ----------------------- | ------------------------------------- | ------------------------------------------------ |
  | `{{ null \|> upper }}`  | silent `''`                           | `''` + a `Deprecated` diagnostic                 |
  | `{{ 42 \|> join(',') }}`| `'42'` (the scalar was wrapped)       | `ClarityException` — `(array)` was never a coercion |

  The `join`/`merge` change is the one that affects the **default** mode, because
  PHP has no scalar→array coercion in either mode: a scalar that used to be wrapped
  into a one-element array now raises. Passing an array, which is the documented
  and common form, is unchanged.

- **`date_modify` returns a formatted string instead of a Unix timestamp.** It
  gains an optional second argument — `date_modify(modifier, format='c')` — so
  `{{ ts |> date_modify('+1 day', 'Y-m-d') }}` produces what previously took a
  chain into `date`. The default is ISO 8601, which `date` parses, so
  `{{ ts |> date_modify('+1 day') |> date('Y-m-d') }}` still yields the same
  result. A template that did arithmetic on the old integer return is the
  breaking case.

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
  starts from `restricted()` and every rule you do not name stays off.
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
  rules alone, so `Policy::restricted()->allowFunctions('count')` stays
  sandboxed: the allowlist filters which PHP functions a construct may call, and
  with no construct reaching PHP there is nothing for it to filter. Pair a grant
  with a rule for it to apply to
  (`Policy::restricted()->allowRule('methodCalls')->allowFunctions('count')`).
  Unlisted names are still refused, by name.
- **`superglobals` is a genuinely independent rule.** Without it, a
  superglobal name is an ordinary scope read, so `{{ _SERVER }}` throws **even
  when `phpVariables` is granted** — previously the two were inseparable, and
  PHP mode reached every superglobal as a side effect of scope seeding. With it,
  the name means PHP's own variable and never resolves to a scope entry.
- **Every rejection message names the grant that would fix it.** `Function 'x' is
blocked in PHP mode. Allow it by removing it from the deny-list.` becomes
  `Function 'x' is not allowed by this policy: it is not in the function
allowlist, or it is denied. Add it with allowFunctions().` — and
  `'{% php %}' … Call setSandboxMode(false) to allow raw PHP.` becomes
  `'{% php %}' is not allowed by this policy. Grant the 'rawPhp' rule to
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

### Fixed

- **A deprecation raised by a template no longer vanishes, and it is reported at
  the template rather than the cache file.** The render error handler was
  installed with the habitual `E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED` mask,
  which kept Clarity's handler from ever being *entered* for a deprecation —
  making both of its branches unreachable. The diagnostic therefore bypassed the
  application's handler entirely and was reported by PHP's built-in handler
  against the compiled cache file, an internal path that leaked into the
  response whenever `display_errors` was on (development setups, and the
  engine's own "Development Error Handling" recipe). Under weak mode this was
  the common case, not an edge one: `{{ null |> upper }}` is the CHANGELOG's own
  example of a deprecation, and it produced output naming
  `…\cache\82\8277e0910d750195b448797616e091ad.php`. The mask is now `E_ALL`, and
  a template deprecation is annotated like any other template diagnostic.

  The level translation is also faithful rather than lossy. It was
  `match ($errno) { E_NOTICE => E_USER_NOTICE, default => E_USER_WARNING }`, so
  everything but a native `E_NOTICE` — including a template's own
  `E_USER_NOTICE` — arrived as `E_USER_WARNING`, and a handler that promotes
  warnings to exceptions would abort a render over a notice. The level now keeps
  its kind: notice → `E_USER_NOTICE`, deprecation → `E_USER_DEPRECATED`,
  everything else (including a native `E_WARNING`, which is how PHP 8 reports an
  undefined variable or array key) → `E_USER_WARNING`.

  A forwarded diagnostic that originated in the template is now handed to the
  application handler at the **template's** file and line instead of the
  compiled cache file's, preferring the physical path when the loader supplied
  one — matching `ClarityException` and the `… in <template>:<line>` text already
  in the message. Diagnostics raised outside the template are untouched. See
  `tests/Engine/SecurityErrorMappingTest.php`.

### Removed

- **Macro definitions and calls no longer use the `@` sigil.** A macro is now
  defined with `{% macro name(params) %}…{% endmacro %}` and called with
  `{% call name(args) %}`; the old `{% macro @name %}`, `{% @name(args) %}` and
  `{% @parent %}` spellings are removed. `COMPILER_VERSION` 24 → 25.

  There is no shim that recognises the old spellings and rewrites them: an old
  `{% @name(args) %}` or `{% @parent %}` now fails as an unknown directive, and
  only a `{% macro @name %}` — which is still a `macro` tag — is refused with a
  message naming the replacement.

  The `@` was doing one job: keeping a macro name out of the directive-keyword and
  template-variable namespaces. `{% call %}` does that job structurally — the tag
  is two keywords, and the name is parsed as an identifier — so the sigil bought
  nothing but a spelling Twig tooling cannot colour. Now the definition tag is
  byte-identical to Twig's, and the call's arguments parse as an ordinary
  expression. (The call tag itself is still Clarity's own: Twig invokes a macro as
  `{{ name(args) }}`, which here would have to become a runtime closure and give
  up compile-time inlining.)

  ```twig
  {# before #}                                 {# after #}
  {% macro @card(t, b) %}…{% endmacro %}       {% macro card(t, b) %}…{% endmacro %}
  {% @card("Hi", intro) %}                     {% call card("Hi", intro) %}
  {% @parent %}                                {% parent %}
  ```

  Macro names are identifiers, so they are case-sensitive and a name that is a
  directive keyword (`if`, `for`, `set`, `block`, `include`, …) is rejected at
  definition time. A bare `{% card(x) %}` suggests `{% call card(x) %}` instead of
  reporting an unknown directive, and a `{% macro %}` that is left unclosed, or
  written empty, is named as such rather than as a missing directive.

  A macro may not be defined inside another macro's body: macro names are not
  scoped, so it would be callable from anywhere despite looking private. The scan
  pairs `macro` against `endmacro`, which is also what lets a definition that is
  missing its own `{% endmacro %}` report THAT rather than blaming the file for a
  stray `{% endmacro %}`.

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
- **A failure raised by the tokenizer now names the template and line.** The two
  fixes above both depend on the exception *carrying* a location — and the
  tokenizer never did. It is constructed without a template (it has no
  `sourcePath` property), so it could not fill in the name, line or path, and
  every one of its 90 throws — an unregistered filter, a malformed expression —
  surfaced as a `ClarityException` naming the engine's own file
  (`Tokenizer/FilterCompilerTrait.php:295`) with an empty `templateName`. Those
  are the author's mistakes, and the ones that most need to point at the author's
  line, so the compiler now attaches the location at the boundary: the calls that
  cross into the tokenizer, and the loader read of an inlined template, go through
  a new `withLocation()` helper, which fills in only the fields that are missing.
  An exception that already names a template came from a nested compile (an
  inlined macro or include) whose location is the more precise one, and is
  rethrown untouched; the original throw is kept as `$previous`.

  The scanner's unclosed-tag errors were a special case of the same bug: they
  passed the correct line but an empty name, so `relocate()` discarded the line
  and the position survived only as prose inside the message ("opened on template
  line 3"). Those now carry the line, and the compiler fills in just the name.
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
