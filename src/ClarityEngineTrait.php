<?php
namespace Clarity;

use Clarity\Debug\CliDumpRenderer;
use Clarity\Debug\DebugEventBus;
use Clarity\Debug\DumpOptions;
use Clarity\Debug\HtmlDebugPanel;
use Clarity\Debug\HtmlDumpRenderer;
use Clarity\Debug\JsDumpRenderer;
use Clarity\Engine\Cache;
use Clarity\Engine\Compiler;
use Clarity\Engine\Registry;
use Clarity\Template\DomainRouterLoader;
use Clarity\Template\FileLoader;
use Clarity\Template\TemplateLoader;
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
    protected ?DebugEventBus $debugBus = null;
    protected ?HtmlDebugPanel $debugPanel = null;

    protected function initializeClarityEngine(): void
    {
        $this->registry = new Registry(
            fn(string $view, array $vars = []): string => $this->renderPartial($view, $vars)
        );
        $this->cache = new Cache();
    }

    /**
     * Enable or disable debug mode (low-level toggle).
     *
     * Prefer enableDebug() for the full debug experience (context-aware dump(),
     * dd(), DebugEventBus, optional HTML panel).  setDebugMode(true) only
     * activates compiler-level assertions (range-loop safety checks) and makes
     * dump() resolve at runtime instead of being pruned to ''.
     *
     * @param bool $debug True to enable, false to disable.
     * @return $this
     */
    public function setDebugMode(bool $debug): static
    {
        $this->debugMode = $debug;
        return $this;
    }

    /**
     * Return whether debug mode is currently enabled.
     */
    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }

    /**
     * Enable full debug mode: context-aware dump()/dd(), DebugEventBus for
     * loader/compile/render tracing, and optionally an HTML debug panel.
     *
     * dump() is pruned to '' at compile time in production (zero overhead).
     * dd() is always active regardless of debug mode.
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
        $opts = $opts ?? new DumpOptions();

        $this->debugMode  = true;
        $this->debugBus   = new DebugEventBus();
        $this->debugPanel = null;

        $htmlRenderer = new HtmlDumpRenderer();
        $cliRenderer  = new CliDumpRenderer();
        $jsRenderer   = new JsDumpRenderer();

        // Install context-aware dump handler
        $this->registry->setDumpHandler(
            static function (string $ctx, mixed ...$args) use ($htmlRenderer, $jsRenderer, $opts): string {
                $value = \count($args) === 1 ? $args[0] : $args;

                if ($ctx === 'js') {
                    return $jsRenderer->render($value, $opts);
                }
                return $htmlRenderer->render($value, $opts);
            }
        );

        // Install dd handler (always active, exits after dump)
        $this->registry->setDdHandler(
            static function (string $ctx, mixed ...$args) use ($htmlRenderer, $cliRenderer, $jsRenderer, $opts): never {
                $value = \count($args) === 1 ? $args[0] : $args;
                $isCli = \PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg';

                if ($isCli) {
                    $out = $cliRenderer->renderForced($value, $opts);
                    \fwrite(\STDOUT, $out);
                    exit(1);
                }
                if ($ctx === 'js') {
                    echo $jsRenderer->render($value, $opts);
                    exit(1);
                }
                echo $htmlRenderer->render($value, $opts);
                exit(1);
            }
        );

        if ($opts->showPanel) {
            $this->debugPanel = new HtmlDebugPanel();
            $this->debugBus->subscribe($this->debugPanel);
        }

        return $this;
    }

    /**
     * Disable debug mode and tear down the event bus and debug panel.
     *
     * @return $this
     */
    public function disableDebug(): static
    {
        $this->debugMode  = false;
        $this->debugBus   = null;
        $this->debugPanel = null;
        return $this;
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
     * $engine->use(new \Clarity\LocalizationModule([
     *     'locale'            => 'de_DE',
     *     'translations_path' => __DIR__ . '/locales',
     * ]));
     * ```
     *
     * @param ModuleInterface $module Module to register.
     * @return $this
     */
    public function use(ModuleInterface $module): static
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
     * Register a handler for a custom directive (e.g. `with_locale`).
     *
     * The handler is a callable that receives the raw text after the keyword,
     * source path and line for error messages, and a `$processExpr` callable
     * that converts a Clarity expression string to a PHP expression string.
     * It must return a PHP statement string.
     *
     * ```php
     * $engine->addDirective('with_locale', function(string $rest, string $path, int $line, callable $expr): string {
     *     return "\$__sv['locale']->push({$expr(trim($rest))});"
     * });
     * $engine->addDirective('endwith_locale', fn(...) => "\$__sv['locale']->pop();");
     * ```
     *
     * @param string   $keyword The directive keyword in lowercase (e.g. 'with_locale').
     * @param callable $handler See {@see Registry} for the expected signature.
     * @return $this
     */
    public function addDirective(string $keyword, callable $handler): static
    {
        $this->registry->addDirective($keyword, $handler);
        return $this;
    }

    /**
     * Store a service object in the registry so that compiled template render
     * bodies can access it via `$__sv['key']`.
     *
     * This is primarily used by modules that need shared mutable state (e.g. a
     * locale stack) accessible both from closures that close over the object
     * *and* from inline filter PHP templates using `$__sv['key']->method()`.
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
     * @param array $vars Variables to pass to the template. Objects are automatically converted to arrays.
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
            $merged = [...$this->vars, ...$vars];
            $cast   = self::castToArray($merged);
            $output = $this->renderFile($view, $cast);
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

            // Instantiate with filter and function registries
            $template = new $className(
                $this->registry->allFilters(),
                $this->registry->allFunctions(),
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
            $previousHandler = null;
            $previousHandler = set_error_handler(
                $this->buildErrorHandler($templateName, $previousHandler),
                E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED
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
                // Recompile if debug mode changed since the template was last compiled
                $compiledDebug = $className::$debugCompiled ?? false;
                if ($compiledDebug !== $this->debugMode) {
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
            ->setExtension($this->extension ?? FileLoader::DEFAULT_EXTENSION)
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
                $e
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
        // Already describes a template location → nothing to add.
        if ($e instanceof ClarityException) {
            return $e;
        }

        $line = $this->resolveThrowableTemplateLine($e, $className, $templateName);
        if ($line <= 0) {
            return $e;
        }

        [$tplFile, $tplLine] = $this->matchSourceMapLine(
            self::staticPropertyOrDefault($className, 'sourceMap', []),
            self::staticPropertyOrDefault($className, 'sourceFiles', []),
            $line
        );
        if ($tplFile === null) {
            return $e;
        }

        return new ClarityException(
            $e->getMessage(),
            $tplFile,
            $tplLine,
            previous: $e
        );
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
            // logical template name so the message is still actionable.
            [$tplFile, $tplLine] = $this->resolveTemplateLine($templateName, $errline);
            $tplFile = $tplFile ?? $templateName;

            // 1) Undefined array key "foo"
            if (preg_match('/Undefined array key "([^"]+)"/', $errstr, $m)) {
                $varName = $m[1];
                throw new ClarityException(
                    "Variable \"$varName\" is not defined in this context",
                    $tplFile,
                    $tplLine
                );
            }

            // 2) Trying to access array offset on value of type null
            if (str_starts_with($errstr, 'Trying to access array offset')) {
                throw new ClarityException(
                    "Trying to access array offset on null – probably a missing variable or null value",
                    $tplFile,
                    $tplLine
                );
            }

            // 3) Undefined variable $foo  (PHP 8 wording; PHP 7 used "Undefined variable: foo")
            if (preg_match('/Undefined variable:?\s+\$?(\w+)/', $errstr, $m)) {
                $varName = $m[1];
                throw new ClarityException(
                    "Variable \"$varName\" is not defined in this context",
                    $tplFile,
                    $tplLine
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
            // diagnostic — accepts *only* user-level constants.
            $userLevel = match ($errno) {
                E_NOTICE => E_USER_NOTICE,
                default  => E_USER_WARNING
            };

            $annotated = "$errstr in $tplFile:$tplLine";

            // Prefer handing the annotated message to the previous handler
            // directly: re-emitting it with trigger_error() would be dropped,
            // because PHP does not invoke a handler for a user-level error raised
            // *while an error handler is executing*.
            if ($previousHandler !== null) {
                return self::dispatchToPreviousHandler(
                    $previousHandler,
                    $userLevel,
                    $annotated,
                    $cacheFile,
                    $errline
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
     * @return array{0: string|null, 1: int}  [templateName|null, templateLine]
     */
    private function resolveTemplateLine(string $templateName, int $phpLine): array
    {
        $className = $this->cache->getLoadedClassName($templateName);
        if ($className === null) {
            return [null, 0];
        }

        try {
            $map   = $className::$sourceMap;
            $files = $className::$sourceFiles;
            $body  = $className::$renderBodyLine;
        } catch (\Error) {
            return [null, 0];
        }

        if (!\is_array($map) || $map === [] || !\is_int($body) || $body <= 0) {
            return [null, 0];
        }

        $bodyLine = $phpLine - $body + 1;
        if ($bodyLine <= 0) {
            return [null, 0];
        }

        return $this->matchSourceMapLine($map, $files, $bodyLine);
    }

    // -------------------------------------------------------------------------
    // Object → array casting
    // -------------------------------------------------------------------------

    /**
     * Recursively cast values so templates never receive live objects and
     * cannot call methods.
     *
     * The general rule is **object → array of its public properties** (step 5).
     * Steps 1-4 are recognised exceptions that produce the value the object
     * itself defines as its template-facing representation; step 5b keeps a
     * value object from becoming an empty array.
     *
     * Precedence:
     * 1. DateTimeInterface → ISO-8601 (ATOM) string, keeping the offset. Done
     *    first so the value survives `|> date(...)`, which accepts int|string.
     * 2. Public toArray() → toArray() then recurse. Ranked above
     *    JsonSerializable because `toArray(): array` declares an array return
     *    type, whereas `jsonSerialize(): mixed` may return a scalar.
     * 3. JsonSerializable → jsonSerialize() then recurse. Used with
     *    get_object_vars() (never `(array)`, which would expose private and
     *    protected properties under mangled keys).
     * 4. Traversable (Iterator / IteratorAggregate) → iterate then recurse.
     * 5. Other objects → get_object_vars() then recurse. This is the general
     *    object → array conversion.
     * 5b. …if an object exposes no public properties but is Stringable, use
     *    its __toString(). Checked only after 5 so the general rule stays
     *    dominant and public state is never silently dropped.
     * 6. Arrays → recurse element by element.
     * 7. Scalars / null → pass through.
     *
     * @param mixed $value Value to cast.
     * @return mixed Arrays, scalars or null — never a live object.
     */
    public static function castToArray(mixed $value): mixed
    {
        if (\is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = self::castToArray($v);
            }
            return $result;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (!\is_object($value)) {
            return $value;
        }

        // is_callable() (not method_exists()) so a private/protected toArray()
        // is ignored rather than throwing "Call to private method".
        if (\is_callable([$value, 'toArray'])) {
            return self::castToArray($value->toArray());
        }

        if ($value instanceof \JsonSerializable) {
            $data = $value->jsonSerialize();
            // get_object_vars() guards against a self-returning
            // jsonSerialize() recursing forever, without leaking non-public
            // state the way `(array)` would. Non-object results pass through
            // unwrapped, so a string stays a string.
            return self::castToArray(\is_object($data) ? get_object_vars($data) : $data);
        }

        if ($value instanceof \Traversable) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = self::castToArray($v);
            }
            return $result;
        }

        $vars = get_object_vars($value);
        if ($vars === [] && $value instanceof \Stringable) {
            return (string) $value;
        }

        return self::castToArray($vars);
    }

}