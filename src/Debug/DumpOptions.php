<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Configuration options for the Clarity dump renderers.
 *
 * Pass this to enableDebug() to customise how dump() and dd() display values.
 *
 * ```php
 * $engine->enableDebug(new DumpOptions(
 *     maxDepth: 4,
 *     maskKeys: ['password', 'token'],
 *     showPanel: true,
 * ));
 * ```
 */
final class DumpOptions
{
    public function __construct(
        /** Maximum nesting depth rendered before values are replaced by '…'. */
        public readonly int $maxDepth = 5,
        /** Maximum number of array items shown at any one level. */
        public readonly int $maxItems = 50,
        /** Key substrings whose values are hidden (case-insensitive). */
        public readonly array $maskKeys = ['password', 'token', 'secret', 'apikey', 'api_key'],
        /**
         * CLI renderer: when true, return value as string (useful for dd()).
         * When false (default), write to STDERR and return ''.
         */
        public readonly bool $forceToTemplate = false,
        /** Whether to inject the HTML debug panel bar into render() output. */
        public readonly bool $showPanel = false,
    ) {
    }
}
