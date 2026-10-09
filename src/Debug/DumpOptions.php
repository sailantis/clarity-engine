<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Options for the dump renderers used by dump() and dd().
 *
 * Pass an instance to {@see \Clarity\ClarityEngineTrait::setDebugMode()}. Each
 * option can be set through the constructor or through a fluent method of the
 * same name, so both styles can be combined:
 *
 * ```php
 * // Named arguments
 * $engine->setDebugMode(new DumpOptions(
 *     maxDepth: 4,
 *     maskKeys: ['password', 'token'],
 *     showPanel: true,
 * ));
 *
 * // Method chain
 * $engine->setDebugMode((new DumpOptions())->maxDepth(4)->maskKeys(['password']));
 * ```
 *
 * Each fluent method changes the instance and returns it. Changes to maxDepth,
 * maxItems, maskKeys, forceToTemplate and haltWithException therefore apply to
 * the engine after the DumpOptions was passed in. showPanel is read only when
 * debug is enabled, so call setDebugMode() again to change it.
 */
final class DumpOptions
{
    public function __construct(
        /**
         * @var int Maximum nesting depth rendered. Values at or beyond this depth are replaced by '…'.
         */
        public int $maxDepth = 5,
        /**
         * @var int Maximum number of array items shown per level. The rest are summarized.
         */
        public int $maxItems = 50,
        /**
         * @var list<string> Substrings of array keys and object property names whose values are hidden (case-insensitive).
         */
        public array $maskKeys = ['password', 'token', 'secret', 'apikey', 'api_key'],
        /**
         * @var bool CLI only. When true, dump() returns the rendered string instead of writing it to STDERR.
         */
        public bool $forceToTemplate = false,
        /**
         * @var bool Whether to render the HTML debug panel. Read when debug is enabled.
         */
        public bool $showPanel = false,
        /**
         * @var bool dd() only. When true, dd() throws a DumpHaltException instead of ending the process.
         */
        public bool $haltWithException = false,
    ) {}

    /**
     * Creates an instance with default options.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Sets the maximum nesting depth rendered.
     */
    public function maxDepth(int $maxDepth): self
    {
        $this->maxDepth = $maxDepth;
        return $this;
    }

    /**
     * Sets the maximum number of array items shown per level.
     */
    public function maxItems(int $maxItems): self
    {
        $this->maxItems = $maxItems;
        return $this;
    }

    /**
     * Replaces the mask list. A key is masked when its name contains one of
     * these substrings, case-insensitively. Array keys and object property names are checked.
     *
     * @param list<string> $maskKeys
     */
    public function maskKeys(array $maskKeys): self
    {
        $this->maskKeys = $maskKeys;
        return $this;
    }

    /**
     * Adds a substring to the mask list.
     */
    public function maskKey(string $maskKey): self
    {
        $this->maskKeys[] = $maskKey;
        return $this;
    }

    /**
     * Removes a substring from the mask list.
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
     * CLI only. When true, dump() returns the rendered string instead of
     * writing it to STDERR. When false (default), dump() writes to STDERR and
     * returns ''. dd() always writes to STDERR and ignores this option.
     */
    public function forceToTemplate(bool $forceToTemplate = true): self
    {
        $this->forceToTemplate = $forceToTemplate;
        return $this;
    }

    /**
     * Shows or hides the HTML debug panel. Takes effect when debug is enabled.
     */
    public function showPanel(bool $showPanel = true): self
    {
        $this->showPanel = $showPanel;
        return $this;
    }

    /**
     * dd() only. When true, dd() throws a {@see DumpHaltException} with the
     * rendered dump instead of calling exit(1). Use it in hosts that keep the
     * PHP process alive between requests, such as RoadRunner, so that one dd()
     * ends one request and not the worker. The exception is thrown on every
     * SAPI, including the CLI.
     */
    public function haltWithException(bool $haltWithException = true): self
    {
        $this->haltWithException = $haltWithException;
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

    public function getHaltWithException(): bool
    {
        return $this->haltWithException;
    }
}
