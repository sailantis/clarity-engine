<?php

namespace Clarity;

/**
 * Contract for Clarity engine modules.
 *
 * A module bundles related filters, functions, and directives and registers them
 * in one call via {@see ClarityEngine::addModule()}.
 *
 * Example
 * -------
 * ```php
 * $clarity->addModule(new IntlFormatModule([
 *     'locale'            => 'ja_JP',
 * ]));
 * ```
 *
 * Implementing a module
 * ---------------------
 * ```php
 * class MyModule implements ModuleInterface
 * {
 *     public function register(ClarityEngine $engine): void
 *     {
 *         $engine->addFilter('my_filter', fn($v) => strtoupper($v));
 *         $engine->addDirective('my_directive', fn($rest, $at, $expr) => '// …');
 *     }
 * }
 * ```
 */
interface ModuleInterface
{
    /**
     * Register all filters, functions, services, and directives that
     * this module provides into the given engine instance.
     *
     * {@see ClarityEngine::addModule()} calls this method immediately. Call
     * addModule() before rendering templates so the module's filters and
     * directives are available.
     *
     * @param ClarityEngine $engine The engine to register into.
     */
    public function register(ClarityEngine $engine): void;
}
