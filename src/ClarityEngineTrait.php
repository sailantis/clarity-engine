<?php
namespace Clarity;

use Clarity\Engine\Cache;
use Clarity\Engine\Compiler;
use Clarity\Engine\Registry;
use ParseError;

trait ClarityEngineTrait
{
    protected Registry $registry;
    protected Cache $cache;
    protected ?Compiler $compiler = null;
    /** @var string[] */
    protected array $renderStack = [];

    protected function initializeClarityEngine(): void
    {
        $this->registry = new Registry(
            fn(string $view, array $vars = []): string => $this->renderPartial($view, $vars)
        );
        $this->cache = new Cache();
    }

    /**
     * Register a module, granting it access to this engine instance so it can
     * self-register filters, functions, services, and block directives.
     *
     * Modules are the recommended way to bundle related features (e.g. a full
     * localization set with filters, a locale stack, and `with_locale` blocks).
     *
     * ```php
     * $engine->use(new \Clarity\LocalizationModule([
     *     'locale'            => 'de_DE',
     *     'translations_path' => __DIR__ . '/locales',
     * ]));
     * ```
     *
     * @param Module $module Module to register.
     * @return $this
     */
    public function use(Module $module): static
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
     * Register a handler for a custom block directive (e.g. `with_locale`).
     *
     * The handler is a callable that receives the raw text after the keyword,
     * source path and line for error messages, and a `$processExpr` callable
     * that converts a Clarity expression string to a PHP expression string.
     * It must return a PHP statement string.
     *
     * ```php
     * $engine->addBlock('with_locale', function(string $rest, string $path, int $line, callable $expr): string {
     *     return "\$__sv['locale']->push({$expr(trim($rest))});"
     * });
     * $engine->addBlock('endwith_locale', fn(...) => "\$__sv['locale']->pop();");
     * ```
     *
     * @param string   $keyword The directive keyword in lowercase (e.g. 'with_locale').
     * @param callable $handler See {@see Registry} for the expected signature.
     * @return $this
     */
    public function addBlock(string $keyword, callable $handler): static
    {
        $this->registry->addBlock($keyword, $handler);
        return $this;
    }

    /**
     * Store a non-callable service object in the registry so that
     * compiled template render bodies can access it via `$__sv['key']`.
     *
     * This is primarily used by modules that need shared mutable state (e.g. a
     * locale stack) accessible both from closures that close over the object
     * *and* from inline filter PHP templates using `$__sv['key']->method()`.
     *
     * @param string $name    Key under which the service is accessible.
     * @param mixed  $service Service value (not required to be callable).
     * @return $this
     */
    public function addService(string $name, mixed $service): static
    {
        $this->registry->addService($name, $service);
        return $this;
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
        $sourcePath = $this->resolveView($view);

        if (!is_file($sourcePath)) {
            throw new ClarityException("Template not found: {$sourcePath}", $sourcePath);
        }

        $this->renderDepth++;
        try {
            $merged = [...$this->vars, ...$vars];
            $cast = self::castToArray($merged);
            $output = $this->renderFile($sourcePath, $cast);
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
     * Compile (if needed) and render a single template file.
     *
     * @param string $sourcePath Absolute path to the .clarity.html file.
     * @param array  $vars  Already-cast variables array.
     * @return string Rendered output.
     * @throws ClarityException On compile or runtime errors.
     */
    private function renderFile(string $sourcePath, array $vars): string
    {
        if (isset($this->renderStack[$sourcePath])) {
            $chain = [...array_keys($this->renderStack), $sourcePath];
            throw new ClarityException(
                'Recursive template rendering detected: ' . \implode(' -> ', $chain),
                $sourcePath
            );
        }

        $this->renderStack[$sourcePath] = true;

        // Ensure compiled class is loaded
        try {
            $className = $this->loadCachedClass($sourcePath);

            // Instantiate with filter and function registries
            $template = new $className(
                $this->registry->allFilters(),
                $this->registry->allFunctions(),
                $this->registry->allServices()
            );

            // Install error handler to map PHP errors → template lines
            set_error_handler(
                $this->buildErrorHandler($sourcePath),
                E_ALL
            );

            try {
                $output = $template->render($vars);
            } finally {
                restore_error_handler();
            }
        } finally {
            unset($this->renderStack[$sourcePath]);
        }

        return $output;
    }

    /**
     * Return an already-loaded class name, compiling & caching as needed.
     *
     * @return class-string
     */
    private function loadCachedClass(string $sourcePath): string
    {
        if ($this->cache->isFresh($sourcePath)) {
            try {
                $className = $this->cache->load($sourcePath);
            } catch (ParseError) {
                // A previously-written cache file contains invalid PHP (e.g. a
                // template that was broken at write time and not yet cleaned up).
                // Delete it so the next step triggers a fresh compile.
                $this->cache->invalidate($sourcePath);
                $className = null;
            }
            if ($className !== null) {
                return $className;
            }
        }

        // Compile and write; the cache file is required inside writeAndLoad()
        // using plain `require` so the new versioned class is always declared.
        $this->compiler ??= new Compiler();
        $this->compiler
            ->setBasePath($this->viewPath)
            ->setExtension($this->extension)
            ->setNamespaces($this->namespaces)
            ->setRegistry($this->registry);
        $compiled = $this->compiler->compile($sourcePath);
        try {
            return $this->cache->writeAndLoad($sourcePath, $compiled);
        } catch (ParseError $e) {
            // The compiled PHP contains a syntax error (e.g. a malformed expression
            // in the template).  Delete the broken cache file so the next request
            // does not serve an unloadable file, then map the error back to the
            // original template line using the source map we already have.
            $this->cache->invalidate($sourcePath);
            [$tplFile, $tplLine] = $this->mapCompiledErrorLine(
                $e->getLine(),
                $compiled->code,
                $compiled->sourceMap,
                $compiled->sourceFiles,
                $sourcePath
            );
            throw new ClarityException(
                'Syntax error in template: ' . $e->getMessage(),
                $tplFile ?? $sourcePath,
                $tplLine,
                $e
            );
        }
    }

    /**
     * Map a file line number from a compiled cache file back to the original
     * template file and line, using only the source map from a CompiledTemplate
     * (no class loading or reflection required).
     *
     * Cache::writeAndLoad() prepends "<?php\n" before the compiled code, so
     * the body does not start at line 1.  The preamble emitted by buildClass()
     * is variable-length (deps/sourceMap exports span multiple lines), so the
     * offset is determined dynamically by locating the "ob_start()" sentinel
     * that marks the start of the render() body.
     *
     * @param int      $fileLine     1-based line number reported by the ParseError.
     * @param string   $compiledCode The compiled PHP code from CompiledTemplate (no leading <?php).
     * @param array    $sourceMap    Source map from the CompiledTemplate.
     * @param string[] $files        Source file paths (indexed by the integers in $sourceMap).
     * @param string   $sourcePath   Fallback template file path.
     * @return array{0: string|null, 1: int}  [templateFile|null, templateLine]
     */
    private function mapCompiledErrorLine(int $fileLine, string $compiledCode, array $sourceMap, array $files, string $sourcePath): array
    {
        if ($sourceMap === []) {
            return [null, 0];
        }

        // Locate the line that contains "ob_start()" inside the full file
        // (compiled code prefixed by the "<?php\n" that Cache adds).
        $fileLines = explode("\n", "<?php\n" . $compiledCode);
        $bodyStartFileLine = 0;
        foreach ($fileLines as $i => $line) {
            if (str_contains($line, 'ob_start()')) {
                $bodyStartFileLine = $i + 1; // 0-indexed → 1-indexed
                break;
            }
        }

        if ($bodyStartFileLine === 0) {
            return [null, 0];
        }

        // Convert the absolute file line to a body-relative line, which is
        // what the source map's phpLineStart values are indexed against.
        $bodyLine = $fileLine - $bodyStartFileLine + 1;

        // Find the last source-map range whose phpLineStart ≤ $bodyLine.
        $matched = null;
        foreach ($sourceMap as $range) {
            if ($range[0] <= $bodyLine) {
                $matched = $range;
            } else {
                break;
            }
        }

        if ($matched === null) {
            return [null, 0];
        }
        $tplFile = $files[$matched[1]] ?? null;
        return [$tplFile, $matched[2]];
    }

    /**
     * Build an error-handler closure that maps a PHP error in the compiled
     * cache file back to the original template file and line.
     *
     * @param string $sourcePath The entry template source path.
     * @return callable
     */
    private function buildErrorHandler(string $sourcePath): callable
    {
        $cacheFile = $this->cache->cacheFilePath($sourcePath);

        return function (int $errno, string $errstr, string $errfile, int $errline) use ($sourcePath, $cacheFile): bool {
            if (realpath($errfile) !== realpath($cacheFile)) {
                // Error is not in our compiled file – let it propagate normally
                return false;
            }

            [$tplFile, $tplLine] = $this->resolveTemplateLine($sourcePath, $errline);
            throw new ClarityException($errstr, $tplFile ?? $sourcePath, $tplLine);
        };
    }

    /**
     * Map a PHP line number in the compiled cache file back to the original
     * template file and line number using the $sourceMap static property on
     * the compiled class — no file I/O required.
     *
     * The source map is a list of ranges: [phpLineStart, fileIndex, templateLine].
     * The matching range is the last entry whose phpLineStart ≤ $phpLine.
     * File paths are resolved from the parallel $files static property.
     *
     * @param string $sourcePath Absolute path to the entry template.
     * @param int    $phpLine    Line number of the error in the compiled file.
     * @return array{0: string|null, 1: int}  [templateFile|null, templateLine]
     */
    private function resolveTemplateLine(string $sourcePath, int $phpLine): array
    {
        $className = $this->cache->getLoadedClassName($sourcePath);
        if ($className === null) {
            return [null, 0];
        }

        try {
            $map = $className::$sourceMap;
            $files = $className::$sourceFiles;
        } catch (\Error) {
            return [null, 0];
        }

        if (!\is_array($map) || $map === []) {
            return [null, 0];
        }

        // Ranges are sorted by phpLineStart ascending; find the last one ≤ phpLine.
        $matched = null;
        foreach ($map as $range) {
            if ($range[0] <= $phpLine) {
                $matched = $range;
            } else {
                break;
            }
        }

        if ($matched === null) {
            return [null, 0];
        }
        $tplFile = $files[$matched[1]] ?? null;
        return [$tplFile, $matched[2]];
    }

    // -------------------------------------------------------------------------
    // Object → array casting
    // -------------------------------------------------------------------------

    /**
     * Recursively cast values to arrays so templates never receive live
     * objects and cannot call methods.
     *
     * Precedence:
     * 1. JsonSerializable → jsonSerialize() then recurse
     * 2. Objects with toArray() → toArray() then recurse
     * 3. Other objects → get_object_vars() then recurse
     * 4. Arrays → recurse element by element
     * 5. Scalars / null → pass through
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

        if ($value instanceof \JsonSerializable) {
            $data = $value->jsonSerialize();
            return self::castToArray((array) $data);
        }

        if (\is_object($value)) {
            if (\method_exists($value, 'toArray')) {
                return self::castToArray($value->toArray());
            }
            return self::castToArray(get_object_vars($value));
        }

        return $value;
    }

}
