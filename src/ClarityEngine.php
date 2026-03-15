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

    protected string $viewPath = __DIR__ . '/../../../views';
    protected ?string $extension = null;
    protected array $namespaces = [];
    protected int $renderDepth = 0;
    protected ?string $layout = null;
    protected array $vars = [];

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
        if (isset($config['vars']) && \is_array($config['vars'])) {
            $this->vars = $config['vars'];
        }

        if (isset($config['viewPath']) && \is_string($config['viewPath'])) {
            $this->setViewPath($config['viewPath']);
        }

        if (isset($config['extension']) && \is_string($config['extension'])) {
            $this->setExtension($config['extension']);
        }

        if (isset($config['namespaces']) && \is_array($config['namespaces'])) {
            foreach ($config['namespaces'] as $ns => $path) {
                if (\is_string($ns) && \is_string($path)) {
                    $this->addNamespace($ns, $path);
                }
            }
        }

        if (isset($config['layout']) && \is_string($config['layout'])) {
            $this->setLayout($config['layout']);
        }

        $this->initializeClarityEngine();

        // Post-init config that requires the registry/cache to exist
        if (isset($config['cachePath']) && \is_string($config['cachePath'])) {
            $this->setCachePath($config['cachePath']);
        }
        if (!empty($config['debug'])) {
            $this->enableDebug();
        }
    }

    public static function create(array $config = []): self
    {
        return new self($config);
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

}
