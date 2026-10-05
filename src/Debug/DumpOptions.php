<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Configuration options for the Clarity dump renderers.
 *
 * Pass this to {@see \Clarity\ClarityEngineTrait::setDebugMode()} to customise
 * how dump() and dd() display values.  Every option is set both by the
 * constructor and by a fluent method of the same name, so the two styles
 * compose and a value can be adjusted after the object exists:
 *
 * ```php
 * // Named arguments in one expression…
 * $engine->setDebugMode(new DumpOptions(
 *     maxDepth: 4,
 *     maskKeys: ['password', 'token'],
 *     showPanel: true,
 * ));
 *
 * // …or a chain of calls, on a fresh instance or a shared one:
 * $engine->setDebugMode((new DumpOptions())->maxDepth(4)->maskKeys(['password']));
 * $opts->showPanel();
 * ```
 *
 * A chain is MUTABLE: each method changes this instance and returns it, so
 * `$opts->maxDepth(4)` is visible to every holder of `$opts` — which is what
 * makes a `DumpOptions` handed to the engine earlier reconfigurable later.
 */
final class DumpOptions
{
    public function __construct(
        /**
         * @var int Maximum nesting depth rendered before values are replaced by '…'.
         */
        public int $maxDepth = 5,
        /**
         * @var int Maximum number of array items shown at any one level.
         */
        public int $maxItems = 50,
        /**
         * @var list<string> Key substrings whose values are hidden.
         */
        public array $maskKeys = ['password', 'token', 'secret', 'apikey', 'api_key'],
        /**
         * @var bool When true, the CLI renderer returns the value as a string instead of writing to STDERR.
         */
        public bool $forceToTemplate = false,
        /**
         * @var bool Whether to render the HTML debug panel along with the output.
         */
        public bool $showPanel = false,
    ) {}

    /**
     * Create a new instance with default options.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Maximum nesting depth rendered before values are replaced by '…'.
     */
    public function maxDepth(int $maxDepth): self
    {
        $this->maxDepth = $maxDepth;
        return $this;
    }

    /**
     * Maximum number of array items shown at any one level.
     */
    public function maxItems(int $maxItems): self
    {
        $this->maxItems = $maxItems;
        return $this;
    }

    /**
     * Replace the mask-key list.  A key is hidden when its name contains one
     * of these substrings, case-insensitively.
     *
     * @param list<string> $maskKeys
     */
    public function maskKeys(array $maskKeys): self
    {
        $this->maskKeys = $maskKeys;
        return $this;
    }

    /**
     * Add a key substring to mask, leaving the current list in place.
     */
    public function maskKey(string $maskKey): self
    {
        $this->maskKeys[] = $maskKey;
        return $this;
    }

    /**
     * Stop masking a key substring, leaving the rest of the list in place.
     */
    public function unmaskKey(string $maskKey): self
    {
        $this->maskKeys = \array_filter(
            $this->maskKeys ?? [],
            static fn(string $mask): bool => $mask !== $maskKey
        );
        return $this;
    }

    /**
     * CLI renderer: when true, return the value as a string (useful for dd()).
     * When false (default), write to STDERR and return ''.
     */
    public function forceToTemplate(bool $forceToTemplate = true): self
    {
        $this->forceToTemplate = $forceToTemplate;
        return $this;
    }

    /**
     * Whether to render the HTML debug panel along with the output.
     */
    public function showPanel(bool $showPanel = true): self
    {
        $this->showPanel = $showPanel;
        return $this;
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    public function getMaxItems(): int
    {
        return $this->maxItems;
    }

    /**
     * @return list<string>
     */
    public function getMaskKeys(): array
    {
        return \array_values($this->maskKeys ?? []);
    }

    public function getForceToTemplate(): bool
    {
        return $this->forceToTemplate;
    }

    public function getShowPanel(): bool
    {
        return $this->showPanel;
    }
}
