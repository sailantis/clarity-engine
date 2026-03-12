<?php
namespace Clarity;

use Clarity\Template\FileLoader;
use Clarity\Template\TemplateLoader;

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
 * $engine = new ClarityEngine([
 *    'viewPath' => __DIR__ . '/templates',
 *    'cachePath' => __DIR__ . '/cache',
 * ]);
 * # or configure via setters:
 * $engine = ClarityEngine::create()
 *    ->setViewPath(__DIR__ . '/templates')
 *    ->setCachePath(__DIR__ . '/cache');
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
     * This constructor accepts a single configuration array. Common keys:
     * - `vars`: array of initial variables available to all views
     * - `viewPath`: base path for views
     * - `extension`: file extension (with or without leading dot)
     * - `layout`: default layout name or null
     * - `namespaces`: associative array of namespace => path
     * - `cachePath`: path to compiled template cache (applied after init)
     * - `debug`: bool to enable debug mode
     *
     * @param array $config Configuration options for the engine.
     */
    public function __construct(array $config = [])
    {
        if (isset($config['vars']) && is_array($config['vars'])) {
            $this->vars = $config['vars'];
        }

        if (isset($config['viewPath']) && \is_string($config['viewPath'])) {
            $this->setViewPath($config['viewPath']);
        }

        if (isset($config['extension']) && \is_string($config['extension'])) {
            $this->setExtension($config['extension']);
        }

        if (isset($config['layout']) && \is_string($config['layout'])) {
            $this->setLayout($config['layout']);
        }

        if (isset($config['namespaces']) && \is_array($config['namespaces'])) {
            // Normalize paths (no trailing slash)
            foreach ($config['namespaces'] as $k => $p) {
                $this->addNamespace($k, $p);
            }
        }

        $this->initializeClarityEngine();

        // Post-init config that requires the registry/cache to exist
        if (isset($config['cachePath']) && \is_string($config['cachePath'])) {
            $this->setCachePath($config['cachePath']);
        }
        if (isset($config['debug'])) {
            $this->setDebugMode((bool) $config['debug']);
        }
    }

    public static function create(array $config = []): self
    {
        return new self($config);
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
        if ($this->loader instanceof FileLoader) {
            $this->loader->setExtension($ext);
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
        $this->namespaces[$name] = \rtrim($path, '/\\');
        if ($this->loader instanceof FileLoader) {
            $this->loader->addNamespace($name, $path);
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
     * Set a custom template loader, replacing the default FileLoader.
     *
     * @param TemplateLoader $loader The loader to use.
     * @return static
     */
    public function setLoader(TemplateLoader $loader): static
    {
        $this->loader = $loader;
        return $this;
    }

    /**
     * Return the active template loader, lazily creating a FileLoader if none
     * has been set explicitly.
     */
    public function getLoader(): TemplateLoader
    {
        return $this->loader ??= new FileLoader(
            $this->viewPath,
            $this->extension,
            $this->namespaces
        );
    }

    /**
     * Resolve a view name to an actual file path on the filesystem.
     *
     * @deprecated Use getLoader()->load($name) instead. Kept for backward compatibility.
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
