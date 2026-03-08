<?php
namespace Clarity;

use Clarity\Engine\Cache;
use Clarity\Engine\Compiler;
use Clarity\Engine\FunctionRegistry;
use ParseError;

/**
 * Clarity Template Engine
 *
 * A fast, secure, and expressive PHP template engine that compiles `.clarity.html` 
 * templates into cached PHP classes. Templates execute in a sandboxed environment 
 * with NO access to arbitrary PHP — they can only use variables passed to render() 
 * and registered filters/functions.
 *
 * Key Features
 * ------------
 * - **Compiled & Cached**: Templates compile to PHP classes, leveraging OPcache for performance
 * - **Secure Sandbox**: No arbitrary PHP execution, strict variable access control
 * - **Auto-escaping**: Built-in XSS protection with automatic HTML escaping
 * - **Template Inheritance**: Reusable layouts via extends/blocks
 * - **Filter Pipeline**: Transform data with chainable filters (|>)
 * - **Unicode Support**: Full multibyte string handling with NFC normalization
 *
 * Basic Usage
 * -----------
 * ```php
 * use Clarity\ClarityEngine;
 * 
 * $engine = new ClarityEngine();
 * $engine->setViewPath(__DIR__ . '/templates');
 * $engine->setCachePath(__DIR__ . '/cache');
 * 
 * // Register a custom filter
 * $engine->addFilter('currency', fn($v, string $symbol = '€') => 
 *     $symbol . ' ' . number_format($v, 2)
 * );
 * 
 * // Render a template
 * echo $engine->render('welcome', [
 *     'user' => ['name' => 'John'],
 *     'balance' => 1234.56
 * ]);
 * ```
 *
 * Template Syntax
 * ---------------
 * ```twig
 * {# Output with auto-escaping #}
 * <h1>Hello, {{ user.name }}!</h1>
 * 
 * {# Filters transform values #}
 * <p>Balance: {{ balance |> currency('$') }}</p>
 * 
 * {# Control flow #}
 * {% if user.isActive %}
 *   <span>Active</span>
 * {% endif %}
 * 
 * {# Loops #}
 * {% for item in items %}
 *   <li>{{ item.name }}</li>
 * {% endfor %}
 * ```
 *
 * Template Inheritance
 * --------------------
 * ```twig
 * {# layouts/base.clarity.html #}
 * <!DOCTYPE html>
 * <html>
 *   <head><title>{% block title %}Default{% endblock %}</title></head>
 *   <body>{% block content %}{% endblock %}</body>
 * </html>
 * 
 * {# pages/home.clarity.html #}
 * {% extends "layouts/base" %}
 * {% block title %}Home{% endblock %}
 * {% block content %}<h1>Welcome!</h1>{% endblock %}
 * ```
 *
 * Configuration
 * -------------
 * - Default template extension: `.clarity.html` (override with setExtension())
 * - Default cache location: `sys_get_temp_dir()/clarity_cache` (set with setCachePath())
 * - Cache auto-invalidation: Templates recompile when source files change
 * - Namespace support: Organize templates with named directories
 *
 * Security
 * --------
 * Templates are sandboxed and cannot:
 * - Access PHP variables directly ($var forbidden)
 * - Call arbitrary PHP functions (use filters instead)
 * - Execute arbitrary code (no eval, backticks, etc.)
 * - Call methods on objects (objects converted to arrays)
 *
 * @see https://github.com/clarity/engine Documentation and examples
 */
class ClarityEngine
{
    private FunctionRegistry $functionRegistry;
    private Cache $cache;
    private Compiler $compiler;
    /** @var string[] */
    private array $renderStack = [];
    protected string $extension = '.clarity.html';
    protected array $namespaces = [];
    protected string $viewPath = __DIR__ . '/../../../views';
    protected int $renderDepth = 0;
    protected ?string $layout = null;
    protected array $vars = [];

    /**
     * Create a new ClarityEngine instance.
     *
     * @param array $vars Initial variables available to all views.
     */
    public function __construct(array $vars = [])
    {
        $this->vars = $vars;

        $this->functionRegistry = new FunctionRegistry(
            fn(string $view, array $vars = []): string => $this->renderPartial($view, $vars)
        );
        $this->cache = new Cache();
        $this->compiler = new Compiler();
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
        $this->namespaces[$name] = rtrim($path, '/');
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
     * Set the base path for resolving relative view names.
     *
     * @param string $path Base directory for views.
     * @return $this
     */
    public function setViewPath(string $path): static
    {
        $this->viewPath = rtrim($path, '/');
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
     * Set the layout template name to be used when calling `render()`.
     *
     * The layout will receive a `content` variable containing the
     * rendered view output.
     *
     * @param string|null $layout Layout view name or null to disable.
     * @return $this
     */
    public function setLayout(?string $layout): static
    {
        $this->layout = $layout;
        return $this;
    }

    /**
     * Get the currently configured layout view name.
     *
     * @return string|null Layout name or null when none set.
     */
    public function getLayout(): ?string
    {
        return $this->layout;
    }

    /**
     * Set a single view variable.
     *
     * @param string $name Variable name available inside templates.
     * @param mixed $value Value assigned to the variable.
     * @return $this
     */
    public function setVar(string $name, mixed $value): static
    {
        $this->vars[$name] = $value;
        return $this;
    }

    /**
     * Merge multiple variables into the view's variable set.
     *
     * Later values override earlier ones for the same keys.
     *
     * @param array $vars Associative array of variables.
     * @return $this
     */
    public function setVars(array $vars): static
    {
        $this->vars = [...$this->vars, ...$vars];
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
     * - Arrays: `join`, `length`, `first`, `last`, `map`, `filter`, `reduce`
     * - Dates: `date`
     * - Other: `json`, `default`
     *
     * @param string   $name Filter name used in templates (e.g. 'currency').
     * @param callable $fn   Callable with signature: fn($value, ...$args): mixed
     * @return static Fluent interface
     */
    public function addFilter(string $name, callable $fn): static
    {
        $this->functionRegistry->addFilter($name, $fn);
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
        $this->functionRegistry->addFunction($name, $fn);
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
            $cast = FunctionRegistry::castToArray($merged);
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

    /**
     * Resolve a view name to an actual file path on the filesystem.
     * @param string $view View name to resolve.
     * @throws \RuntimeException If the view cannot be resolved.
     * @return string Resolved file path.
     */
    protected function resolveView(string $view): string
    {
        if ($view === '') {
            throw new \RuntimeException("Empty view name");
        }

        $ns = \strstr($view, '::', true);
        if ($ns !== false) {
            // namespaced view
            $name = \substr($view, \strlen($ns) + 2);

            if (!isset($this->namespaces[$ns])) {
                throw new \RuntimeException("Unknown view namespace: $ns");
            }

            return $this->namespaces[$ns] . '/' . \str_replace('.', '/', $name) . $this->extension;
        }

        $len = \strlen($view);

        $addExtension = !\str_ends_with($view, $this->extension);

        if ($view[0] === '/') {
            // absolute unix path
            $path = $view;

        } elseif ($view[1] === ':' && $len >= 3 && ($view[2] === '/' || $view[2] === '\\') && \ctype_alpha($view[0])) {
            // absolute windows path: C:/foo or C:\foo
            $path = $view;

        } elseif ($view[0] === '\\' && $len >= 2 && $view[1] === '\\') {
            // absolute UNC path: \\server\share
            $path = $view;

        } elseif ($view[0] === '.') {
            // treat as literal relative path: ./partials/header or ../shared/footer
            $path = $this->viewPath . '/' . $view;

        } else {
            // relative view name, resolve to path using dot-notation
            $relative = \str_replace('.', '/', $view);
            $path = $this->viewPath . '/' . $relative;
        }

        if ($addExtension) {
            $path .= $this->extension;
        }
        return $path;
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
                $this->functionRegistry->allFilters(),
                $this->functionRegistry->allFunctions(),
                [FunctionRegistry::class, 'castToArray']
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
        $this->syncCompilerConfig();
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
     * Keep the compiler in sync with engine configuration (path, extension,
     * namespaces).  Called before every fresh compile.
     */
    private function syncCompilerConfig(): void
    {
        $this->compiler
            ->setBasePath($this->viewPath)
            ->setExtension($this->extension)
            ->setNamespaces($this->namespaces)
            ->setFilterRegistry($this->functionRegistry);
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

}
