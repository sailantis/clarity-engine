# Class: ClarityEngineTrait

**Full name:** [Clarity\ClarityEngineTrait](../../src/ClarityEngineTrait.php)

## Public methods

### setDebugMode() · <small>[🗎](../../src/ClarityEngineTrait.php#L81)</small>

`public function setDebugMode(Clarity\Debug\DumpOptions|bool|null $debug = true): static`

Enable or disable the debug mode for the engine.

```php
$engine->setDebugMode(true);                // debug defaults
$engine->setDebugMode(new DumpOptions(showPanel: true, maxDepth: 3));
$engine->setDebugMode(false);               // production
```

Debug mode affects the following aspects of the engine:

- compiler-level runtime assertions (range-loop safety checks);
- `dump()` rendered by the context-aware renderers — an HTML tree in HTML,
  a `;/* DEBUG_DUMP *\/` comment in JS, and a `/* DEBUG_DUMP *\/` comment
  in CSS — with sensitive keys masked;
- `{{ x |> dump }}`, which dumps the piped value at the pipe position and
  still yields it (`{{ x |> dump |> length }}` measures x);
- a [`DebugEventBus`](Clarity_Debug_DebugEventBus.md) emitting `template.resolve`, `template.compile`,
  `template.cached` and `template.render`;
- the HTML debug panel, when `DumpOptions::showPanel()` is set.

`true` enables debug with default options. Passing a [`DumpOptions`](Clarity_Debug_DumpOptions.md)
enables it with those options. `false` and `null` both disable it, and
`false` is equivalent to `disableDebug()`.

`dd()` is the one exception: it is never pruned, so the registry refuses
it while debug is off instead of dumping raw, unmasked values.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$debug` | [DumpOptions](Clarity_Debug_DumpOptions.md)\|bool\|null | `true` | True/options to enable, false to disable. |

**Return value**

- Type: `static`


---

### isDebugMode() · <small>[🗎](../../src/ClarityEngineTrait.php#L130)</small>

`public function isDebugMode(): bool`

Return whether debug mode is currently enabled.

**Return value**

- Type: `bool`


---

### setPolicy() · <small>[🗎](../../src/ClarityEngineTrait.php#L157)</small>

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

Security: a policy that grants `rawPhp`, `phpVariables` or `methodCalls`
is equivalent to executing arbitrary PHP. Use it only for templates written
by trusted authors. Templates compiled under one policy are recompiled
automatically under another.

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

Always a real object: a freshly built engine returns
[`Policy::restricted()`](Clarity_Engine_Policy.md#restricted). For coarse questions, query it directly, for
example `getPolicy()->isSandboxed()`.

**Return value**

- Type: [Policy](Clarity_Engine_Policy.md)


---

### isSandboxed() · <small>[🗎](../../src/ClarityEngineTrait.php#L184)</small>

`public function isSandboxed(): bool`

Whether PHP is unreachable under the current policy.

Equivalent to `getPolicy()->isSandboxed()`. Returns true only when the
policy grants no PHP-reaching rule.

**Return value**

- Type: `bool`


---

### enableDebug() · <small>[🗎](../../src/ClarityEngineTrait.php#L203)</small>

`public function enableDebug(Clarity\Debug\DumpOptions|null $opts = null): static`

Enable full debug mode. Equivalent to `setDebugMode()`.

```php
$engine->enableDebug();   // default options
$engine->enableDebug(new DumpOptions(showPanel: true, maxDepth: 4));
```

**Deprecated**: Use {@see \setDebugMode()}. Passing `true` or a {@see \DumpOptions} enables debug the same way.
Kept as an alias so existing code keeps working.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$opts` | [DumpOptions](Clarity_Debug_DumpOptions.md)\|null | `null` | Options for depth, masking, and the panel. |

**Return value**

- Type: `static`


---

### disableDebug() · <small>[🗎](../../src/ClarityEngineTrait.php#L216)</small>

`public function disableDebug(): static`

Disable debug mode and tear down everything it installed: the event bus,
the panel, and the registry's dump/dd handlers.

**Deprecated**: Use {@see setDebugMode(false)} instead.

**Return value**

- Type: `static`


---

### getDebugBus() · <small>[🗎](../../src/ClarityEngineTrait.php#L224)</small>

`public function getDebugBus(): Clarity\Debug\DebugEventBus|null`

Return the active DebugEventBus, or null when debug mode is off.

**Return value**

- Type: [DebugEventBus](Clarity_Debug_DebugEventBus.md)|`null`


---

### getDebugPanel() · <small>[🗎](../../src/ClarityEngineTrait.php#L233)</small>

`public function getDebugPanel(): Clarity\Debug\HtmlDebugPanel|null`

Return the active HtmlDebugPanel, or null when debug mode is off or the
panel is not enabled via `DumpOptions::showPanel()`.

**Return value**

- Type: [HtmlDebugPanel](Clarity_Debug_HtmlDebugPanel.md)|`null`


---

### getDebugOptions() · <small>[🗎](../../src/ClarityEngineTrait.php#L243)</small>

`public function getDebugOptions(): Clarity\Debug\DumpOptions|null`

Return the DumpOptions in use by the active debug runtime, or null when
debug mode is off. Options are mutable, so changes take effect on the
next `dd()` or `dump()`.

**Return value**

- Type: [DumpOptions](Clarity_Debug_DumpOptions.md)|`null`


---

### setViewPath() · <small>[🗎](../../src/ClarityEngineTrait.php#L254)</small>

`public function setViewPath(string $path): static`

Set the base path for resolving relative template names.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Base directory for templates. |

**Return value**

- Type: `static`


---

### getViewPath() · <small>[🗎](../../src/ClarityEngineTrait.php#L280)</small>

`public function getViewPath(): string`

Get the currently configured base path for view resolution.

**Return value**

- Type: `string`
- Description: Base directory for views.


---

### setExtension() · <small>[🗎](../../src/ClarityEngineTrait.php#L291)</small>

`public function setExtension(string $ext): static`

Set the view file extension for this instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ext` | string | - | Extension with or without a leading dot. |

**Return value**

- Type: `static`


---

### getExtension() · <small>[🗎](../../src/ClarityEngineTrait.php#L308)</small>

`public function getExtension(): string`

Get the file extension used when resolving templates.

**Return value**

- Type: `string`
- Description: Extension including leading dot, or empty string for no extension.


---

### addNamespace() · <small>[🗎](../../src/ClarityEngineTrait.php#L322)</small>

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

### getNamespaces() · <small>[🗎](../../src/ClarityEngineTrait.php#L349)</small>

`public function getNamespaces(): array`

Get the currently registered view namespaces.

**Return value**

- Type: `array`
- Description: Associative array of namespace => path mappings.


---

### addModule() · <small>[🗎](../../src/ClarityEngineTrait.php#L371)</small>

`public function addModule(Clarity\ModuleInterface $module): static`

Register a module, granting it access to this engine instance so it can
self-register filters, functions, services, and directives.

Modules are the recommended way to bundle related features (e.g. a full
localization set with filters, a locale stack, and `with_locale` directives).

```php
$engine->addModule(new \Clarity\Localization\TranslationModule([
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

### addInlineFilter() · <small>[🗎](../../src/ClarityEngineTrait.php#L399)</small>

`public function addInlineFilter(string $name, array $definition): static`

Register an inline filter definition that is compiled directly into the
generated PHP render body, so no runtime call is made.

The definition must follow the same format as the built-in inline filters:
```php
$engine->addInlineFilter('my_upper', [
    'php' => '\mb_strtoupper((string) {1})',
]);
$engine->addInlineFilter('my_substr', [
    'php' => '\mb_substr((string) {1}, {2}, {3})',
    'params' => ['start', 'length'],
    'defaults' => ['length' => 'null'],
]);
```
Placeholders: `{1}` is the piped value, and `{2}`, `{3}`, … are the
parameters declared in `params`, in order.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name. |
| `$definition` | array | - |  |

**Return value**

- Type: `static`


---

### addInlineFunction() · <small>[🗎](../../src/ClarityEngineTrait.php#L432)</small>

`public function addInlineFunction(string $name, array $definition): static`

Register an inline function: codegen that compiles into the template but
cannot be used with the pipe operator.

`addInlineFunction()` is to `addInlineFilter()` what `addFunction()` is to
`addFilter()`: the call form only. The `php` template backs `name(...)`
the same way it would for a filter, while `value |> name` is a compile-time
error.

Use it when the argument is source code rather than a value to transform,
so a piped form has no meaning:

```php
$engine->addInlineFunction('isset', [
    'php'       => 'isset({1})',
    'callGuard' => 'presence',
]);
```

`callGuard` enforces the construct's restrictions at compile time. The
`presence` guard requires the first argument to be a bare name or a chain
over one, which is all PHP's `isset()` accepts.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Function name used in templates. |
| `$definition` | array | - |  |

**Return value**

- Type: `static`


---

### addDirective() · <small>[🗎](../../src/ClarityEngineTrait.php#L477)</small>

`public function addDirective(string $keyword, callable $handler, Clarity\Engine\Directive|null $directive = null): static`

Register a handler for a custom directive (e.g. `with_locale`).

The handler is a callable that receives the raw text after the keyword, a
[`TemplateLocation`](Clarity_Template_TemplateLocation.md) for error messages, and a `$expr` callable
that converts a Clarity expression string to a PHP expression string.
It must return a PHP statement string.

```php
$engine->addDirective('with_locale', function(string $rest, TemplateLocation $at, callable $expr): string {
    return "\$__c_sv['locale']->push({$expr(trim($rest))});";
});
$engine->addDirective('endwith_locale', fn(...) => "\$__c_sv['locale']->pop();");
```

Paired directives
-----------------
A directive that wraps a body declares its parts with a [`Directive`](Clarity_Engine_Directive.md)
whose factory name states the role:
```php
$engine->addDirective('cache',      $openHandler,   Directive::opens('endcache', 'cache_else'));
$engine->addDirective('cache_else', $branchHandler, Directive::branches('cache'));
$engine->addDirective('endcache',   $closeHandler,  Directive::closes('cache'));
```
The compiler then rejects an unclosed `{% cache %}`, a stray `{% endcache %}`,
a close that crosses another construct, and a branch tag used outside its
construct — all at compile time, naming the template and line.

A leaf that may only appear within a construct says so with `inside()`, which
asserts no structure — the tag stays an ordinary directive:
```php
$engine->addDirective('cache_control', $leafHandler, Directive::inside('cache'));
```

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - | The directive keyword in lowercase (e.g. 'with_locale'). |
| `$handler` | callable | - | See [`Registry`](Clarity_Engine_Registry.md) for the expected signature. |
| `$directive` | [Directive](Clarity_Engine_Directive.md)\|null | `null` | Omit for an ordinary directive; see [`Directive`](Clarity_Engine_Directive.md). |

**Return value**

- Type: `static`


---

### addService() · <small>[🗎](../../src/ClarityEngineTrait.php#L495)</small>

`public function addService(string $name, mixed $service): static`

Store a service object in the registry so that compiled template render
bodies can access it via `$__c_sv['key']` or `$this->services['key']`.

This is primarily used by modules that need shared mutable state (e.g. a
locale stack) accessible both from closures that close over the object
*and* from inline filter PHP templates using `$this->services['key']->method()`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Key under which the service is accessible. |
| `$service` | mixed | - | Service value, can be of any type. |

**Return value**

- Type: `static`


---

### hasService() · <small>[🗎](../../src/ClarityEngineTrait.php#L504)</small>

`public function hasService(string $name): bool`

Return true if a service with the given key has been registered.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### getService() · <small>[🗎](../../src/ClarityEngineTrait.php#L514)</small>

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

### addFilter() · <small>[🗎](../../src/ClarityEngineTrait.php#L568)</small>

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
- Text: `upper`, `lower`, `trim`, `truncate`, `escape`, `raw`, `slug`
- Numbers: `number`, `abs`, `round`, `ceil`, `floor`
- Arrays: `join`, `length`, `first`, `last`, `keys`, `values`, `map`, `filter`, `reduce`
- Dates: `date`, `date_modify`, `format_datetime`
- Other: `json`, `default`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name used in templates (e.g. 'currency'). |
| `$fn` | callable | - | Callable with signature: fn($value, ...$args): mixed |

**Return value**

- Type: `static`
- Description: Fluent interface


---

### addFunction() · <small>[🗎](../../src/ClarityEngineTrait.php#L584)</small>

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

### setLoader() · <small>[🗎](../../src/ClarityEngineTrait.php#L596)</small>

`public function setLoader(Clarity\Template\TemplateLoader $loader): static`

Set a custom template loader, replacing the default FileLoader.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$loader` | [TemplateLoader](Clarity_Template_TemplateLoader.md) | - | The loader to use. |

**Return value**

- Type: `static`


---

### getLoader() · <small>[🗎](../../src/ClarityEngineTrait.php#L609)</small>

`public function getLoader(): Clarity\Template\TemplateLoader`

Return the active template loader, lazily creating a FileLoader if none
has been set explicitly.

**Return value**

- Type: [TemplateLoader](Clarity_Template_TemplateLoader.md)


---

### setCachePath() · <small>[🗎](../../src/ClarityEngineTrait.php#L648)</small>

`public function setCachePath(string $path): static`

Set the directory where compiled templates should be cached.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Absolute path to the cache directory. |

**Return value**

- Type: `static`


---

### getCachePath() · <small>[🗎](../../src/ClarityEngineTrait.php#L659)</small>

`public function getCachePath(): string`

Get the currently configured cache directory.

**Return value**

- Type: `string`
- Description: Absolute path to the cache directory.


---

### flushCache() · <small>[🗎](../../src/ClarityEngineTrait.php#L669)</small>

`public function flushCache(): static`

Flush all cached compiled templates.

**Return value**

- Type: `static`


---

### render() · <small>[🗎](../../src/ClarityEngineTrait.php#L720)</small>

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
$engine->setLayout(null); // Subsequent renders use no layout
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
- Description: Rendered HTML/output, with the debug panel HTML appended when the panel is enabled.

**Throws**

- [ClarityException](Clarity_ClarityException.md)  If the template is not found, fails to compile, or throws at runtime.


---

### renderPartial() · <small>[🗎](../../src/ClarityEngineTrait.php#L742)</small>

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

### renderLayout() · <small>[🗎](../../src/ClarityEngineTrait.php#L768)</small>

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
