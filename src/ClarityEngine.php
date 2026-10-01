<?php
namespace Clarity;

use Clarity\Engine\Policy;

/**
 * Clarity Template Engine
 *
 * A fast, secure, and expressive PHP template engine for `.clarity.html` templates.
 * Templates are sandboxed by default — they can only use variables passed to
 * render() and registered filters/functions — or run in PHP mode (sandbox disabled)
 * with the full power of PHP. Both modes deliver maximum performance.
 *
 * Key Features
 * ------------
 * - **Compiled & Cached**: Templates compile to PHP classes, leveraging OPcache for performance
 * - **Secure Sandbox**: No arbitrary PHP execution, strict variable access control
 * - **Opt-In PHP Mode**: A policy can grant templates the full power of PHP
 *   (any function, method calls, raw `{% php %}` tags)
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
 * Templates are sandboxed by default and cannot:
 * - Access PHP variables directly ($var forbidden)
 * - Call arbitrary PHP functions (use filters instead)
 * - Execute arbitrary code (no eval, backticks, etc.)
 * - Call methods on objects
 *
 * What a template may reach is decided by a {@see \Clarity\Engine\Policy}: a set
 * of capabilities plus two allowlists, resolved entirely at compile time.  An
 * application that needs one PHP function grants it without giving up the
 * sandbox (see Policy::custom()); granting the capabilities that reach PHP at
 * all (rawPhp, phpVariables, methodCalls) is equivalent to executing arbitrary
 * PHP and is intended for templates written by trusted authors only.
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
     *   the array form it accepts. Sandboxed by default; `Policy::open()` is the
     *   full-power PHP mode.
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
