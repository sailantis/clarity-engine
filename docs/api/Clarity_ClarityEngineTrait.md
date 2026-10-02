# Class: ClarityEngineTrait

**Full name:** [Clarity\ClarityEngineTrait](../../src/ClarityEngineTrait.php)

## Public methods

### setDebugMode() · <small>[🗎](../../src/ClarityEngineTrait.php#L80)</small>

`public function setDebugMode(Clarity\Debug\DumpOptions|bool|null $debug = true): static`

Turn debug mode on or off — the single debug switch.

```php
$engine->setDebugMode(true);                       // full debug, defaults
$engine->setDebugMode(new DumpOptions(maxDepth: 3));
$engine->setDebugMode(false);                      // production
```

Turning it ON installs the whole debug experience in one step, and
turning it OFF removes all of it:

- compiler-level runtime assertions (range-loop safety checks);
- `dump()` rendered by the context-aware renderers — an HTML tree in HTML,
  a `/* DEBUG_DUMP *\/` comment in JS — with sensitive keys masked;
- `{{ x |> dump }}`, which dumps the piped value at the pipe position and
  still yields it (`{{ x |> dump |> length }}` measures x);
- a [`DebugEventBus`](Clarity_Debug_DebugEventBus.md) emitting `template.resolve`, `template.compile`
  and `template.render`;
- the HTML debug panel, when `DumpOptions::$showPanel` is set.

Passing [`DumpOptions`](Clarity_Debug_DumpOptions.md) is shorthand for "on, with these options" —
`$debug instanceof DumpOptions` and `$debug === null` both mean "on".
`$debug === false` is exactly `disableDebug()`.

`dd()` is the one exception: it is never pruned, so the registry refuses
it while debug is off instead of dumping raw, unmasked values.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$debug` | [DumpOptions](Clarity_Debug_DumpOptions.md)\|bool\|null | `true` | True/options to enable, false to disable. |

**Return value**

- Type: `static`


---

### isDebugMode() · <small>[🗎](../../src/ClarityEngineTrait.php#L129)</small>

`public function isDebugMode(): bool`

Return whether debug mode is currently enabled.

**Return value**

- Type: `bool`


---

### setPolicy() · <small>[🗎](../../src/ClarityEngineTrait.php#L156)</small>

`public function setPolicy(Clarity\Engine\Policy|array $policy): static`

Set what compiled templates are allowed to reach.

A policy is a set of rules plus two allowlists; see
[`Policy`](Clarity_Engine_Policy.md).  Start from a preset and change what you
mean to change:

```php
$engine->setPolicy(Policy::unrestricted());
$engine->setPolicy(Policy::default()
    ->allowRule('methodCalls')
    ->allowFunctions('strtoupper', 'count'));
```

SECURITY: a policy that grants `rawPhp`, `phpVariables` or
`methodCalls` is equivalent to executing arbitrary PHP and is intended for
templates written by trusted authors only.  Templates compiled under one
policy are automatically recompiled under another.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$policy` | [Policy](Clarity_Engine_Policy.md)\|array | - | A policy. |

**Return value**

- Type: `static`


---

### getPolicy() · <small>[🗎](../../src/ClarityEngineTrait.php#L173)</small>

`public function getPolicy(): Clarity\Engine\Policy`

The policy templates are currently compiled under.

Always a real object: a freshly built engine answers with
[`Policy::restricted()`](Clarity_Engine_Policy.md#restricted).  Use it for coarse questions rather than
keeping a second flag that could disagree with it — `getPolicy()->isSandboxed()`
answers what the old `isSandboxed()` answered.

**Return value**

- Type: [Policy](Clarity_Engine_Policy.md)


---

### isSandboxed() · <small>[🗎](../../src/ClarityEngineTrait.php#L184)</small>

`public function isSandboxed(): bool`

Whether the current policy lets templates reach PHP at all.

Kept because it reads better than `getPolicy()->allowsPhp()` at a call site
that only wants the coarse answer.

**Return value**

- Type: `bool`


---

### enableDebug() · <small>[🗎](../../src/ClarityEngineTrait.php#L205)</small>

`public function enableDebug(Clarity\Debug\DumpOptions|null $opts = null): static`

Enable full debug mode.

**Deprecated**: Use {@see \setDebugMode()} — the two debug entry points have
            been unified, and `setDebugMode(true)` (or passing
            {@see \DumpOptions}) now installs exactly what this method did.
            Kept as an alias so existing code keeps working.

```php
$engine->enableDebug();   // default options
$engine->enableDebug(new DumpOptions(showPanel: true, maxDepth: 4));
```

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$opts` | [DumpOptions](Clarity_Debug_DumpOptions.md)\|null | `null` | Customise depth, masking, panel, etc. |

**Return value**

- Type: `static`


---

### disableDebug() · <small>[🗎](../../src/ClarityEngineTrait.php#L218)</small>

`public function disableDebug(): static`

Disable debug mode and tear down everything it installed: the event bus,
the panel, and the registry's dump/dd handlers.

**Deprecated**: Use {@see setDebugMode(false)} instead.

**Return value**

- Type: `static`


---

### getDebugBus() · <small>[🗎](../../src/ClarityEngineTrait.php#L226)</small>

`public function getDebugBus(): Clarity\Debug\DebugEventBus|null`

Return the active DebugEventBus, or null when debug mode is off.

**Return value**

- Type: [DebugEventBus](Clarity_Debug_DebugEventBus.md)|`null`


---

### getDebugPanel() · <small>[🗎](../../src/ClarityEngineTrait.php#L234)</small>

`public function getDebugPanel(): Clarity\Debug\HtmlDebugPanel|null`

Return the active HtmlDebugPanel, or null when disabled.

**Return value**

- Type: [HtmlDebugPanel](Clarity_Debug_HtmlDebugPanel.md)|`null`


---

### setViewPath() · <small>[🗎](../../src/ClarityEngineTrait.php#L245)</small>

`public function setViewPath(string $path): static`

Set the base path for resolving relative template names.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Base directory for templates. |

**Return value**

- Type: `static`


---

### getViewPath() · <small>[🗎](../../src/ClarityEngineTrait.php#L271)</small>

`public function getViewPath(): string`

Get the currently configured base path for view resolution.

**Return value**

- Type: `string`
- Description: Base directory for views.


---

### setExtension() · <small>[🗎](../../src/ClarityEngineTrait.php#L282)</small>

`public function setExtension(string $ext): static`

Set the view file extension for this instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ext` | string | - | Extension with or without a leading dot. |

**Return value**

- Type: `static`


---

### getExtension() · <small>[🗎](../../src/ClarityEngineTrait.php#L299)</small>

`public function getExtension(): string`

Get the effective file extension used when resolving templates.

**Return value**

- Type: `string`
- Description: Extension including leading dot or empty string.


---

### addNamespace() · <small>[🗎](../../src/ClarityEngineTrait.php#L313)</small>

`public function addNamespace(string $name, string $path): static`

Add a namespace for view resolution.

Views can be referenced using the syntax "namespace::view.name".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Namespace name to register. |
| `$path` | string | - | Filesystem path corresponding to the namespace. |

**Return value**

- Type: `static`


---

### getNamespaces() · <small>[🗎](../../src/ClarityEngineTrait.php#L340)</small>

`public function getNamespaces(): array`

Get the currently registered view namespaces.

**Return value**

- Type: `array`
- Description: Associative array of namespace => path mappings.


---

### use() · <small>[🗎](../../src/ClarityEngineTrait.php#L362)</small>

`public function use(Clarity\ModuleInterface $module): static`

Register a module, granting it access to this engine instance so it can
self-register filters, functions, services, and directives.

Modules are the recommended way to bundle related features (e.g. a full
localization set with filters, a locale stack, and `with_locale` directives).

```php
$engine->use(new \Clarity\LocalizationModule([
    'locale'            => 'de_DE',
    'translations_path' => __DIR__ . '/locales',
]));
```

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$module` | [ModuleInterface](Clarity_ModuleInterface.md) | - | Module to register. |

**Return value**

- Type: `static`


---

### addInlineFilter() · <small>[🗎](../../src/ClarityEngineTrait.php#L390)</small>

`public function addInlineFilter(string $name, array $definition): static`

Register an inline filter definition that is compiled directly into the
generated PHP render body (zero runtime call overhead).

The definition must follow the same format as the built-in inline filters:
```php
$engine->addInlineFilter('my_upper', [
    'php' => '\mb_strtoupper((string) {1})',
]);
$engine->addInlineFilter('my_substr', [
    'php' => '\mb_substr((string) {1}, {2}, {3})',
    'params' => ['start', 'length'],
    'defaults' => ['length' => null],
]);
```
Template placeholders: `{1}` for the piped value, `{2}`, `{3}`, … for
additional parameters are declared in `params`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name. |
| `$definition` | array | - |  |

**Return value**

- Type: `static`


---

### addDirective() · <small>[🗎](../../src/ClarityEngineTrait.php#L415)</small>

`public function addDirective(string $keyword, callable $handler): static`

Register a handler for a custom directive (e.g. `with_locale`).

The handler is a callable that receives the raw text after the keyword,
source path and line for error messages, and a `$processExpr` callable
that converts a Clarity expression string to a PHP expression string.
It must return a PHP statement string.

```php
$engine->addDirective('with_locale', function(string $rest, string $path, int $line, callable $expr): string {
    return "\$__c_sv['locale']->push({$expr(trim($rest))});"
});
$engine->addDirective('endwith_locale', fn(...) => "\$__c_sv['locale']->pop();");
```

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - | The directive keyword in lowercase (e.g. 'with_locale'). |
| `$handler` | callable | - | See [`Registry`](Clarity_Engine_Registry.md) for the expected signature. |

**Return value**

- Type: `static`


---

### addService() · <small>[🗎](../../src/ClarityEngineTrait.php#L433)</small>

`public function addService(string $name, mixed $service): static`

Store a service object in the registry so that compiled template render
bodies can access it via `$__c_sv['key']`.

This is primarily used by modules that need shared mutable state (e.g. a
locale stack) accessible both from closures that close over the object
*and* from inline filter PHP templates using `$__c_sv['key']->method()`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Key under which the service is accessible. |
| `$service` | mixed | - | Service value, can be of any type. |

**Return value**

- Type: `static`


---

### hasService() · <small>[🗎](../../src/ClarityEngineTrait.php#L442)</small>

`public function hasService(string $name): bool`

Return true if a service with the given key has been registered.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### getService() · <small>[🗎](../../src/ClarityEngineTrait.php#L452)</small>

`public function getService(string $name): mixed`

Retrieve a previously registered service.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `mixed`

**Throws**

- RuntimeException  if no service with that name exists.


---

### addFilter() · <small>[🗎](../../src/ClarityEngineTrait.php#L506)</small>

`public function addFilter(string $name, callable $fn): static`

Register a custom filter callable.

Filters transform a piped value and are invoked in templates using pipe syntax:
- Simple filter: `{{ value |> filterName }}`
- Filter with arguments: `{{ value |> filterName(arg1, arg2) }}`
- Chained filters: `{{ value |> filter1 |> filter2 |> filter3 }}`

Filters receive the piped value as the first parameter, followed by any arguments
specified in the template.

**Example: Currency filter**
```php
$engine->addFilter('currency', function($amount, string $symbol = '€') {
    return $symbol . ' ' . number_format($amount, 2);
});
```

Template usage:
```twig
{{ price |> currency }}       {# Output: € 99.99 #}
{{ price |> currency('$') }}  {# Output: $ 99.99 #}
```

**Example: Excerpt filter**
```php
$engine->addFilter('excerpt', function($text, int $length = 100) {
    return mb_strlen($text) > $length
        ? mb_substr($text, 0, $length) . '…'
        : $text;
});
```

Template usage:
```twig
{{ article.body |> excerpt(150) }}
```

**Built-in filters:**
- Text: `upper`, `lower`, `trim`, `truncate`, `escape`, `raw`
- Numbers: `number`, `abs`, `round`, `ceil`, `floor`
- Arrays: `join`, `length`, `first`, `last`, `keys`, `values`, `map`, `filter`, `reduce`
- Dates: `date`, `date_modify`, `format_datetime`
- Other: `json`, `default`, `unicode`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name used in templates (e.g. 'currency'). |
| `$fn` | callable | - | Callable with signature: fn($value, ...$args): mixed |

**Return value**

- Type: `static`
- Description: Fluent interface


---

### addFunction() · <small>[🗎](../../src/ClarityEngineTrait.php#L522)</small>

`public function addFunction(string $name, callable $fn): static`

Register a custom function callable.

Functions are called directly in templates, e.g. `{{ name(arg) }}`.
This is distinct from filters, which transform a piped value.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Function name used in templates (e.g. 'formatDate'). |
| `$fn` | callable | - | fn(...$args): mixed |

**Return value**

- Type: `static`


---

### setLoader() · <small>[🗎](../../src/ClarityEngineTrait.php#L534)</small>

`public function setLoader(Clarity\Template\TemplateLoader $loader): static`

Set a custom template loader, replacing the default FileLoader.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$loader` | [TemplateLoader](Clarity_Template_TemplateLoader.md) | - | The loader to use. |

**Return value**

- Type: `static`


---

### getLoader() · <small>[🗎](../../src/ClarityEngineTrait.php#L547)</small>

`public function getLoader(): Clarity\Template\TemplateLoader`

Return the active template loader, lazily creating a FileLoader if none
has been set explicitly.

**Return value**

- Type: [TemplateLoader](Clarity_Template_TemplateLoader.md)


---

### setCachePath() · <small>[🗎](../../src/ClarityEngineTrait.php#L586)</small>

`public function setCachePath(string $path): static`

Set the directory where compiled templates should be cached.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Absolute path to the cache directory. |

**Return value**

- Type: `static`


---

### getCachePath() · <small>[🗎](../../src/ClarityEngineTrait.php#L597)</small>

`public function getCachePath(): string`

Get the currently configured cache directory.

**Return value**

- Type: `string`
- Description: Absolute path to the cache directory.


---

### flushCache() · <small>[🗎](../../src/ClarityEngineTrait.php#L607)</small>

`public function flushCache(): static`

Flush all cached compiled templates.

**Return value**

- Type: `static`


---

### render() · <small>[🗎](../../src/ClarityEngineTrait.php#L658)</small>

`public function render(string $view, array $vars = []): string`

Render a view template and return the result as a string.

If a layout is configured via setLayout(), the view is first rendered and then
wrapped in the layout. The layout receives the rendered content in the `content`
variable.

Templates are automatically compiled to cached PHP classes. The cache is
automatically invalidated when source files change.

**Basic rendering:**
```php
$html = $engine->render('welcome', [
    'user' => ['name' => 'John', 'email' => 'john@example.com'],
    'title' => 'Welcome Page'
]);
```

**With layout:**
```php
$engine->setLayout('layouts/main');
$html = $engine->render('pages/dashboard', [
    'stats' => $dashboardStats
]);
// The layout receives 'content' variable with rendered 'pages/dashboard'
```

**Without layout (override):**
```php
$engine->setLayout(null); // Temporarily disable layout
$partial = $engine->render('partials/widget', ['data' => $widgetData]);
```

**Namespaced templates:**
```php
$engine->addNamespace('admin', __DIR__ . '/admin_templates');
$html = $engine->render('admin::dashboard', $data);
```

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$view` | string | - | View name to render. Can include namespace prefix (e.g. 'admin::dashboard'). |
| `$vars` | array | `[]` | Variables to pass to the template. Objects stay objects: `a.b`<br>reads a public property while `a:b` reads an array key. |

**Return value**

- Type: `string`
- Description: Rendered HTML/output.

**Throws**

- [ClarityException](Clarity_ClarityException.md)  If template not found or compilation fails.


---

### renderPartial() · <small>[🗎](../../src/ClarityEngineTrait.php#L680)</small>

`public function renderPartial(string $view, array $vars = []): string`

Render a partial view (without applying a layout) and return the output.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$view` | string | - | View name to resolve and render. |
| `$vars` | array | `[]` | Variables for this render call. |

**Return value**

- Type: `string`
- Description: Rendered HTML/output.


---

### renderLayout() · <small>[🗎](../../src/ClarityEngineTrait.php#L706)</small>

`public function renderLayout(string $layout, string $content, array $vars = []): string`

Render a layout template wrapping provided content.

The layout receives the rendered view in the `content` variable.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$layout` | string | - | Layout view name. |
| `$content` | string | - | Previously rendered content. |
| `$vars` | array | `[]` | Additional variables to pass to the layout. |

**Return value**

- Type: `string`
- Description: Rendered layout output.



---

[Back to the Index ⤴](README.md)
