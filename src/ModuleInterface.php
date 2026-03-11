<?php

namespace Clarity;

/**
 * Contract for Clarity engine modules.
 *
 * A module bundles a cohesive set of filters, functions, and block directives
 * and registers them all in one call via {@see ClarityEngine::use()}.
 *
 * Example
 * -------
 * ```php
 * $clarity->use(new IntlFormatModule([
 *     'locale'            => 'jp_JP',
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
 *         $engine->addBlock('my_block', fn($rest, $path, $line, $expr) => '// …');
 *     }
 * }
 * ```
 */
interface ModuleInterface
{
    /**
     * Register all filters, functions, services, and block directives that
     * this module provides into the given engine instance.
     *
     * This method is called once by {@see ClarityEngine::use()} at engine
     * setup time, before any templates are compiled or rendered.
     *
     * @param ClarityEngine $engine The engine to register into.
     */
    public function register(ClarityEngine $engine): void;
}
