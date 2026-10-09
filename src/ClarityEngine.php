<?php
namespace Clarity;

/**
 * Clarity Template Engine
 *
 * A PHP template engine for `.clarity.html` templates.
 * Templates are sandboxed by default: they can use only variables passed to
 * render() and registered filters and functions. A policy can instead grant
 * templates access to PHP.
 *
 * Key Features
 * ------------
 * - **Compiled & Cached**: Templates compile to PHP classes that are cached on disk and can benefit from OPcache
 * - **Sandbox**: By default, templates cannot execute arbitrary PHP code and have strict variable access control
 * - **Policy-Based Access Control**: Fine-grained control over what templates can access (for example PHP functions, method calls, raw `{% php %}` tags)
 * - **Debugging**: Integrated debug tools for development
 * - **Auto-escaping**: Output is HTML-escaped by default
 * - **Template Inheritance**: Reusable layouts via extends/blocks
 * - **Filter Pipeline**: Transform data with chainable filters (|>)
 * - **Unicode Support**: Multibyte string handling with NFC normalization
 *
 * Basic Usage
 * -----------
 * ```php
 * use Clarity\ClarityEngine;
 *
 * $engine = new ClarityEngine([
 *     'viewPath' => __DIR__ . '/templates',
 *     'cachePath' => __DIR__ . '/cache',
 * ]);
 * // or configure with setters:
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
 * Templates are sandboxed by default and cannot:
 * - Access PHP variables directly ($var forbidden)
 * - Call arbitrary PHP functions (use filters instead)
 * - Execute arbitrary code (no eval, backticks, etc.)
 * - Call methods on objects
 *
 * A {@see \Clarity\Engine\Policy} decides what a template may reach. It consists
 * of rules and two allowlists, resolved at compile time. An application that
 * needs one PHP function can grant only that function and keep the sandbox
 * (see Policy::default()). Granting `rawPhp`, `phpVariables`, or `methodCalls`
 * gives templates broad PHP access, so limit these to templates written by
 * trusted authors.
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
     * - `policy`: what templates may reach — a {@see \Clarity\Engine\Policy} or
     *   the array form it accepts. Sandboxed by default; `Policy::unrestricted()`
     *   is the full-power PHP mode.
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

        // What templates may reach.  Applied AFTER initializeClarityEngine(),
        // which installs the sandboxed default — the other order silently
        // discarded the configured policy.
        if (isset($config['policy'])) {
            $this->setPolicy($config['policy']);
        }

        // Post-init config that requires the registry/cache to exist
        if (isset($config['cachePath']) && \is_string($config['cachePath'])) {
            $this->setCachePath($config['cachePath']);
        }
        if (!empty($config['debug'])) {
            $this->setDebugMode($config['debug']);
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
