<?php
namespace Clarity\Engine;

use Clarity\ClarityException;

/**
 * Registry of filter and function callables for the Clarity template engine.
 *
 * This class maintains the collection of built-in and user-defined filters and functions
 * available to templates. Filters transform values through the pipe operator (|>), while
 * functions are called directly in expressions.
 *
 * User code may add additional filters via {@see addFilter()} and functions via {@see addFunction()}.
 *
 * Built-in Filters Catalog
 * -------------------------
 *
 * **String / Text Manipulation**
 * - `trim`                      : Remove leading/trailing whitespace
 * - `upper`                     : Convert to uppercase (mb_strtoupper)
 * - `lower`                     : Convert to lowercase (mb_strtolower)
 * - `capitalize`                : First character uppercase, rest lowercase
 * - `title`                     : Title-case every word
 * - `nl2br`                     : Insert <br> tags before newlines (use with |> raw)
 * - `replace($search, $replace)`: String replacement (str_replace)
 * - `split($delimiter [, $limit])`: Split string into array (explode)
 * - `join($glue)`               : Join array elements to string (implode)
 * - `slug [$separator='-']`     : Generate URL-friendly slug
 * - `striptags [$allowed]`      : Strip HTML/PHP tags
 * - `truncate($length [, $ellipsis='…'])`: Truncate string to length
 * - `format(...$args)`          : sprintf-style string formatting
 * - `escape` (alias: `esc`)     : HTML-escape (htmlspecialchars) — rarely needed, auto-escaping enabled
 * - `raw`                       : Disable auto-escaping for this output (DANGEROUS with user input)
 *
 * **Numbers**
 * - `number($decimals=2)`       : Format number with decimal places (number_format)
 * - `abs`                       : Absolute value
 * - `round [$precision=0]`      : Round to decimal places
 * - `ceil`                      : Round up to nearest integer
 * - `floor`                     : Round down to nearest integer
 *
 * **Dates & Times**
 * - `date [$format='Y-m-d']`    : Format timestamp/DateTimeInterface/date string
 * - `date_modify($modifier)`    : Apply date modifier (e.g. '+1 day'), return Unix timestamp
 *
 * **Arrays & Collections**
 * - `first`                     : Get first element (works on arrays and strings)
 * - `last`                      : Get last element (works on arrays and strings)
 * - `keys`                      : Get array keys
 * - `values`                    : Get array values
 * - `length`                    : Count elements (arrays) or string length (mb_strlen)
 * - `slice($start [, $length])` : Extract portion (array_slice / mb_substr)
 * - `merge($other)`             : Merge arrays (array_merge)
 * - `sort`                      : Return sorted copy
 * - `reverse`                   : Reverse array or string (Unicode-aware)
 * - `shuffle`                   : Return shuffled copy
 * - `batch($size [, $fill])`    : Split into chunks, optionally padded
 *
 * **Collection Operations (Lambda Support)**
 * - `map(lambda|filterRef)`     : Transform each element
 *   Usage: `{{ users |> map(u => u.name) }}` or `{{ items |> map("upper") }}`
 * - `filter [lambda|filterRef]` : Keep elements matching condition (returns re-indexed array)
 *   Usage: `{{ items |> filter(i => i.active) }}`
 * - `reduce(lambda|filterRef [, $initial])`: Reduce to single value
 *   Usage: `{{ numbers |> reduce(sum, value => sum + value, 0) }}`
 *   Note: Lambda receives explicit accumulator and current-element parameters
 *
 * **Utility Filters**
 * - `json`                      : JSON encode (use with |> raw)
 * - `default($fallback)`        : Return fallback if value is empty/falsy
 * - `url_encode`                : URL-encode value (rawurlencode)
 * - `data_uri [$mimeType]`      : Generate base64-encoded data: URI
 * - `unicode`                   : Wrap in UnicodeString for advanced operations
 *
 * Built-in Functions
 * ------------------
 * - `context()`: Returns current template variables array
 * - `include($view [, $context])`: Render another template dynamically
 *
 * Custom Filter Examples
 * ----------------------
 * ```php
 * // Currency formatting
 * $registry->addFilter('currency', function($amount, string $symbol = '€') {
 *     return $symbol . ' ' . number_format($amount, 2);
 * });
 * 
 * // Smart excerpt with word boundary
 * $registry->addFilter('excerpt', function($text, int $maxLength = 150) {
 *     if (mb_strlen($text) <= $maxLength) return $text;
 *     $truncated = mb_substr($text, 0, $maxLength);
 *     $lastSpace = mb_strrpos($truncated, ' ');
 *     return mb_substr($truncated, 0, $lastSpace) . '…';
 * });
 * ```
 *
 * Template usage:
 * ```twig
 * {{ price |> currency('$') }}  {# Output: $ 123.45 #}
 * {{ article.body |> excerpt(200) }}
 * ```
 */
class Registry
{
    private mixed $includeRenderer;

    /** @var array<string, callable> */
    private array $functions = [];

    /** @var array<string, callable> keyword → handler */
    private array $blockHandlers = [];

    /** @var array<string, callable> */
    private array $filters = [
        // inlined filters
        'default' => true,
        'empty' => true,
        'length' => true,
        'slice' => true,
        'escape' => true,
        'esc' => true,
        'trim' => true,
        'upper' => true,
        'lower' => true,
        'capitalize' => true,
        'title' => true,
        'replace' => true,
        'nl2br' => true,
        'split' => true,
        'join' => true,
        'truncate' => true,
        'number' => true,
        'format' => true,
        'abs' => true,
        'round' => true,
        'ceil' => true,
        'floor' => true,
        'date' => true,
        'date_modify' => true,
        'first' => true,
        'last' => true,
        'keys' => true,
        'values' => true,
        'merge' => true,
        'reverse' => true,
        'data_uri' => true,
        'url_encode' => true,
        'striptags' => true,
        'json' => true,
        'unicode' => true,
    ];

    /**
     * Filter templates compiled directly into the generated PHP.
     *
     * `params` lists filter arguments after the piped value. `defaults` holds
     * PHP expressions used when those arguments are omitted.
     *
     * Modules may register additional inline filters via {@see addInlineFilter()}.
     *
     * @var array<string, array{php?: string, params?: string[], defaults?: array<string, string>, variadic?: bool}>
     */
    private array $inlineFilters = [
        'default' => [
            'php' => '({1} ?? {2})',
            'params' => ['fallback'],
            'defaults' => ['fallback' => 'null'],
        ],
        'empty' => [
            'php' => '({1} ?: {2})',
            'params' => ['fallback'],
            'defaults' => ['fallback' => '""'],
        ],
        'length' => [
            'php' => '(\is_array($__tmp = {1}) || $__tmp instanceof \Countable ? \count($__tmp) : \mb_strlen((string) $__tmp))',
        ],
        'slice' => [
            'php' => '(\is_array($__tmp = {1}) ? \array_slice($__tmp, {2}, {3}) : \mb_substr((string) $__tmp, {2}, {3}))',
            'params' => ['start', 'length'],
            'defaults' => ['length' => 'null'],
        ],
        'escape' => [
            'php' => '\htmlspecialchars((string) {1}, \ENT_QUOTES | \ENT_SUBSTITUTE, "UTF-8")',
        ],
        'esc' => [
            'php' => '\htmlspecialchars((string) {1}, \ENT_QUOTES | \ENT_SUBSTITUTE, "UTF-8")',
        ],
        'trim' => [
            'php' => '\trim((string) {1})',
        ],
        'upper' => [
            'php' => '\mb_strtoupper((string) {1})',
        ],
        'lower' => [
            'php' => '\mb_strtolower((string) {1})',
        ],
        'capitalize' => [
            'php' => '($__tmp = (string) {1}) === "" ? "" : \mb_strtoupper(\mb_substr($__tmp, 0, 1)) . \mb_strtolower(\mb_substr($__tmp, 1))',
        ],
        'title' => [
            'php' => '\mb_convert_case((string) {1}, \MB_CASE_TITLE)',
        ],
        'replace' => [
            'php' => '\str_replace({2}, {3}, (string) {1})',
            'params' => ['search', 'replace'],
            'defaults' => ['replace' => "''"],
        ],
        'nl2br' => [
            'php' => '\nl2br((string) {1})',
        ],
        'split' => [
            'php' => '\explode({2}, (string) {1}, {3})',
            'params' => ['delimiter', 'limit'],
            'defaults' => ['limit' => '\\PHP_INT_MAX'],
        ],
        'join' => [
            'php' => '\implode({2}, (array) {1})',
            'params' => ['glue'],
            'defaults' => ['glue' => "''"],
        ],
        'truncate' => [
            'php' => '(\mb_strlen($__tmp = ((string) {1})) <= {2} ? $__tmp : \mb_substr($__tmp, 0, {2}) . {3})',
            'params' => ['length', 'ellipsis'],
            'defaults' => ['ellipsis' => "'\\u{2026}'"],
        ],
        'number' => [
            'php' => '\number_format((float) {1}, {2})',
            'params' => ['decimals'],
            'defaults' => ['decimals' => '2'],
        ],
        'sprintf' => [
            'php' => '\sprintf',
            'params' => ['args'],
            'variadic' => true,
        ],
        'abs' => [
            'php' => '\abs({1} + 0)',
        ],
        'round' => [
            'php' => '\round((float) {1}, {2})',
            'params' => ['precision'],
            'defaults' => ['precision' => '0'],
        ],
        'ceil' => [
            'php' => '\ceil((float) {1})',
        ],
        'floor' => [
            'php' => '\floor((float) {1})',
        ],
        'date' => [
            'php' => '\date({2}, \is_int($__tmp = {1}) ? $__tmp : (int) \strtotime((string) $__tmp))',
            'params' => ['format'],
            'defaults' => ['format' => "'Y-m-d'"],
        ],
        'date_modify' => [
            'php' => '(int) ((new \DateTimeImmutable("@" . (\is_int($__tmp = {1}) ? $__tmp : (int) \strtotime((string) $__tmp))))->modify({2})->getTimestamp())',
            'params' => ['modifier'],
        ],
        'first' => [
            'php' => '(\is_array($__tmp = {1}) ? (\array_slice(\array_values($__tmp), 0, 1)[0] ?? null) : (($__tmp = (string) {1}) === "" ? "" : \mb_substr($__tmp, 0, 1)))',
        ],
        'last' => [
            'php' => '(\is_array($__tmp = {1}) ? (\array_slice(\array_values($__tmp), -1, 1)[0] ?? null) : (($__tmp = (string) {1}) === "" ? "" : \mb_substr($__tmp, -1)))',
        ],
        'keys' => [
            'php' => '(\is_array($__tmp = {1}) ? \array_keys($__tmp) : [])',
        ],
        'values' => [
            'php' => '(\is_array($__tmp = {1}) ? \array_values($__tmp) : [])',
        ],
        'merge' => [
            'php' => '[...(array) {1}, ...(array) {2}]',
            'params' => ['other'],
            'defaults' => ['other' => '[]'],
        ],
        'reverse' => [
            'php' => '(\is_array($__tmp = {1}) ? \array_reverse($__tmp) : \implode("", \array_reverse(\preg_split("//u", (string) $__tmp, -1, \PREG_SPLIT_NO_EMPTY) ?: [])))',
        ],
        'data_uri' => [
            'php' => '"data:" . {2} . ";base64," . \base64_encode((string) {1})',
            'params' => ['mime'],
            'defaults' => ['mime' => "'application/octet-stream'"],
        ],
        'url_encode' => [
            'php' => '\rawurlencode((string) {1})',
        ],
        'striptags' => [
            'php' => '\strip_tags((string) {1}, {2})',
            'params' => ['allowedTags'],
            'defaults' => ['allowedTags' => "''"],
        ],
        'json' => [
            // SON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
            'php' => '\json_encode({1}, 0x200340)',
        ],
        'unicode' => [
            'php' => 'new \Clarity\Engine\UnicodeString((string) {1}, {2}, {3})',
            'params' => ['start', 'length'],
            'defaults' => ['start' => '0', 'length' => 'null'],
        ],
    ];

    /**
     * Non-callable service objects stored under a named key and passed into
     * compiled templates via the $__fl array.
     *
     * Modules use this to inject shared state (e.g. a locale stack) that
     * inline filter PHP templates can access as `$this->__fl['__key']->method()`.
     *
     * @var array<string, mixed>
     */
    private array $services = [];

    /**
     * Optional closure that handles dump() output when enableDebug() is called.
     * Receives (string $ctx, mixed ...$args): string.
     * Null = use the built-in print_r fallback.
     */
    private ?\Closure $dumpHandler = null;

    /**
     * Optional closure that handles dd() output (always active, never null-checked
     * before falling back to var_dump + exit).
     * Receives (string $ctx, mixed ...$args): never.
     */
    private ?\Closure $ddHandler = null;

    /**
     * Install context-aware dump/dd handlers produced by enableDebug().
     *
     * Called internally — not part of the public engine API.
     */
    public function setDumpHandler(\Closure $fn): void
    {
        $this->dumpHandler = $fn;
    }

    public function setDdHandler(\Closure $fn): void
    {
        $this->ddHandler = $fn;
    }

    public function __construct(?callable $includeRenderer = null)
    {
        $this->includeRenderer = $includeRenderer;
        $this->registerBuiltinFunctions();
        $this->registerBuiltinFilters();
    }

    /**
     * Register a user-defined filter.
     *
     * @param string   $name Filter name used in templates (e.g. 'currency').
     * @param callable $fn   Callable receiving ($value, ...$args).
     * @return static
     */
    public function addFilter(string $name, callable $fn): static
    {
        $this->filters[$name] = $fn;
        return $this;
    }

    /**
     * Check whether a named filter is registered.
     */
    public function hasFilter(string $name): bool
    {
        return isset($this->filters[$name]);
    }

    /**
     * Register an additional inline filter that is compiled directly into the
     * generated PHP (zero runtime call overhead).
     *
     * The definition must follow the same structure as the built-in entries:
     *   'php'      – PHP expression template with {1} for the piped value and
     *                {2}, {3}, … for each additional parameter.
     *   'params'   – (optional) ordered list of parameter names.
     *   'defaults' – (optional) map of paramName → PHP default expression.
     *   'variadic' – (optional) true for variadic filters like 'format'.
     *
     * @param string $name       Filter name used in templates.
     * @param array{php?: string, params?: string[], defaults?: array<string, string>, variadic?: bool} $definition
     */
    public function addInlineFilter(string $name, array $definition): void
    {
        $this->inlineFilters[$name] = $definition;
        $this->filters[$name] = true;
    }

    /**
     * Check whether a named inline filter is registered.
     */
    public function hasInlineFilter(string $name): bool
    {
        return isset($this->inlineFilters[$name]);
    }

    /**
     * Get the definition of a named inline filter.
     */
    public function getInlineFilter(string $name): ?array
    {
        return $this->inlineFilters[$name] ?? null;
    }

    /**
     * Mark a filter name as a known inline filter (compiled to a PHP expression;
     * no callable is stored or invoked at runtime).  Modules that register
     * inline filters via Tokenizer::addInlineFilter() call this so that
     * hasFilter() returns true for the new name.
     */
    public function registerInlineFilter(string $name): void
    {
        $this->filters[$name] = true;
    }

    /**
     * Store a non-callable service object under a named key so that compiled
     * template render bodies can access it via `$this->__fl['key']->method()`.
     *
     * The key is conventionally prefixed with `__` to avoid collisions with
     * real filter names (e.g. `__locale`, `__translator`).
     *
     * @param string $name    Key under which the service is accessible in templates.
     * @param mixed  $service Any value; not required to be callable.
     */
    public function addService(string $name, mixed $service): static
    {
        $this->services[$name] = $service;
        return $this;
    }

    /**
     * Check whether a named service is registered.
     */
    public function hasService(string $name): bool
    {
        return isset($this->services[$name]);
    }

    /**
     * Retrieve a named service.
     *
     * @throws \RuntimeException if the service is not registered.
     */
    public function getService(string $name): mixed
    {
        if (!isset($this->services[$name])) {
            throw new \RuntimeException("Service '{$name}' is not registered.");
        }
        return $this->services[$name];
    }

    /**
     * Get all registered filters as a name → callable/value map.
     *
     * The returned array includes callable filters, inline-filter markers
     * (value `true`), and services registered via {@see addService()}.
     *
     * @return array<string, mixed>
     */
    public function allServices(): array
    {
        return $this->services;
    }

    /**
     * Get all registered filters as a name → callable/value map.
     *
     * The returned array includes callable filters, inline-filter markers
     * (value `true`), and services registered via {@see addService()}.
     *
     * @return array<string, mixed>
     */
    public function allFilters(): array
    {
        return $this->filters;
    }

    /**
     * Register a user-defined function.
     *
     * @param string   $name Function name used in templates (e.g. 'greet').
     * @param callable $fn   Callable receiving any positional arguments.
     * @return static
     */
    public function addFunction(string $name, callable $fn): static
    {
        $this->functions[$name] = $fn;
        return $this;
    }

    /**
     * Check whether a named function is registered.
     */
    public function hasFunction(string $name): bool
    {
        return isset($this->functions[$name]);
    }

    /**
     * Get all registered functions as a name → callable map.
     *
     * @return array<string, callable>
     */
    public function allFunctions(): array
    {
        return $this->functions;
    }

    // -------------------------------------------------------------------------

    private function registerBuiltinFunctions(): void
    {
        $this->functions['context'] = static fn(array $vars = []): array => $vars;

        $this->functions['include'] = function (string $view, array $context = []): string {
            if ($this->includeRenderer === null) {
                throw new \LogicException('The built-in include() function is not available in this Clarity runtime.');
            }

            return ($this->includeRenderer)($view, $context);
        };

        $this->functions['json'] = static function (mixed ...$args): string {
            if (\count($args) === 1) {
                $args = $args[0];
            }
            return (string) \json_encode(
                $args,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
        };

        // dump(): only called in debug mode (compiler prunes it to '' in production).
        // When enableDebug() has been called the dumpHandler does all the work;
        // otherwise we fall back to a minimal print_r-based HTML block.
        $this->functions['dump'] = function (string $ctx, mixed ...$args): string {
            if ($this->dumpHandler !== null) {
                return ($this->dumpHandler)($ctx, ...$args);
            }
            // Minimal fallback — debug mode without enableDebug() (e.g. setDebugMode(true))
            $out = '<pre style="background:#f7f7f9;padding:8px;border:1px solid #ddd;'
                . 'font-family:monospace;font-size:13px;overflow:auto">';
            foreach ($args as $i => $v) {
                $out .= \htmlspecialchars(
                    "[{$i}] " . \print_r($v, true),
                    \ENT_QUOTES | \ENT_SUBSTITUTE,
                    'UTF-8'
                );
            }
            return $out . '</pre>';
        };

        // dd(): always active regardless of debug mode — dump and die.
        $this->functions['dd'] = function (string $ctx, mixed ...$args): never {
            if ($this->ddHandler !== null) {
                ($this->ddHandler)($ctx, ...$args);
                // ddHandler must exit(); this is a safety net:
            }
            // Minimal fallback
            if (\PHP_SAPI !== 'cli' && \PHP_SAPI !== 'phpdbg') {
                \header('Content-Type: text/plain; charset=utf-8');
            }
            foreach ($args as $v) {
                \var_dump($v);
            }
            exit(1);
        };

        $this->functions['keys'] = static fn(mixed $v): array =>
            \is_array($v) ? \array_keys($v) : [];

        $this->functions['values'] = static fn(mixed $v): array =>
            \is_array($v) ? \array_values($v) : [];
    }

    private function registerBuiltinFilters(): void
    {

        // ── Dates ──────────────────────────────────────────────────────────

        $this->filters['format_datetime'] = static function (mixed $v, string $dateStyle = 'medium', string $timeStyle = 'medium', ?string $locale = null, ?string $timezone = null): string {
            // Convert to timestamp
            if (\is_int($v)) {
                $timestamp = $v;
            } else {
                $ts = \strtotime((string) $v);
                if ($ts === false) {
                    return ''; // oder Exception, je nach Philosophie
                }
                $timestamp = $ts;
            }

            // Create DateTime
            $dt = new \DateTime("@$timestamp");
            $dt->setTimezone(new \DateTimeZone($timezone ?? \date_default_timezone_get()));

            // Style map
            static $match = [
            'none' => \IntlDateFormatter::NONE,
            'short' => \IntlDateFormatter::SHORT,
            'medium' => \IntlDateFormatter::MEDIUM,
            'long' => \IntlDateFormatter::LONG,
            'full' => \IntlDateFormatter::FULL,
            ];

            // Create formatter
            $fmt = new \IntlDateFormatter(
                $locale ?? \Locale::getDefault(),
                $match[$dateStyle] ?? $dateStyle,
                $match[$timeStyle] ?? $timeStyle,
                $dt->getTimezone()->getName()
            );

            if (!$fmt) {
                return ''; // oder Exception
            }

            $out = $fmt->format($dt);
            return $out === false ? '' : $out;
        };

        // ── Arrays ─────────────────────────────────────────────────────────

        $this->filters['sort'] = static function (mixed $v): array {
            $arr = (array) $v;
            \sort($arr);
            return $arr;
        };

        $this->filters['shuffle'] = static function (mixed $v): array {
            $arr = (array) $v;
            \shuffle($arr);
            return $arr;
        };

        $this->filters['batch'] = static function (mixed $v, int $size, mixed $fill = null): array {
            $size = \max(1, $size);
            $chunks = \array_chunk((array) $v, $size);
            if ($fill !== null && !empty($chunks)) {
                $last = &$chunks[\count($chunks) - 1];
                while (\count($last) < $size) {
                    $last[] = $fill;
                }
            }
            return $chunks;
        };

        // map / filter / reduce: the callable argument is always a compiled
        // PHP closure produced by the Clarity compiler from a lambda expression
        // (item => item.field) or a filter reference ("filterName").
        // Passing raw callable variables from template scope is rejected at
        // compile time — only these two safe forms are accepted.

        $this->filters['map'] = static fn(mixed $v, callable $fn): array =>
            \array_map($fn, (array) $v);

        $this->filters['filter'] = static fn(mixed $v, ?callable $fn = null): array =>
            \array_values(\array_filter((array) $v, $fn));

        $this->filters['reduce'] = static fn(mixed $v, callable $fn, mixed $initial = null): mixed =>
            \array_reduce((array) $v, $fn, $initial);

        // ── Utility ────────────────────────────────────────────────────────

        $this->filters['slug'] = static function (mixed $v, string $separator = '-'): string {
            $s = (string) $v;
            if (\function_exists('iconv')) {
                $s = (string) \iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            } elseif (\function_exists('transliterator_transliterate')) {
                $s = transliterator_transliterate('Any-Latin; Latin-ASCII; [:Nonspacing Mark:] Remove; NFC', $s);
            }
            $s = \mb_strtolower($s);
            $s = (string) \preg_replace('/[^a-z0-9]+/', $separator, $s);
            return \trim($s, $separator);
        };

    }

    /**
     * Registry of custom block / directive handlers for the Clarity compiler.
     *
     * Modules register pairs of block keywords (e.g. `with_locale` / `endwith_locale`) whose compilation is delegated to user-supplied callables instead of being handled by the built-in match table in {@see Compiler::compileBlock()}.
     *
     * Handler signature
     * -----------------
     * ```php
     * function(
     *     string   $rest,        // everything after the keyword in the {% … %} tag
     *     string   $sourcePath,  // absolute path being compiled (for error messages)
     *     int      $tplLine,     // template line number (for error messages)
     *     callable $processExpr  // fn(string $clarityExpr): string — converts a Clarity expression to a PHP expression string
     * ): string                  // compiled PHP statement(s) for this directive
     * ```
     *
     * Example registration (inside a Module::register() call):
     * ```php
     * $engine->addBlock('with_locale', function(string $rest, string $path, int $line, callable $expr): string {
     *     $param = $expr(trim($rest));
     *     return "\$this->__fl['__locale']->push({$param});";
     * });
     * $engine->addBlock('endwith_locale', fn(...) => "\$this->__fl['__locale']->pop();");
     * ```
     *
     * @param string   $keyword  Directive keyword (lowercase, e.g. 'with_locale').
     * @param callable $handler  See class docblock for expected signature.
     */
    public function addBlock(string $keyword, callable $handler): static
    {
        $this->blockHandlers[$keyword] = $handler;
        return $this;
    }

    /**
     * Check whether a handler is registered for the given keyword.
     */
    public function hasBlock(string $keyword): bool
    {
        return isset($this->blockHandlers[$keyword]);
    }

    /**
     * Invoke the registered handler for $keyword and return compiled PHP.
     *
     * @param string   $keyword     Directive keyword.
     * @param string   $rest        Raw text after the keyword inside {% … %}.
     * @param string   $sourcePath  Source file path (for error messages).
     * @param int      $tplLine     Template line number (for error messages).
     * @param callable $processExpr fn(string $clarityExpr): string converter.
     * @return string Compiled PHP statement(s).
     * @throws ClarityException If the handler itself throws one.
     */
    public function compileBlock(
        string $keyword,
        string $rest,
        string $sourcePath,
        int $tplLine,
        callable $processExpr,
    ): string {
        return ($this->blockHandlers[$keyword])(
            $rest,
            $sourcePath,
            $tplLine,
            $processExpr
        );
    }

}
