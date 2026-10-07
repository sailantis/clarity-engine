<?php
namespace Clarity;

use Clarity\Debug\DebugEventBus;
use Clarity\Debug\DebugRuntime;
use Clarity\Debug\DumpOptions;
use Clarity\Debug\HtmlDebugPanel;
use Clarity\Engine\Cache;
use Clarity\Engine\Compiler;
use Clarity\Engine\Directive;
use Clarity\Engine\Policy;
use Clarity\Engine\Registry;
use Clarity\Engine\SourceMap;
use Clarity\Template\DomainRouterLoader;
use Clarity\Template\FileLoader;
use Clarity\Template\TemplateLoader;
use Clarity\Template\TemplateLocation;
use ParseError;

trait ClarityEngineTrait
{
    protected Registry $registry;
    protected Cache $cache;
    protected ?Compiler $compiler = null;
    protected ?TemplateLoader $loader = null;
    /** @var string[] */
    protected array $renderStack = [];
    protected bool $debugMode = false;
    protected ?DebugRuntime $debugRuntime = null;
    protected ?DebugEventBus $debugBus = null;
    protected ?HtmlDebugPanel $debugPanel = null;

    /**
     * What compiled templates are allowed to reach.  Sandboxed by default.
     *
     * One object answers every rule question, so a grant cannot be made in
     * one half of the engine and missed in another.  A compiled template records
     * a digest of this policy and is recompiled whenever the digest changes.
     */
    protected ?Policy $policy = null;

    protected function initializeClarityEngine(): void
    {
        //$this->policy   = Policy::restricted();
        $this->registry = new Registry(
            fn(string $view, array $vars = []): string => $this->renderPartial($view, $vars)
        );
        $this->cache = new Cache();
    }

    /**
     * Turn debug mode on or off — the single debug switch.
     *
     * ```php
     * $engine->setDebugMode(true);                       // full debug, defaults
     * $engine->setDebugMode(new DumpOptions(maxDepth: 3));
     * $engine->setDebugMode(false);                      // production
     * ```
     *
     * Turning it ON installs the whole debug experience in one step, and
     * turning it OFF removes all of it:
     *
     * - compiler-level runtime assertions (range-loop safety checks);
     * - `dump()` rendered by the context-aware renderers — an HTML tree in HTML,
     *   a `;/* DEBUG_DUMP *\/` comment in JS, and a `/* DEBUG_DUMP *\/` comment
     *   in CSS — with sensitive keys masked;
     * - `{{ x |> dump }}`, which dumps the piped value at the pipe position and
     *   still yields it (`{{ x |> dump |> length }}` measures x);
     * - a {@see DebugEventBus} emitting `template.resolve`, `template.compile`
     *   and `template.render`;
     * - the HTML debug panel, when `DumpOptions::showPanel()` is set.
     *
     * Passing {@see DumpOptions} is shorthand for "on, with these options" —
     * `$debug instanceof DumpOptions` and `$debug === null` both mean "on".
     * `$debug === false` is exactly {@see disableDebug()}.
     *
     * `dd()` is the one exception: it is never pruned, so the registry refuses
     * it while debug is off instead of dumping raw, unmasked values.
     *
     * @param bool|DumpOptions|null $debug True/options to enable, false to disable.
     * @return $this
     */
    public function setDebugMode(bool|DumpOptions|null $debug = true): static
    {
        if ($debug) {
            $opts = $debug instanceof DumpOptions ? $debug : new DumpOptions();

            $this->debugMode = true;

            // Every debug facility is installed here and nowhere else, so "debug
            // is on" and "dump() is formatted" can never disagree.  The registry owns
            // no debug behaviour of its own; it is handed the runtime's handlers.
            $this->debugRuntime = new DebugRuntime($opts);
            $this->debugRuntime->register($this->registry);

            // The pass-through behind `{{ x |> dump }}`.  A SERVICE, not a callable:
            // the pipe form must EMIT the dump and still yield the value, which the
            // callable form (a plain return) cannot express.  Only the compile-time
            // probe wiring ever names this key, and the compiler prunes it in
            // production, so a production template never resolves it.
            $this->registry->addService(
                '__debug_probe',
                fn(string $ctx, mixed $value, mixed ...$args): mixed =>
                    $this->debugRuntime->probe($ctx, $value, ...$args)
            );

            $this->debugBus   = $this->debugRuntime->bus;
            $this->debugPanel = $this->debugRuntime->panel;

            return $this;
        } else {
            $this->debugMode    = false;
            $this->debugRuntime = null;
            $this->debugBus     = null;
            $this->debugPanel   = null;

            // Hand the registry back to its debug-neutral state: dump() becomes a
            // no-op and dd() refuses, rather than either keeping a stale runtime
            // (whose DumpOptions no longer reflect anything) or falling back to a
            // second formatter.  Doing this in the one teardown path is what makes
            // "off" mean off, whatever order the two methods were called in.
            $this->registry->setDumpHandler(null);
            $this->registry->setDdHandler(null);

            return $this;
        }
    }

    /**
     * Return whether debug mode is currently enabled.
     */
    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }

    /**
     * Set what compiled templates are allowed to reach.
     *
     * A policy is a set of rules plus two allowlists; see
     * {@see \Clarity\Engine\Policy}.  Start from a preset and change what you
     * mean to change:
     *
     * ```php
     * $engine->setPolicy(Policy::unrestricted());
     * $engine->setPolicy(Policy::default()
     *     ->allowRule('methodCalls')
     *     ->allowFunctions('strtoupper', 'count'));
     * ```
     *
     * SECURITY: a policy that grants `rawPhp`, `phpVariables` or
     * `methodCalls` is equivalent to executing arbitrary PHP and is intended for
     * templates written by trusted authors only.  Templates compiled under one
     * policy are automatically recompiled under another.
     *
     * @param Policy|array $policy A policy.
     * @return $this
     */
    public function setPolicy(Policy|array $policy): static
    {
        if (\is_array($policy)) {
            $policy = Policy::fromArray($policy);
        }
        $this->policy = $policy;
        return $this;
    }

    /**
     * The policy templates are currently compiled under.
     *
     * Always a real object: a freshly built engine answers with
     * {@see Policy::restricted()}.  Use it for coarse questions rather than
     * keeping a second flag that could disagree with it — `getPolicy()->isSandboxed()`
     * answers what the old `isSandboxed()` answered.
     */
    public function getPolicy(): Policy
    {
        return $this->policy ??= Policy::restricted();
    }

    /**
     * Whether the current policy lets templates reach PHP at all.
     *
     * Kept because it reads better than `getPolicy()->allowsPhp()` at a call site
     * that only wants the coarse answer.
     */
    public function isSandboxed(): bool
    {
        return $this->getPolicy()->isSandboxed();
    }

    /**
     * Enable full debug mode.
     *
     * @deprecated Use {@see setDebugMode()} — the two debug entry points have
     *             been unified, and `setDebugMode(true)` (or passing
     *             {@see DumpOptions}) now installs exactly what this method did.
     *             Kept as an alias so existing code keeps working.
     *
     * ```php
     * $engine->enableDebug();   // default options
     * $engine->enableDebug(new DumpOptions(showPanel: true, maxDepth: 4));
     * ```
     *
     * @param DumpOptions|null $opts Customise depth, masking, panel, etc.
     * @return $this
     */
    public function enableDebug(?DumpOptions $opts = null): static
    {
        return $this->setDebugMode($opts ?? true);
    }

    /**
     * Disable debug mode and tear down everything it installed: the event bus,
     * the panel, and the registry's dump/dd handlers.
     *
     * @deprecated Use {@see setDebugMode(false)} instead.
     *
     * @return $this
     */
    public function disableDebug(): static
    {
        return $this->setDebugMode(false);
    }

    /**
     * Return the active DebugEventBus, or null when debug mode is off.
     */
    public function getDebugBus(): ?DebugEventBus
    {
        return $this->debugBus;
    }

    /**
     * Return the active HtmlDebugPanel, or null when disabled.
     */
    public function getDebugPanel(): ?HtmlDebugPanel
    {
        return $this->debugPanel;
    }

    /**
     * Set the base path for resolving relative template names.
     *
     * @param string $path Base directory for templates.
     * @return $this
     */
    public function setViewPath(string $path): static
    {
        $this->viewPath = rtrim($path, '/\\');

        if ($this->loader instanceof FileLoader) {
            $this->loader->setBasePath($this->viewPath);
            return $this;
        }

        if ($this->loader instanceof DomainRouterLoader) {
            // Update the fallback FileLoader if it exists
            $fallback = $this->loader->getFallbackLoader();
            if ($fallback instanceof FileLoader) {
                $fallback->setBasePath($this->viewPath);
            }
            return $this;
        }

        return $this;
    }

    /**
     * Get the currently configured base path for view resolution.
     *
     * @return string Base directory for views.
     */
    public function getViewPath(): string
    {
        return $this->viewPath;
    }

    /**
     * Set the view file extension for this instance.
     *
     * @param string $ext Extension with or without a leading dot.
     * @return $this
     */
    public function setExtension(string $ext): static
    {
        if ($ext !== '' && $ext[0] !== '.') {
            $ext = '.' . $ext;
        }
        $this->extension = $ext;
        if ($this->loader !== null) {
            self::applyExtensionToLoader($this->loader, $ext);
        }
        return $this;
    }

    /**
     * Get the effective file extension used when resolving templates.
     *
     * @return string Extension including leading dot or empty string.
     */
    public function getExtension(): string
    {
        return $this->extension;
    }

    /**
     * Add a namespace for view resolution.
     *
     * Views can be referenced using the syntax "namespace::view.name".
     *
     * @param string $name Namespace name to register.
     * @param string $path Filesystem path corresponding to the namespace.
     * @return $this
     */
    public function addNamespace(string $name, string $path): static
    {
        $path = rtrim($path, '/');
        $this->namespaces[$name] = $path;

        // Ensure we have a DomainRouterLoader
        if (!$this->loader instanceof DomainRouterLoader) {
            $fallback = $this->loader ?? new FileLoader($this->viewPath, $this->extension);
            $this->loader = new DomainRouterLoader([], fallback: $fallback);
        }

        if ($this->loader instanceof DomainRouterLoader) {
            // Add domain → FileLoader
            $this->loader->addDomainLoader(
                $name,
                new FileLoader($path, $this->extension)
            );
        }

        return $this;
    }

    /**
     * Get the currently registered view namespaces.
     *
     * @return array Associative array of namespace => path mappings.
     */
    public function getNamespaces(): array
    {
        return $this->namespaces;
    }

    /**
     * Register a module, granting it access to this engine instance so it can
     * self-register filters, functions, services, and directives.
     *
     * Modules are the recommended way to bundle related features (e.g. a full
     * localization set with filters, a locale stack, and `with_locale` directives).
     *
     * ```php
     * $engine->addModule(new \Clarity\LocalizationModule([
     *     'locale'            => 'de_DE',
     *     'translations_path' => __DIR__ . '/locales',
     * ]));
     * ```
     *
     * @param ModuleInterface $module Module to register.
     * @return $this
     */
    public function addModule(ModuleInterface $module): static
    {
        $module->register($this);
        return $this;
    }

    /**
     * Register an inline filter definition that is compiled directly into the
     * generated PHP render body (zero runtime call overhead).
     *
     * The definition must follow the same format as the built-in inline filters:
     * ```php
     * $engine->addInlineFilter('my_upper', [
     *     'php' => '\mb_strtoupper((string) {1})',
     * ]);
     * $engine->addInlineFilter('my_substr', [
     *     'php' => '\mb_substr((string) {1}, {2}, {3})',
     *     'params' => ['start', 'length'],
     *     'defaults' => ['length' => null],
     * ]);
     * ```
     * Template placeholders: `{1}` for the piped value, `{2}`, `{3}`, … for
     * additional parameters are declared in `params`.
     *
     * @param string $name       Filter name.
     * @param array{php?: string, params?: string[], defaults?: array<string, string>, variadic?: bool} $definition
     * @return $this
     */
    public function addInlineFilter(string $name, array $definition): static
    {
        $this->registry->addInlineFilter($name, $definition);
        return $this;
    }

    /**
     * Register an inline FUNCTION — codegen that compiles into the template but
     * is NOT reachable with the pipe operator.
     *
     * `addInlineFunction()` is to `addInlineFilter()` what `addFunction()` is to
     * `addFilter()`: the call form only. The `php` template backs `name(...)`
     * exactly as it would for a filter, while `value |> name` is a compile-time
     * error.
     *
     * Use it for a construct whose argument is a piece of SOURCE rather than a
     * value to transform, so that a piped form has no meaning:
     *
     * ```php
     * $engine->addInlineFunction('isset', [
     *     'php'    => 'isset({1})',
     *     'callGuard' => 'presence',
     * ]);
     * ```
     *
     * The `callGuard` is what keeps the template honest about the construct's own
     * restrictions: `presence` requires the first argument to be a bare name or a
     * chain over one, because PHP's `isset()` accepts nothing else.
     *
     * @param string $name       Function name used in templates.
     * @param array{php: string, params?: string[], defaults?: array<string, string>, variadic?: bool, valueParam?: string, callGuard?: string} $definition
     * @return $this
     */
    public function addInlineFunction(string $name, array $definition): static
    {
        $this->registry->addInlineFunction($name, $definition);
        return $this;
    }

    /**
     * Register a handler for a custom directive (e.g. `with_locale`).
     *
     * The handler is a callable that receives the raw text after the keyword, a
     * {@see TemplateLocation} for error messages, and a `$processExpr` callable
     * that converts a Clarity expression string to a PHP expression string.
     * It must return a PHP statement string.
     *
     * ```php
     * $engine->addDirective('with_locale', function(string $rest, TemplateLocation $at, callable $expr): string {
     *     return "\$__c_sv['locale']->push({$expr(trim($rest))});"
     * });
     * $engine->addDirective('endwith_locale', fn(...) => "\$__c_sv['locale']->pop();");
     * ```
     *
     * Paired directives
     * -----------------
     * A directive that wraps a body declares its parts with a {@see Directive}
     * whose factory name states the role:
     * ```php
     * $engine->addDirective('cache',      $openHandler,   Directive::opens('endcache', 'cache_else'));
     * $engine->addDirective('cache_else', $branchHandler, Directive::branches('cache'));
     * $engine->addDirective('endcache',   $closeHandler,  Directive::closes('cache'));
     * ```
     * The compiler then rejects an unclosed `{% cache %}`, a stray `{% endcache %}`,
     * a close that crosses another construct, and a branch tag used outside its
     * construct — all at compile time, naming the template and line.
     *
     * A leaf that may only appear within a construct says so with `inside()`, which
     * asserts no structure — the tag stays an ordinary directive:
     * ```php
     * $engine->addDirective('cache_control', $leafHandler, Directive::inside('cache'));
     * ```
     *
     * @param string         $keyword The directive keyword in lowercase (e.g. 'with_locale').
     * @param callable       $handler See {@see Registry} for the expected signature.
     * @param Directive|null $directive Omit for an ordinary directive; see {@see Directive}.
     * @return $this
     */
    public function addDirective(string $keyword, callable $handler, ?Directive $directive = null): static
    {
        $this->registry->addDirective($keyword, $handler, $directive);
        return $this;
    }

    /**
     * Store a service object in the registry so that compiled template render
     * bodies can access it via `$__c_sv['key']` or `$this->services['key']`.
     *
     * This is primarily used by modules that need shared mutable state (e.g. a
     * locale stack) accessible both from closures that close over the object
     * *and* from inline filter PHP templates using `$this->services['key']->method()`.
     *
     * @param string $name    Key under which the service is accessible.
     * @param mixed  $service Service value, can be of any type.
     * @return $this
     */
    public function addService(string $name, mixed $service): static
    {
        $this->registry->addService($name, $service);
        return $this;
    }

    /**
     * Return true if a service with the given key has been registered.
     */
    public function hasService(string $name): bool
    {
        return $this->registry->hasService($name);
    }

    /**
     * Retrieve a previously registered service.
     *
     * @throws \RuntimeException if no service with that name exists.
     */
    public function getService(string $name): mixed
    {
        return $this->registry->getService($name);
    }

    /**
     * Register a custom filter callable.
     *
     * Filters transform a piped value and are invoked in templates using pipe syntax:
     * - Simple filter: `{{ value |> filterName }}`
     * - Filter with arguments: `{{ value |> filterName(arg1, arg2) }}`
     * - Chained filters: `{{ value |> filter1 |> filter2 |> filter3 }}`
     *
     * Filters receive the piped value as the first parameter, followed by any arguments
     * specified in the template.
     *
     * **Example: Currency filter**
     * ```php
     * $engine->addFilter('currency', function($amount, string $symbol = '€') {
     *     return $symbol . ' ' . number_format($amount, 2);
     * });
     * ```
     *
     * Template usage:
     * ```twig
     * {{ price |> currency }}       {# Output: € 99.99 #}
     * {{ price |> currency('$') }}  {# Output: $ 99.99 #}
     * ```
     *
     * **Example: Excerpt filter**
     * ```php
     * $engine->addFilter('excerpt', function($text, int $length = 100) {
     *     return mb_strlen($text) > $length
     *         ? mb_substr($text, 0, $length) . '…'
     *         : $text;
     * });
     * ```
     *
     * Template usage:
     * ```twig
     * {{ article.body |> excerpt(150) }}
     * ```
     *
     * **Built-in filters:**
     * - Text: `upper`, `lower`, `trim`, `truncate`, `escape`, `raw`
     * - Numbers: `number`, `abs`, `round`, `ceil`, `floor`
     * - Arrays: `join`, `length`, `first`, `last`, `keys`, `values`, `map`, `filter`, `reduce`
     * - Dates: `date`, `date_modify`, `format_datetime`
     * - Other: `json`, `default`, `unicode`
     *
     * @param string   $name Filter name used in templates (e.g. 'currency').
     * @param callable $fn   Callable with signature: fn($value, ...$args): mixed
     * @return static Fluent interface
     */
    public function addFilter(string $name, callable $fn): static
    {
        $this->registry->addFilter($name, $fn);
        return $this;
    }

    /**
     * Register a custom function callable.
     *
     * Functions are called directly in templates, e.g. `{{ name(arg) }}`.
     * This is distinct from filters, which transform a piped value.
     *
     * @param string   $name Function name used in templates (e.g. 'formatDate').
     * @param callable $fn   fn(...$args): mixed
     * @return static
     */
    public function addFunction(string $name, callable $fn): static
    {
        $this->registry->addFunction($name, $fn);
        return $this;
    }

    /**
     * Set a custom template loader, replacing the default FileLoader.
     *
     * @param TemplateLoader $loader The loader to use.
     * @return static
     */
    public function setLoader(TemplateLoader $loader): static
    {
        $this->loader = $loader;
        if ($this->extension !== null) {
            self::applyExtensionToLoader($loader, $this->extension);
        }
        return $this;
    }

    /**
     * Return the active template loader, lazily creating a FileLoader if none
     * has been set explicitly.
     */
    public function getLoader(): TemplateLoader
    {
        // If namespaces exist but no loader yet → create DomainRouterLoader
        if ($this->loader === null && !empty($this->namespaces)) {
            $routes = [];
            foreach ($this->namespaces as $ns => $path) {
                $routes[$ns] = new FileLoader($path, $this->extension);
            }

            $this->loader = new DomainRouterLoader(
                $routes,
                fallback: new FileLoader($this->viewPath, $this->extension)
            );
        }

        // If no loader and no namespaces → simple FileLoader
        return $this->loader ??= new FileLoader(
            $this->viewPath,
            $this->extension
        );
    }

    protected static function applyExtensionToLoader(TemplateLoader $loader, string $extension): void
    {
        if ($loader instanceof FileLoader) {
            $loader->setExtension($extension);
        } else {
            foreach ($loader->getSubLoaders() as $subLoader) {
                self::applyExtensionToLoader($subLoader, $extension);
            }
        }
    }

    /**
     * Set the directory where compiled templates should be cached.
     *
     * @param string $path Absolute path to the cache directory.
     * @return static
     */
    public function setCachePath(string $path): static
    {
        $this->cache->setPath($path);
        return $this;
    }

    /**
     * Get the currently configured cache directory.
     *
     * @return string Absolute path to the cache directory.
     */
    public function getCachePath(): string
    {
        return $this->cache->getPath();
    }

    /**
     * Flush all cached compiled templates.
     *
     * @return static
     */
    public function flushCache(): static
    {
        $this->cache->flush();
        return $this;
    }

    /**
     * Render a view template and return the result as a string.
     *
     * If a layout is configured via setLayout(), the view is first rendered and then
     * wrapped in the layout. The layout receives the rendered content in the `content`
     * variable.
     *
     * Templates are automatically compiled to cached PHP classes. The cache is
     * automatically invalidated when source files change.
     *
     * **Basic rendering:**
     * ```php
     * $html = $engine->render('welcome', [
     *     'user' => ['name' => 'John', 'email' => 'john@example.com'],
     *     'title' => 'Welcome Page'
     * ]);
     * ```
     *
     * **With layout:**
     * ```php
     * $engine->setLayout('layouts/main');
     * $html = $engine->render('pages/dashboard', [
     *     'stats' => $dashboardStats
     * ]);
     * // The layout receives 'content' variable with rendered 'pages/dashboard'
     * ```
     *
     * **Without layout (override):**
     * ```php
     * $engine->setLayout(null); // Temporarily disable layout
     * $partial = $engine->render('partials/widget', ['data' => $widgetData]);
     * ```
     *
     * **Namespaced templates:**
     * ```php
     * $engine->addNamespace('admin', __DIR__ . '/admin_templates');
     * $html = $engine->render('admin::dashboard', $data);
     * ```
     *
     * @param string $view View name to render. Can include namespace prefix (e.g. 'admin::dashboard').
     * @param array $vars Variables to pass to the template. Objects stay objects: `a.b`
     *                    reads a public property while `a:b` reads an array key.
     * @return string Rendered HTML/output.
     * @throws ClarityException If template not found or compilation fails.
     */
    public function render(string $view, array $vars = []): string
    {
        $content = $this->renderPartial($view, $vars);

        if ($this->layout !== null && $this->renderDepth === 0) {
            $content = $this->renderLayout($this->layout, $content, $vars);
        }

        if ($this->debugPanel !== null) {
            $content .= $this->debugPanel->getHtml();
        }

        return $content;
    }

    /**
     * Render a partial view (without applying a layout) and return the output.
     *
     * @param string $view View name to resolve and render.
     * @param array $vars Variables for this render call.
     * @return string Rendered HTML/output.
     */
    public function renderPartial(string $view, array $vars = []): string
    {
        $this->renderDepth++;
        try {
            // The merged scope is passed through UNCHANGED. Objects stay objects;
            // the compiler emits real property reads for them and container
            // operations ({@see Access}) normalise lazily where they are needed.
            $merged = [...$this->vars, ...$vars];
            $output = $this->renderFile($view, $merged);
        } finally {
            $this->renderDepth--;
        }

        return $output;
    }

    /**
     * Render a layout template wrapping provided content.
     *
     * The layout receives the rendered view in the `content` variable.
     *
     * @param string $layout Layout view name.
     * @param string $content Previously rendered content.
     * @param array $vars Additional variables to pass to the layout.
     * @return string Rendered layout output.
     */
    public function renderLayout(string $layout, string $content, array $vars = []): string
    {
        $vars['content'] = $content;
        return $this->renderPartial($layout, $vars);
    }

    // -------------------------------------------------------------------------
    // Internal rendering
    // -------------------------------------------------------------------------

    /**
     * Build the runtime callable table handed to compiled templates as `$__c_fn`.
     *
     * This is the registry's table verbatim. `dump`/`dd` live in it already, so
     * there is one place a template name can resolve to — `{{ dump(x) }}` and a
     * quoted filter reference such as `map(items, "dump")` reach the SAME
     * callable.
     *
     * That is deliberate. This method used to rebuild `dump` from the engine's
     * private `__debug_dump` service on every render, which meant the call form
     * and the reference form did not agree: with debug off, `map(items, "dump")`
     * still reached the registry's raw formatter and printed unmasked values
     * into production output, while `{{ dump(x) }}` was pruned. One table, one
     * behaviour.
     *
     * @return array<string, callable>
     */
    private function runtimeCallables(): array
    {
        return $this->registry->allCallables();
    }

    /**
     * Compile (if needed) and render a single template.
     *
     * @param string $templateName Logical template name (e.g. 'home', 'layouts/base').
     * @param array  $vars         Already-cast variables array.
     * @return string Rendered output.
     * @throws ClarityException On compile or runtime errors.
     */
    private function renderFile(string $templateName, array $vars): string
    {
        if (isset($this->renderStack[$templateName])) {
            $chain = [...array_keys($this->renderStack), $templateName];
            throw new ClarityException(
                'Recursive template rendering detected: ' . \implode(' -> ', $chain),
                $templateName
            );
        }

        $this->renderStack[$templateName] = true;

        // Ensure compiled class is loaded
        try {
            $className = $this->loadCachedClass($templateName);

            // Instantiate with the callable and service registries
            $template = new $className(
                $this->runtimeCallables(),
                $this->registry->allServices()
            );

            // Install error handler to map PHP errors → template lines.
            //
            // set_error_handler() hands back the previously installed handler so
            // this one can *chain* to it.  That matters: returning false from an
            // error handler makes PHP's BUILT-IN handler report the diagnostic —
            // it does NOT invoke the previously registered custom handler.  Without
            // chaining, a template diagnostic would silently bypass the
            // application's logging / error reporting and go to stderr instead.
            //
            // $previousHandler is passed BY REFERENCE into buildErrorHandler():
            // its value only exists once set_error_handler() returns, so the
            // closure must observe the variable rather than a snapshot of it.
            // The mask is E_ALL rather than the habitual
            // `E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED`: that mask would keep
            // this handler from ever being *entered* for a deprecation, which
            // makes both of its branches unreachable.  A deprecation raised by a
            // template would then bypass the application's handler entirely and
            // be reported by PHP's built-in handler against the compiled cache
            // file — an internal path — which leaks into the response when
            // display_errors is on.  Deprecations raised by a template are
            // ordinary template diagnostics (a filter handed `null` under weak
            // mode is the common one), so they get annotated like any other.
            $previousHandler = null;
            $previousHandler = set_error_handler(
                $this->buildErrorHandler($templateName, $previousHandler),
                E_ALL
            );

            $renderStart = $this->debugMode ? \microtime(true) : 0.0;
            try {
                $output = $template->render($vars);
            } catch (\Throwable $e) {
                // Exceptions raised while the template runs (e.g. from PHP
                // generated for inline filters, from a registered filter, or a
                // TypeError on a filter argument) are mapped here rather than by
                // a global exception handler: a global handler only fires for
                // exceptions that are *truly* uncaught, which never happens when
                // the caller wraps render() in try/catch.
                throw $this->mapRenderThrowable($e, $className, $templateName);
            } finally {
                restore_error_handler();
                if ($this->debugMode) {
                    $this->debugBus?->emit('template.render', [
                        'template'    => $templateName,
                        'duration_ms' => \round((\microtime(true) - $renderStart) * 1000, 3),
                    ]);
                }
            }
        } finally {
            unset($this->renderStack[$templateName]);
        }

        return $output;
    }

    /**
     * Return an already-loaded class name, compiling & caching as needed.
     *
     * @return class-string
     */
    private function loadCachedClass(string $templateName): string
    {
        $loader = $this->getLoader();

        if ($this->debugMode) {
            $this->debugBus?->emit('template.resolve', [
                'template' => $templateName,
                'loader'   => \get_class($loader),
            ]);
        }

        if ($this->cache->isFresh($templateName, static fn(string $n) => $loader->load($n)?->revision)) {
            try {
                $className = $this->cache->load($templateName);
            } catch (ParseError) {
                // A previously-written cache file contains invalid PHP (e.g. a
                // template that was broken at write time and not yet cleaned up).
                // Delete it so the next step triggers a fresh compile.
                $this->cache->invalidate($templateName);
                $className = null;
            }
            if ($className !== null) {
                // Recompile if debug mode or the policy changed since the
                // template was last compiled.  The compiled body is
                // policy-specific (scope seeding, pruning, escape context,
                // permitted calls), so a template built under one policy must
                // never be served under another.
                //
                // The policy is compared by DIGEST rather than by identity
                // because a policy is rebuilt on every boot: identity would
                // report a change on every request.  The digest is what makes it
                // safe to change an allowlist without bumping
                // COMPILER_VERSION.
                $compiledDebug  = $className::$debugCompiled ?? false;
                $compiledDigest = $className::$policyDigest ?? '';
                if ($compiledDebug !== $this->debugMode || $compiledDigest !== $this->getPolicy()->digest()) {
                    $this->cache->invalidate($templateName);
                } else {
                    if ($this->debugMode) {
                        $this->debugBus?->emit('template.cached', ['template' => $templateName]);
                    }
                    return $className;
                }
            }
        }

        // Compile and write; the cache file is required inside writeAndLoad()
        // using plain `require` so the new versioned class is always declared.
        $this->compiler ??= new Compiler();
        $this->compiler
            ->setPolicy($this->getPolicy())
            ->setRegistry($this->registry)
            ->setDebugMode($this->debugMode);
        $compileStart = $this->debugMode ? \microtime(true) : 0.0;
        $compiled     = $this->compiler->compile($templateName, $loader);
        if ($this->debugMode) {
            $this->debugBus?->emit('template.compile', [
                'template'    => $templateName,
                'duration_ms' => \round((\microtime(true) - $compileStart) * 1000, 3),
            ]);
        }
        try {
            return $this->cache->writeAndLoad($templateName, $compiled);
        } catch (ParseError $e) {
            // The compiled PHP contains a syntax error (e.g. a malformed expression
            // in the template).  Delete the broken cache file so the next request
            // does not serve an unloadable file, then map the error back to the
            // original template line using the source map we already have.
            $this->cache->invalidate($templateName);
            [$tplFile, $tplLine] = $this->mapCompiledErrorLine(
                $e->getLine(),
                $compiled->renderBodyLine,
                $compiled->sourceMap,
                $compiled->sourceFiles
            );
            throw new ClarityException(
                'Syntax error in template: ' . $e->getMessage(),
                $tplFile ?? $templateName,
                $tplLine,
                previous: $e
            );
        }
    }

    /**
     * Map a file line number from a compiled cache file back to the original
     * template file and line, using only data available at compile time
     * (no class loading, reflection, or file I/O).
     *
     * Cache::writeAndLoad() prepends "<?php\n" before the compiled code, so
     * the body does not start at line 1.  The compiler bakes the resolved body
     * offset into {@see CompiledTemplate::$renderBodyLine}, from which the
     * engine can derive the body start line of the written cache file without
     * re-parsing it.
     *
     * @param int      $fileLine      1-based line number reported by the ParseError.
     * @param int      $renderBodyLine First line of the compiled body, as baked into the class.
     * @param array    $sourceMap     Source map from the CompiledTemplate.
     * @param string[] $files         Logical template names (indexed by the integers in $sourceMap).
     * @return array{0: string|null, 1: int}  [templateName|null, templateLine]
     */
    private function mapCompiledErrorLine(int $fileLine, int $renderBodyLine, array $sourceMap, array $files): array
    {
        if ($sourceMap === [] || $renderBodyLine <= 0) {
            return [null, 0];
        }

        return $this->matchSourceMapLine($sourceMap, $files, $fileLine - $renderBodyLine + 1);
    }

    /**
     * Translate a throwable raised during template execution into a
     * {@see ClarityException} that points at the originating template.
     *
     * The throwable's own location is used when it lies inside this template's
     * compiled cache file.  Otherwise the stack trace is searched for the
     * innermost frame belonging to that file, which covers user code called
     * *from* the template (filters, functions, services).  Throwables that
     * never touch the template — e.g. an exception raised by an application
     * callback outside the render path — are returned unchanged so genuine
     * application bugs keep their original type.
     *
     * @param \Throwable  $e            Throwable raised by the render call.
     * @param class-string $className   Compiled template class that was being rendered.
     * @param string      $templateName Logical template name (for cache-path resolution).
     */
    private function mapRenderThrowable(\Throwable $e, string $className, string $templateName): \Throwable
    {
        // Already describes a template location → nothing to add. A
        // ClarityException raised by the runtime (e.g. Access::iterate() on a
        // non-iterable) carries NO location, so it still needs mapping below.
        if ($e instanceof ClarityException && ($e->templateName !== '' || $e->templateLine > 0)) {
            return $e;
        }

        $line = $this->resolveThrowableTemplateLine($e, $className, $templateName);
        if ($line <= 0) {
            return $e;
        }

        $sourceMap = SourceMap::normalise(self::staticPropertyOrDefault($className, 'sourceMap', ''));
        $files     = self::staticPropertyOrDefault($className, 'sourceFiles', []);
        $paths     = SourceMap::normalisePaths(self::staticPropertyOrDefault($className, 'sourcePaths', []));

        [$tplFile, $tplLine] = $this->matchSourceMapLine($sourceMap, $files, $line);
        if ($tplFile === null) {
            return $e;
        }

        // The physical path is carried PER SOURCE by the class itself, so an
        // inlined include or a layout gets its own file — no re-resolution
        // against a loader that may not even be the one that served it.
        return new ClarityException(
            $e->getMessage(),
            $tplFile,
            $tplLine,
            self::sourcePathOf($files, $paths, $tplFile),
            previous: $e
        );
    }

    /**
     * Physical path recorded for a logical name in a compiled class's parallel
     * `sourceFiles` / `sourcePaths` arrays, or '' when it recorded none.
     *
     * @param string[] $files
     * @param string[] $paths
     */
    private static function sourcePathOf(array $files, array $paths, string $templateName): string
    {
        $index = \array_search($templateName, $files, true);

        return $index === false ? '' : ($paths[$index] ?? '');
    }

    /**
     * Read a static property from a compiled template class, tolerating classes
     * compiled by an older compiler that lack it.
     *
     * @param class-string $className
     * @param mixed        $default
     * @return mixed
     */
    private static function staticPropertyOrDefault(string $className, string $property, mixed $default): mixed
    {
        try {
            return $className::${$property};
        } catch (\Error) {
            return $default;
        }
    }

    /**
     * Find the compiled-body-relative line at which a throwable originated.
     *
     * @param \Throwable   $e
     * @param class-string $className
     * @param string       $templateName
     * @return int Body-relative line, or 0 when the throwable did not originate
     *             in this template.
     */
    private function resolveThrowableTemplateLine(\Throwable $e, string $className, string $templateName): int
    {
        $renderBodyLine = self::staticPropertyOrDefault($className, 'renderBodyLine', 0);
        if (!\is_int($renderBodyLine) || $renderBodyLine <= 0) {
            return 0;
        }

        $cachePath  = $this->cache->cacheFilePath($templateName);
        $cacheReal  = \realpath($cachePath);
        $cacheForms = $cacheReal === false ? [$cachePath] : [$cachePath, $cacheReal];

        $file = $e->getFile();
        if (in_array($file, $cacheForms, true)) {
            return $e->getLine() - $renderBodyLine + 1;
        }

        // Fallback: user code (filters, functions) called by the template
        // throws from its own file, but the frame below it is the template.
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file'], $frame['line']) && in_array($frame['file'], $cacheForms, true)) {
                return $frame['line'] - $renderBodyLine + 1;
            }
        }

        return 0;
    }

    /**
     * @param array<int, array{0:int, 1:int, 2:int}> $sourceMap
     * @param string[]                                $files
     * @return array{0: string|null, 1: int}
     */
    private function matchSourceMapLine(array $sourceMap, array $files, int $bodyLine): array
    {
        // Ranges are appended in ascending order of their start line, so the
        // matching range is the last one whose start is <= $bodyLine.  Scanning
        // backwards finds it in O(1) for the common case (errors near the end of
        // a template) instead of walking the whole map from the beginning.
        for ($index = \count($sourceMap) - 1; $index >= 0; $index--) {
            $range = $sourceMap[$index];
            if ($range[0] <= $bodyLine) {
                return [$files[$range[1]] ?? null, $range[2]];
            }
        }

        return [null, 0];
    }

    /**
     * Build an error-handler closure that maps a PHP error in the compiled
     * cache file back to the original template name and line.
     *
     * Diagnostics that do NOT originate in the template are handed to the
     * previously installed handler via {@see self::dispatchToPreviousHandler()},
     * so the application keeps receiving them.
     *
     * @param string         $templateName    The logical entry template name.
     * @param callable|null  $previousHandler Handler that was installed before this
     *                                        one, captured from set_error_handler().
     * @return callable
     */
    private function buildErrorHandler(string $templateName, ?callable &$previousHandler = null): callable
    {
        $cacheFile = $this->cache->cacheFilePath($templateName);

        return function (int $errno, string $errstr, string $errfile, int $errline) use ($templateName, $cacheFile, &$previousHandler): bool {

            // Diagnostics raised outside the compiled template belong to the
            // application, not to the template → hand them to the handler that
            // was installed before this one, so application error handling is
            // preserved rather than bypassed.
            //
            // PHP reports the exact `require` path in $errfile, so a plain string
            // comparison against the path handed to require is sufficient.  The
            // realpath() fallback only covers exotic path shapes (symlinks, mixed
            // casing) and is deliberately attempted *after* the cheap compare.
            if ($errfile !== $cacheFile && realpath($errfile) !== realpath($cacheFile)) {
                return self::dispatchToPreviousHandler($previousHandler, $errno, $errstr, $errfile, $errline);
            }

            if (!(error_reporting() & $errno)) {
                return self::dispatchToPreviousHandler($previousHandler, $errno, $errstr, $errfile, $errline);
            }

            // Determine template position.  Resolution can fail (e.g. the class
            // was loaded by an older compiler), in which case fall back to the
            // logical template name so the message is still actionable.  The
            // physical path comes from the same class metadata, so an editor can
            // open the file an included/layout error actually came from.
            [$tplFile, $tplLine, $tplPath] = $this->resolveTemplateLine($templateName, $errline);
            $tplFile = $tplFile ?? $templateName;

            // 1) Undefined array key "foo"
            //
            //    Deliberately NOT reworded to mention a filter or a variable by
            //    name: the same PHP diagnostic covers a missing render-scope
            //    entry (`$__c_va['foo']`) AND a missing key on a present array
            //    (`$__c_va['user']['foo']`), and the message cannot tell which
            //    array it was. A missing FILTER no longer reaches this branch at
            //    all — it is rejected at compile time (see FilterCompilerTrait).
            if (preg_match('/Undefined array key "([^"]+)"/', $errstr, $m)) {
                $varName = $m[1];
                throw new ClarityException(
                    "Variable \"$varName\" is not defined in this context",
                    $tplFile,
                    $tplLine,
                    $tplPath
                );
            }

            // 2) Trying to access array offset on value of type null
            if (str_starts_with($errstr, 'Trying to access array offset')) {
                throw new ClarityException(
                    "Trying to access array offset on null – probably a missing variable or null value",
                    $tplFile,
                    $tplLine,
                    $tplPath
                );
            }

            // 3) Undefined variable $foo  (PHP 8 wording; PHP 7 used "Undefined variable: foo")
            if (preg_match('/Undefined variable:?\s+\$?(\w+)/', $errstr, $m)) {
                $varName = $m[1];
                throw new ClarityException(
                    "Variable \"$varName\" is not defined in this context",
                    $tplFile,
                    $tplLine,
                    $tplPath
                );
            }

            // 4) Undefined property: Foo::$bar  — strict object access. With the
            //    scope no longer converted to arrays, `a.b` compiles to a real
            //    property read, so this is the object-side counterpart of (1).
            if (preg_match('/Undefined property: .+?::\$?(\w+)/', $errstr, $m)) {
                $propName = $m[1];
                throw new ClarityException(
                    "Property \"$propName\" is not defined on this object",
                    $tplFile,
                    $tplLine,
                    $tplPath
                );
            }

            // 5) Attempt to read property "y" on null|array|string|int|... —
            //    a chain step applied to the wrong kind of value. Reports the
            //    offending property, which is what the template author needs.
            if (preg_match('/Attempt to read property "([^"]+)" on (\w+)/', $errstr, $m)) {
                $propName = $m[1];
                $onType   = $m[2];
                throw new ClarityException(
                    "Cannot read property \"$propName\" on $onType — check the chain before it",
                    $tplFile,
                    $tplLine,
                    $tplPath
                );
            }

            // 6) Cannot use object of type Foo as array — an array-style access
            //    (a.k / a[k]) applied to an object. The fix is property syntax.
            if (preg_match('/Cannot use object of type (\S+) as array/', $errstr, $m)) {
                throw new ClarityException(
                    "Cannot use object of type {$m[1]} as an array — use property access (a.b) instead of a key access (a[b])",
                    $tplFile,
                    $tplLine,
                    $tplPath
                );
            }

            // 7) Cannot access offset of type X on ... — the index-side
            //    counterpart of (6), e.g. indexing a string with a non-integer.
            if (str_starts_with($errstr, 'Cannot access offset of type')) {
                throw new ClarityException(
                    "Invalid key type for index access — $errstr",
                    $tplFile,
                    $tplLine,
                    $tplPath
                );
            }

            // Any other diagnostic (e.g. `foreach() argument must be of type
            // array|object`) is annotated with the template location and handed
            // to the previous handler.  Rendering continues — the application's
            // handler decides how severe that is (log it, promote it to an
            // exception, ignore it), so Clarity does not impose a policy.
            //
            // The level is converted to its E_USER_* counterpart because
            // trigger_error() — and therefore a handler receiving a user-level
            // diagnostic — accepts *only* user-level constants.  The conversion
            // keeps the *kind* of diagnostic intact (a notice stays a notice, a
            // deprecation stays a deprecation) rather than collapsing everything
            // to a warning: a handler that promotes warnings to exceptions must
            // not abort a render over a notice.  A native E_WARNING (which is
            // how PHP 8 reports an undefined variable or array key) is the
            // default, and so is a user-level warning.
            $userLevel = match ($errno) {
                E_NOTICE, E_USER_NOTICE         => E_USER_NOTICE,
                E_DEPRECATED, E_USER_DEPRECATED => E_USER_DEPRECATED,
                default                         => E_USER_WARNING,
            };

            $annotated = "$errstr in $tplFile:$tplLine";

            // Prefer handing the annotated message to the previous handler
            // directly: re-emitting it with trigger_error() would be dropped,
            // because PHP does not invoke a handler for a user-level error raised
            // *while an error handler is executing*.
            //
            // A diagnostic that originated in the template is reported at the
            // TEMPLATE's file and line, not the compiled cache file's: the
            // caller asked about a template, and the cache path is an
            // implementation detail that must not leak into logs or the
            // response.  The physical path is preferred over the logical name
            // when the loader supplied one, matching ClarityException.
            if ($previousHandler !== null) {
                return self::dispatchToPreviousHandler(
                    $previousHandler,
                    $userLevel,
                    $annotated,
                    $tplPath !== '' ? $tplPath : $tplFile,
                    $tplLine
                );
            }

            trigger_error($annotated, $userLevel);
            return true;
        };
    }

    /**
     * Hand a diagnostic to the handler that was installed before Clarity's.
     *
     * A `null` previous handler means PHP's built-in handler is the next one in
     * line, which is exactly what returning false requests — so the caller's
     * result is returned unchanged in that case.
     *
     * @param callable|null $previousHandler
     */
    private static function dispatchToPreviousHandler(
        ?callable $previousHandler,
        int $errno,
        string $errstr,
        string $errfile,
        int $errline
    ): bool {
        if ($previousHandler === null) {
            return false; // → PHP's built-in error handler reports it
        }

        return (bool) $previousHandler($errno, $errstr, $errfile, $errline);
    }

    /**
     * Map a PHP line number in the compiled cache file back to the original
     * template name and line number using the $sourceMap static property on
     * the compiled class — no file I/O required.
     *
     * The source map is a list of ranges: [phpLineStart, fileIndex, templateLine].
     * The matching range is the last entry whose phpLineStart ≤ the body-relative
     * line.  Template names are resolved from the parallel $sourceFiles static
     * property, and the body offset comes from $renderBodyLine — both baked into
     * the class at compile time, so this needs neither reflection nor disk access.
     *
     * @param string $templateName Logical name of the entry template.
     * @param int    $phpLine      Line number of the error in the compiled file.
     * @return array{0: string|null, 1: int, 2: string}  [templateName|null, templateLine, templatePath]
     */
    private function resolveTemplateLine(string $templateName, int $phpLine): array
    {
        $className = $this->cache->getLoadedClassName($templateName);
        if ($className === null) {
            return [null, 0, ''];
        }

        try {
            $packed = $className::$sourceMap;
            $files  = $className::$sourceFiles;
            $body   = $className::$renderBodyLine;
            // Absent on a class compiled before sourcePaths existed.
            $paths = $className::$sourcePaths ?? [];
        } catch (\Error) {
            return [null, 0, ''];
        }

        if (!\is_int($body) || $body <= 0) {
            return [null, 0, ''];
        }

        $map = SourceMap::normalise($packed);
        if ($map === []) {
            return [null, 0, ''];
        }

        $bodyLine = $phpLine - $body + 1;
        if ($bodyLine <= 0) {
            return [null, 0, ''];
        }

        [$file, $line] = $this->matchSourceMapLine($map, $files, $bodyLine);

        return [$file, $line, $file === null ? '' : self::sourcePathOf($files, $paths, $file)];
    }
}
