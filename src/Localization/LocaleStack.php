<?php

namespace Clarity\Localization;

use Locale;

/**
 * A simple stack-based locale manager.
 *
 * Templates use `{% with_locale "fr_FR" %}` to temporarily push a locale onto
 * the stack. All locale-aware filters in scope read the current top of the
 * stack, so nested blocks work as expected.
 *
 * The stack is a plain PHP object shared by value (reference semantics) between
 * the module's filter closures and the compiled template's `$this->__fl` map,
 * so push/pop calls are immediately visible to all filters in the same render.
 *
 * Example:
 * ```clarity
 * {% with_locale "fr_FR" %}
 *     {{ price |> currency("EUR") }}
 * {% endwith_locale %}
 * ```
 * Compiled to:
 * ```php
 * $this->__fl['__locale']->push("fr_FR");
 * // … rendered body …
 * $this->__fl['__locale']->pop();
 * ```
 */
class LocaleStack
{
    /** @var string[] */
    private array $stack = [];

    /**
     * @param string $defaultLocale  Locale returned when the stack is empty.
     *                               Defaults to PHP's Locale::getDefault(), or
     *                               'en_US' if that is unset.
     */
    public function __construct(private string $defaultLocale = '')
    {
        if ($this->defaultLocale === '') {
            $this->defaultLocale = Locale::getDefault() ?: 'en_US';
        }
    }

    /**
     * Push a locale onto the stack.
     *
     * Passing null or an empty string is a no-op so that template variables
     * that may be null do not corrupt the stack.
     */
    public function push(?string $locale): void
    {
        if ($locale !== null && $locale !== '') {
            $this->stack[] = $locale;
        }
    }

    /**
     * Pop the top locale from the stack.
     *
     * Calling this when the stack is empty is a no-op.
     */
    public function pop(): void
    {
        \array_pop($this->stack);
    }

    /**
     * Return the currently active locale (top of the stack), or the default
     * locale when the stack is empty.
     */
    public function current(): string
    {
        return empty($this->stack)
            ? $this->defaultLocale
            : \end($this->stack);
    }

    /**
     * Change the default locale used when the stack is empty.
     */
    public function setDefault(string $locale): void
    {
        $this->defaultLocale = $locale;
    }
}
