# Changelog

All notable changes to `sailantis/clarity-engine` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Type casts — `{{ x |> int }}` and `{{ (int) x }}`.** A conversion between
  the scalar types and `array`, available in both syntaxes:

  ```twig
  {{ '3.7' |> int }}              {# 3                                     #}
  {{ 1.5 |> string }}             {# '1.5'                                 #}
  {{ '0' |> bool }}               {# false — PHP truthiness, not emptiness #}
  {{ abs((int) '-4343') }}        {# 4343                                  #}
  {{ x |> float |> round(2) }}    {# the reason casts are needed, below    #}
  ```

  Each cast is a total function: it never throws and never yields `null`, so it
  is safe on a value whose type is only known at render time — a form field, a
  DB row, a decoded payload. `(int) 'dsfd'` is `0`, and that is the property
  being bought.

  Six names are registered — `int`, `float`, `string`, `bool`, `array` and
  `object` — each usable as a filter or a call, and each also spellable as a
  `(type) expr` cast prefix. `object` turns a scalar or array into an object
  (useful for a JSON field a consumer expects to be an object); it is the one
  cast whose result cannot be rendered directly, so pipe it into a serializer:
  `{{ x |> object |> json }}`.

  There is one spelling per cast. `(integer)`, `(boolean)` and `(double)` are
  **not** casts — `int`, `bool` and `float` spell the same conversion more
  briefly, and refusing the long forms keeps a cast from shadowing a name a
  template plausibly holds as data. `(real)` and `(unset)` are not casts either:
  PHP removed both in 8.0. `(binary)` is not a cast because it does nothing — a
  legacy alias for `(string)` with byte-identical output, not a base-2
  conversion. All six keep their previous meaning: a parenthesised variable read.

  The cast syntax is GRAMMAR, not a capability: it needs no rule, appears in no
  preset, and works under `restricted()` exactly as under `unrestricted()`. No
  policy digest changes. A cast is told apart from `(a) + b` by whether what
  follows the `)` can open an operand — the whitespace is optional, so
  `{{ (int)x }}` and `{{ (int) x }}` are the same cast. See
  [Cast Syntax](docs/01-template-syntax.md#cast-syntax).

  Because the cast name alone decides, three spellings change meaning for a
  template that holds a variable named after a cast type:

  | Template     | Before                        | After                |
  | ------------ | ----------------------------- | -------------------- |
  | `(int)(x)`   | a call on the variable `int`  | a cast of the `(x)`  |
  | `(int)[0]`   | an index into the variable    | a cast of `[0]`      |
  | `(int)-5`    | the variable `int` minus `5`  | a cast of `-5`       |

  Every other previously valid reading of `( … )` compiles to the same PHP as
  before: a name that is not one of the six cast types never reaches the rule.

  This is what a template uses to pass through `strictTypes` deliberately. The
  numeric built-ins still do **not** cast their input, so a numeric string
  reaching `round`, `ceil`, `floor` or `abs` remains a `TypeError` under the
  default policy:

  ```twig
  {{ '3.7' |> round(2) }}          {# TypeError — unchanged, on purpose #}
  {{ '3.7' |> float |> round(2) }} {# 3.7 — the conversion is stated    #}
  ```

  That contrast is the point rather than a wart. A filter quietly accepting the
  wrong type is the failure `strictTypes` was turned on to eliminate, and
  `number` is the sole remaining exception because `number_format()` has no
  `string` overload at all. `intval`, `floatval`, `strval` and `boolval` are
  deliberately not registered: they are reachable in PHP mode as ordinary
  functions, so registering them would duplicate these casts under a second
  naming convention.

  `COMPILER_VERSION` moves 30 → 32: the cast grammar decides how a `( … )` group
  is read, so a template compiled before it must not be reused against a
  tokenizer that could read it differently. The glued form widened what that
  covers, so the bump is to 32 rather than 31 — both belong to the same unmerged
  release.

- **`t` accepts a per-call locale, and `nil` is a keyword.** Two gaps closed
  around locale handling:

  ```twig
  {{ "subject" |> t(locale: "fr_FR") }}
  {{ "welcome" |> t({name: user:name}, domain: "emails", locale: "de_DE") }}
  {{ user:middle_name ?? nil }}
  ```

  `t(key, vars?, domain?, locale?)` gains a trailing `locale`, matching the
  intl filters' optional trailing `locale`. It outranks both an enclosing
  `{% with_locale %}` block and the module's own `locale` option, so one string
  can be translated without scoping the surrounding page:

  ```twig
  {# the page stays en_US; only this lookup is French #}
  {{ "subject" |> t(locale: "fr_FR") }}
  ```

  Previously the only way to do this was a `{% with_locale %}` block, which also
  changed the locale of any formatting inside it.

  `nil` is now a keyword spelled alongside `null`, in both value and test
  position (`{{ nil }}`, `{{ x is nil }}`, `{{ f(nil) }}`). It compiles to the
  same PHP literal, so it is a shorter spelling rather than a second value, and
  it shadows a passed variable of the same name exactly as `null` always has.

- **`ArrayTranslationLoader` — translations held in a PHP array.** The fourth
  loader, and the one `ChainTranslationLoader`'s docblock had been advertising
  all along: it named an `ArrayTranslationLoader` that did not exist. It serves
  the same role as the template engine's `ArrayLoader` — tests without a fixture
  directory, and strings that belong beside the code using them — and composes
  with the others, so a small array can override a few keys from the files:

  ```php
  'loader' => new ChainTranslationLoader(
      new FileTranslationLoader(__DIR__ . '/locales'),
      new ArrayTranslationLoader(['messages' => ['de_DE' => ['greeting' => 'Servus']]]),
  ),
  ```

  It flattens nested keys to dot notation and stringifies values, both shared
  with `FileTranslationLoader` through a new `CatalogNormalizationTrait` — the
  two must agree on the shape of a catalog or the same lookup would resolve
  differently depending on which loader read it. Flattening happens once, in the
  constructor; `load()` returns the stored map unchanged, and `set()` writes a
  single flat entry, so a dotted key never rewrites its branch.

- **The translation loader architecture is documented.** `TranslationModule`
  has always taken a pluggable `loader` (`TranslationLoaderInterface`), with
  `FileTranslationLoader`, `ChainTranslationLoader` and `RedisCachingLoader`
  shipped — but none of it appeared outside the generated API pages, and
  `RedisCachingLoader` was mentioned nowhere at all. `07-modules.md` now covers
  the interface, the `loader` option, chaining, the Redis decorator (including
  that it needs `ext-redis` and how to reach `invalidate()`), and how to write a
  loader. `composer.json` now suggests `ext-redis` beside `ext-intl`, and the
  loaders have tests for the first time
  (`tests/Engine/TranslationLoaderArchitectureTest.php`) — including a pin on the
  per-lookup behaviour described under *Fixed* below.

### Fixed

- **`true`, `false` and `null` had stopped being keywords.** They were dropped
  from the tokenizer's keyword map when `nil` was added to it — the map is one
  array literal, and the edit re-listed its entries without `true`, `false` or
  `null`. A bare `{{ true }}`, `{{ null }}` or `{{ flag ? a : b }}` in a
  template then threw `Variable "true" is not defined in this context`;
  `{% if true %}` kept working, because that path tests the token separately.

  All three are restored, and a test now pins them: the loss survived a
  full-suite run precisely because nothing asserted a bare literal outside a
  directive.

- **A keyword now resolves in a ternary condition.** The identifier's
  continuation gate hands `a ? b : c` to the chain parser, and the condition is
  exactly where a literal sits — so the keyword test, which ran behind that
  gate, never fired for `{{ true ? 'y' : 'n' }}`. It reported `true` as an
  undefined variable while `{% if true %}` worked. The keyword test now runs
  before the gate, still bounded by identifier characters on both sides so that
  `nullable` stays a variable and `obj:true` still reads a key named `true`.

- **`currency_name` and `currency_symbol` threw instead of resolving.** Both
  read ICU's `ICUDATA-curr` bundle and used `isset($entry[1])` as a presence
  check. An ICU currency entry is itself a `ResourceBundle`, which implements
  `Countable` and `Traversable` but **not** `ArrayAccess`, so `isset()` on an
  offset is a fatal `Cannot use object of type ResourceBundle as array` — a
  working `{{ currency_name("USD") }}` was impossible. The entry is now read
  through `get(0)`/`get(1)`, and an unknown code falls back to the code itself.

- **`TranslationModule`'s catalog memoization was dead code, and is gone.**
  `7818c46` ("introduce `ChainTranslationLoader` and `FileTranslationLoader`")
  moved the file reading out of the module and into `FileTranslationLoader`, but
  only the *reads* of `$this->catalog` came along — the writes stayed behind. The
  field was thereafter permanently empty, so its fast path and both `isset()`
  guards were dead branches that always fell through to the loader: a lookup
  consulted the loader every single time. The interface docblock asserted the
  opposite ("caches them internally"), which is how the regression went
  unnoticed. The field, the fast path and the guards are removed — each dropped
  branch was always-true, so behaviour is unchanged — and the docblock now places
  caching with the loader.

- **`TranslationModule::getLoader()` exposes the loader.** It is injectable, and
  a decorator such as `RedisCachingLoader` has an `invalidate()` that was
  reachable from nowhere in the library. The module is registered as the `t`
  service, so `$engine->getService('t')->getLoader()` now returns it and
  invalidation is possible without holding a separate reference.

### Changed

- **Every locale parameter is now named `locale`.** The intl filters had split
  between `locale` (12 of them) and `loc` (`country_name`, `language_name`,
  `locale_name`, `format_message`), and the module docs abbreviated all sixteen
  as `$loc`, which was callable on none of them — `format_number(loc: "de_DE")`
  threw `Unknown named parameter $loc`. Named arguments are matched against the
  parameter name and are not aliased, so a wrong spelling is an error rather
  than a silent fallback. All sixteen now use `locale`, which is also the config
  key on all three localization modules. **Update any `loc:` call** to
  `locale:`.

- **`DumpOptions` is fluent.** Every dump option now has a method of the same
  name beside its constructor argument, so the same object can be composed with
  named arguments and then adjusted:

  ```php
  $opts = (new DumpOptions())
      ->maxDepth(4)
      ->maxItems(20)
      ->maskKeys(['password', 'token'])
      ->showPanel();

  $engine->setDebugMode($opts);

  $opts->maxDepth(2);   // still applies, next render onward
  ```

  The chain is **mutable**: each method changes the instance and returns it, so
  a `DumpOptions` handed to the engine earlier can be narrowed later, and every
  holder of the object sees the change. The two boolean options take no argument
  as shorthand for `true` — `->showPanel()` turns the panel on, `->showPanel(false)`
  off — and `maskKey()` / `unmaskKey()` adjust the mask list one entry at a time
  beside `maskKeys()`, which replaces it.

  The five options are now `private`, with `getMaxDepth()`, `getMaxItems()`,
  `getMaskKeys()`, `getForceToTemplate()` and `getShowPanel()` replacing the
  former `public readonly` properties — a mutator and a `readonly` property
  cannot coexist. `$opts->maxDepth` therefore becomes `$opts->getMaxDepth()`;
  the renderers, which were the only readers, use the getters.

- **`addInlineFunction()` — inline codegen with no piped form.** A new registry
  record member, `filter => false`, marks an inline definition as CALL-ONLY: the
  same `{1}`-templated codegen backs `name(...)`, while `value |> name` is refused
  at compile time with the existing “is a function, not a filter” message. It
  fills the one gap the two older tables could not express — a name that is
  callable without being a runtime callable, and call-only without being listed in
  the pipeable set.

  `isset` is the built-in in this shape. It is a presence probe on a variable
  chain, so its argument is source the compiler must see, not a value to
  transform: `x |> isset` would ask whether the already-compiled piped expression
  exists, which is a constant.

  ```twig
  {{ isset(user:email) }}          {# isset($__c_va['user']['email']) #}
  {{ name |> isset }}              {# compile error: a function, not a filter #}
  ```

  A record may also declare a `callGuard`, which validates its first argument
  while compiling. `isset` uses `presence`, which accepts only a name or a chain
  over one. Previously `isset(1 + 1)` compiled to `isset((…))` and died as a PHP
  fatal — “Cannot use isset() on the result of an expression” — inside the
  compiled cache file; a template author now gets a located `ClarityException`
  naming the construct. The guard runs on the COMPILED operand, so every access
  operator is accepted (`user:email`, `user.email`, `items[0]`).

  `COMPILER_VERSION` moves 29 → 30, so previously cached templates are recompiled
  automatically.

### Changed

- **`$engine->use()` is now `$engine->addModule()`.** The one public method on the
  engine that registers a `ModuleInterface` was also the only registration method
  not named `add*`, next to `addNamespace()`, `addFilter()`, `addFunction()`,
  `addDirective()`, `addService()`, `addInlineFilter()` and `addInlineFunction()`.
  A module is what is being added, so the call now says so:

  ```php
  // was
  $engine->use(new IntlFormatModule(['locale' => 'en_US']));

  // now
  $engine->addModule(new IntlFormatModule(['locale' => 'en_US']));
  ```

  `use()` is removed rather than aliased — an alias would preserve exactly the
  ambiguity the rename exists to remove, and the module system has no other
  meaning for “use”. The method’s behaviour is unchanged: it still calls
  `$module->register($this)` and returns `static` for chaining. Update any
  `$engine->use(...)` call to `$engine->addModule(...)`; nothing else about
  `ModuleInterface` changes.

- **`LocaleService` is a module.** The localization stack was documented — in
  its own docblock, in `07-modules.md`, and in the generated API pages — as
  something you register with `$engine->addModule(new LocaleService([...]))`.
  It could not be: the class had no `__construct()` and no `register()`, so every
  one of those examples died with a `TypeError` at the `addModule()` call, and the
  `locale` option they passed was ignored. It now implements `ModuleInterface`;
  the documented calls work and the option does what it says:

  ```php
  $engine->addModule(new LocaleService(['locale' => 'de_DE']));
  ```

  `register()` puts the locale stack on the engine and installs the
  `with_locale` directives. The configured `locale` is kept **off** the stack as
  an application-wide default rather than pushed onto it: a stack entry
  outranks every module's own `locale` option, so seeding it would have made
  `IntlFormatModule(['locale' => 'fr_FR'])` silently format in the service's
  locale instead. Kept beside the stack, the default is only consulted when a
  module configures no locale of its own, so `TranslationModule` and
  `IntlFormatModule` can still be configured independently.

  Registration is a **no-op when a locale service is already installed**, so
  the order the docs recommended is no longer the only safe one:
  `TranslationModule` and `IntlFormatModule` may bootstrap the service first
  (they still do, unchanged) and an explicit `LocaleService` registered
  afterwards leaves that instance, and anyone holding it, alone — while still
  contributing its default if the installed service had none.

  With no `locale` option the stack starts empty, exactly as
  `LocaleService::bootstrap()` leaves it, so a module that bootstrapped first
  keeps falling back to its own configured `locale`. The new `defaultLocale()`
  accessor reports the configured default; `current()` reports only what a
  `{% with_locale %}` block is applying, and stays `null` outside one.
  `bootstrap()` and `registerBlocks()` are unchanged and stay public for code
  that manages the service itself.

- **The registry's codegen table is now `$inlineDefinitions`.** It was
  `$inlineFilters`, which stopped being accurate the moment a record could be a
  call-only function rather than a filter. The table answers one question — *how
  does this name compile?* — so the neutral name states it. The public API is
  unchanged (`addInlineFilter()`, `hasInlineFilter()`, `getInlineFilter()`), and
  `hasInlineFilter()` still means “has codegen”; `hasFilter()` is what answers
  “may be piped”. `Registry::isInlineFunction()` is new and asks the call-only
  question directly.

- **Compiled templates now emit arrow functions for lambdas and quoted filter
  references.** A lambda body and a quoted filter reference used to compile to a
  `static function (…) use (…) { … }`, which made `$this` unreachable inside them:
  a directive handler or inline filter that named `$this->services['key']` broke
  the moment the compiler emitted it inside one of those closures, forcing module
  authors to learn which of two spellings was safe in which position. Both are now
  non-static arrow functions (`fn(…) => …`), which are created in `render()` and
  therefore inherit `$this`. `$this->services['key']` and `$__c_sv['key']` are
  interchangeable everywhere. `COMPILER_VERSION` moves 28 → 29, so previously
  cached templates are recompiled automatically.

- **Compiled-template properties renamed to `functions` / `services`.** A compiled
  class was generated with `public function __construct(private array $__c_fn, private
  array $__c_sv)`, so the only spelling available to a module author emitting directive
  or inline-filter PHP was the cryptic `$__c_sv['key']`. The constructor properties are
  now `$functions` and `$services`, which reads as `$this->services['locale']`:

  ```php
  public function __construct(private array $functions, private array $services) {}
  ```

  The render-frame **locals keep the `__c_` spelling** (`$__c_fn`, `$__c_sv`): they are
  the spelling the engine's own snippets use and the one a module may rely on.
  `render()` unpacks each local from its property only when the compiled body actually
  references it, so a template that touches neither pays nothing. Every position the
  compiler emits a handler's code — a directive body, or a closure it wraps a lambda
  body or quoted filter reference in — reaches the same table through either spelling.
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
