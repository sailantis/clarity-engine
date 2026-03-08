<?php
namespace Clarity\Engine;

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
 *   Usage: `{{ numbers |> reduce(sum => sum + value, 0) }}`
 *   Note: Lambda receives accumulator as parameter, current element as implicit `value`
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
class FunctionRegistry
{
    private mixed $includeRenderer;

    /** @var array<string, callable> */
    private array $functions = [];

    /** @var array<string, callable> */
    private array $filters = [];

    /** @var array<string, true> Names of user-registered (non-builtin) filters. */
    private array $customFilters = [];

    /** @var array<string, true> Names of user-registered (non-builtin) functions. */
    private array $customFunctions = [];

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
        $this->customFilters[$name] = true;
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
     * Returns true when $name was registered via addFilter() rather than a built-in.
     * Used by the compiler to decide whether to sandbox the return value.
     */
    public function isCustomFilter(string $name): bool
    {
        return isset($this->customFilters[$name]);
    }

    /**
     * Get all registered filters as a name → callable map.
     *
     * @return array<string, callable>
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
        $this->customFunctions[$name] = true;
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
     * Returns true when $name was registered via addFunction() rather than a built-in.
     * Used by the compiler to decide whether to sandbox the return value.
     */
    public function isCustomFunction(string $name): bool
    {
        return isset($this->customFunctions[$name]);
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

            return ($this->includeRenderer)(
                $view,
                self::castToArray($context)
            );
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

        $this->functions['dump'] = static function (mixed ...$args): string {
            $result = '';
            foreach ($args as $index => $arg) {
                $result .= "[$index] ";
                $result .= print_r($arg, true);
                $result .= "\n";
            }
            return $result;
        };

        $this->functions['keys'] = static fn(mixed $v): array =>
            \is_array($v) ? \array_keys($v) : [];

        $this->functions['values'] = static fn(mixed $v): array =>
            \is_array($v) ? \array_values($v) : [];
    }

    private function registerBuiltinFilters(): void
    {

        $this->filters['default'] = static fn(mixed $v, mixed $fallback) => $v ?: $fallback;

        $this->filters['length'] = static fn(mixed $v): int => \is_array($v) || $v instanceof \Countable ? \count($v) : \mb_strlen((string) $v);

        $this->filters['slice'] = static function (mixed $v, int $start, ?int $length = null): mixed {
            if (\is_array($v)) {
                return \array_slice($v, $start, $length);
            }
            return \mb_substr((string) $v, $start, $length);
        };

        // ── String / text ──────────────────────────────────────────────────

        $this->filters['unicode'] = static fn(mixed $v, int $start = 0, ?int $length = null): UnicodeString => new UnicodeString((string) $v, $start, $length);

        $this->filters['escape'] = static fn(mixed $v): string => (string) \htmlspecialchars((string) $v, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $this->filters['esc'] = $this->filters['escape']; // alias

        $this->filters['trim'] = static fn(mixed $v): string => trim((string) $v);

        $this->filters['upper'] = static fn(mixed $v): string => \mb_strtoupper((string) $v);

        $this->filters['lower'] = static fn(mixed $v): string => \mb_strtolower((string) $v);

        $this->filters['capitalize'] = static function (mixed $v): string {
            $s = (string) $v;
            if ($s === '') {
                return '';
            }
            return \mb_strtoupper(\mb_substr($s, 0, 1)) . \mb_strtolower(\mb_substr($s, 1));
        };

        $this->filters['title'] = static fn(mixed $v): string =>
            \mb_convert_case((string) $v, \MB_CASE_TITLE);

        $this->filters['nl2br'] = static fn(mixed $v): string =>
            \nl2br((string) $v);

        $this->filters['replace'] = static fn(mixed $v, string $search, string $replace = ''): string =>
            \str_replace($search, $replace, (string) $v);

        $this->filters['split'] = static fn(mixed $v, string $delimiter, int $limit = \PHP_INT_MAX): array =>
            \explode($delimiter, (string) $v, $limit);

        $this->filters['join'] = static fn(mixed $v, string $glue = ''): string =>
            \implode($glue, (array) $v);

        $this->filters['truncate'] = static function (mixed $v, int $length, string $ellipsis = '\u{2026}'): string {
            $s = (string) $v;
            return \mb_strlen($s) <= $length ? $s : \mb_substr($s, 0, $length) . $ellipsis;
        };

        // ── Numbers ────────────────────────────────────────────────────────

        $this->filters['number'] = static fn(mixed $v, int $decimals = 2): string => \number_format((float) $v, $decimals);

        $this->filters['format'] = static fn(mixed $v, mixed ...$args): string => \sprintf((string) $v, ...$args);

        $this->filters['abs'] = static fn(mixed $v): int|float => \abs($v + 0);

        $this->filters['round'] = static fn(mixed $v, int $precision = 0): float => \round((float) $v, $precision);

        // ── Dates ──────────────────────────────────────────────────────────

        $this->filters['date'] = static function (mixed $v, string $format = 'Y-m-d'): string {
            $timestamp = \is_int($v) ? $v : (int) \strtotime((string) $v);
            return \date($format, $timestamp);
        };

        $this->filters['format_datetime'] = static function (mixed $v, string $dateStyle = 'medium', string $timeStyle = 'medium', ?string $locale = null, ?string $timezone = null): string {
            $timestamp = \is_int($v) ? $v : (int) \strtotime((string) $v);

            $dt = new \DateTime("@$timestamp");
            $dt->setTimezone(new \DateTimeZone($timezone ?? date_default_timezone_get()));

            static $map = [
            'none' => \IntlDateFormatter::NONE,
            'short' => \IntlDateFormatter::SHORT,
            'medium' => \IntlDateFormatter::MEDIUM,
            'long' => \IntlDateFormatter::LONG,
            'full' => \IntlDateFormatter::FULL,
            ];

            $fmt = new \IntlDateFormatter(
                $locale ?? \Locale::getDefault(),
                $map[$dateStyle] ?? $dateStyle,
                $map[$timeStyle] ?? $timeStyle,
                $dt->getTimezone()->getName()
            );

            return $fmt->format($dt);
        };

        $this->filters['date_modify'] = static function (mixed $v, string $modifier): int {
            $timestamp = \is_int($v) ? $v : (int) \strtotime((string) $v);
            return (int) (new \DateTimeImmutable('@' . $timestamp))->modify($modifier)->getTimestamp();
        };

        // ── Arrays ─────────────────────────────────────────────────────────

        $this->filters['first'] = static function (mixed $v): mixed {
            if (\is_array($v)) {
                if ($v === []) {
                    return null;
                }
                return \array_values($v)[0];
            }
            $s = (string) $v;
            return $s === '' ? '' : \mb_substr($s, 0, 1);
        };

        $this->filters['last'] = static function (mixed $v): mixed {
            if (\is_array($v)) {
                if ($v === []) {
                    return null;
                }
                $vals = \array_values($v);
                return $vals[\count($vals) - 1];
            }
            $s = (string) $v;
            return $s === '' ? '' : \mb_substr($s, -1);
        };

        $this->filters['keys'] = static fn(mixed $v): array =>
            \is_array($v) ? \array_keys($v) : [];

        $this->filters['merge'] = static fn(mixed $v, array $other = []): array =>
            \array_merge((array) $v, $other);

        $this->filters['sort'] = static function (mixed $v): array {
            $arr = (array) $v;
            \sort($arr);
            return $arr;
        };

        $this->filters['reverse'] = static function (mixed $v): array|string {
            if (\is_array($v)) {
                return \array_reverse($v);
            }
            $chars = \preg_split('//u', (string) $v, -1, \PREG_SPLIT_NO_EMPTY);
            return \implode('', \array_reverse($chars ?: []));
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

        $this->filters['data_uri'] = static fn(mixed $v, string $mime = 'application/octet-stream'): string =>
            'data:' . $mime . ';base64,' . \base64_encode((string) $v);

        $this->filters['url_encode'] = static fn(mixed $v): string =>
            \rawurlencode((string) $v);

        $this->filters['striptags'] = static fn(mixed $v, string $allowedTags = ''): string => \strip_tags((string) $v, $allowedTags);

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

        $this->filters['json'] = static fn(mixed $v): string|bool =>
            \json_encode(
                $v,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
    }

    // -------------------------------------------------------------------------
    // Object → array casting
    // -------------------------------------------------------------------------

    /**
     * Recursively cast values to arrays so templates never receive live
     * objects and cannot call methods.
     *
     * Precedence:
     * 1. JsonSerializable → jsonSerialize() then recurse
     * 2. Objects with toArray() → toArray() then recurse
     * 3. Other objects → get_object_vars() then recurse
     * 4. Arrays → recurse element by element
     * 5. Scalars / null → pass through
     */
    public static function castToArray(mixed $value): mixed
    {
        if (\is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = self::castToArray($v);
            }
            return $result;
        }

        if ($value instanceof \JsonSerializable) {
            $data = $value->jsonSerialize();
            return self::castToArray((array) $data);
        }

        if (\is_object($value)) {
            if (method_exists($value, 'toArray')) {
                return self::castToArray($value->toArray());
            }
            return self::castToArray(get_object_vars($value));
        }

        return $value;
    }
}
