<?php
namespace Clarity;

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
    use ClarityEngineTrait;

    protected array $namespaces = [];
    protected string $viewPath = __DIR__ . '/../../../views';
    protected int $renderDepth = 0;
    protected ?string $layout = null;
    protected array $vars = [];
    protected string $extension = '.clarity.html';

    /**
     * Create a new ClarityEngine instance.
     *
     * @param array $vars Initial variables available to all views.
     */
    public function __construct(array $vars = [])
    {
        $this->vars = $vars;
        $this->initializeClarityEngine();
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

}
