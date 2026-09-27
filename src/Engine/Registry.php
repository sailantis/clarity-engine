<?php
namespace Clarity\Engine;

use Clarity\ClarityException;
use Countable;
use Stringable;

/**
 * Registry of named callables for the Clarity template engine.
 *
 * The registry answers THREE independent questions, each with its own table:
 *
 *   - {@see $inlineFilters} — HOW does the name compile? (a `php` codegen
 *     template, or nothing)
 *   - {@see $filters}       — IS the name pipeable? (`value |> name`)
 *   - {@see $callables}     — what do we invoke at RUNTIME? (the `$__c_fn` table)
 *
 * A name is reachable through the pipe operator (`|>`) and/or through call
 * syntax `name(...)`; a single name may be both, and the dispatch is chosen by
 * SYNTAX, not by runtime type. Whether a name may be piped is a COMPILE-TIME
 * decision, so it lives in data (`$filters` + the inline templates) and the
 * runtime never re-checks it.
 *
 * User code registers filters via {@see addFilter()}, functions via
 * {@see addFunction()}, and compiled filter templates via
 * {@see addInlineFilter()}.
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
 * - `sprintf(...$args)`         : sprintf-style string formatting (alias: `format`)
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
 *   (DateTimeInterface values reach this filter as ISO-8601 strings, because
 *   castToArray() converts them on the way in)
 * - `date_modify($modifier)`    : Apply date modifier (e.g. '+1 day'), return Unix timestamp
 *
 * **Arrays & Collections**
 * - `first`                     : Get first element (works on arrays and strings)
 * - `last`                      : Get last element (works on arrays and strings)
 * - `keys`                      : Get array keys
 * - `values`                    : Get array values
 * - `length` (alias: `len`)     : Count elements (arrays) or string length (mb_strlen)
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
 * - `filter [lambda|filterRef]` : Keep elements matching condition (returns a new array)
 *   Usage: `{{ items |> filter(i => i.active) }}`
 *   Note: `array_filter` PRESERVES keys — it does not reindex. Follow with
 *   `|> values` if you need a list.
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
 * Dynamic variable access is spelled `${expr}` / `$$name` in the template
 * syntax (see docs); it is not a filter.
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
    /**
     * Functions blocked by default when the engine runs in open mode (sandbox
     * disabled).
     *
     * EMPTY on purpose: open mode means "the full power of PHP", so the engine
     * does not smuggle a second, weaker sandbox into it.  A fixed subset of
     * "sinks" could never be a security boundary anyway -- hundreds of ordinary
     * functions read the environment, write files or spawn processes -- and a
     * list that silently blocks `exec` while allowing `proc_open` reads as
     * protection that the switch has already declined to give.  The switch is
     * the security decision; this constant exists only so an application can
     * still add its own guardrails on top of it:
     *
     *     $engine->setDeniedFunctions(['exec', 'system']);
     *
     * What remains out of reach in both modes is the engine's own render-frame
     * namespace: a template may not BIND a `__c_`-prefixed name (it would swap an
     * internal for the rest of the render), and `$$name` variable-variable
     * expansion is compile-time rejected while the sandbox is enabled.  Open mode
     * lifts the latter — a dynamic dereference there is an ordinary local lookup,
     * which is strictly weaker than the literal `$_SERVER` spelling open mode
     * already permits.
     *
     * @var array<string, true>
     */
    public const DEFAULT_DENIED_FUNCTIONS = [];

    private mixed $includeRenderer;

    /** @var array<string, callable> keyword → handler */
    private array $directiveHandlers = [];

    /**
     * INLINE filter templates, keyed by name — the codegen half of the registry.
     *
     * Every record here answers exactly one question: *how does this name
     * compile?* A name in this table is both pipeable and callable, because the
     * same `php` template backs both forms (only the slot assignment differs),
     * so its presence is itself the declaration.
     *
     * This table is deliberately named after the public API that manages it
     * ({@see addInlineFilter()}, {@see hasInlineFilter()}, {@see getInlineFilter()}).
     * Names that must be DISPATCHED at runtime never appear here; they live in
     * {@see $callables} instead.
     *
     * Record schema
     * -------------
     *   php        PHP expression template. `{1}` is the piped value (or the
     *              first argument in call form), `{2}`, `{3}`, … are the
     *              declared `params`. Compiles to inline PHP: no runtime
     *              dispatch, no closure allocation.
     *   params     Ordered parameter names, in CALL order, after the value.
     *   defaults   paramName → PHP expression used when the argument is omitted.
     *   variadic   true for `sprintf`-style filters taking a trailing arg list.
     *   valueParam Name of the parameter that receives the piped value in the
     *              filter form. Absent means slot `{1}` (the value leads).
     *
     * Aliases (`format` = `sprintf`, `e`/`esc` = `escape`) are separate keys
     * that point at the same definition. The `raw` filter, `dump()`/`dd()`
     * context injection, and the `context()` / `include()` special forms stay
     * compiler-intercepted; they are not data here.
     *
     * Populated by {@see registerBuiltins()} in the constructor (closures are
     * not valid property defaults, so the literal lives there).
     *
     * @var array<string, array{
     *   php: string,
     *   params?: list<string>,
     *   defaults?: array<string, string>,
     *   variadic?: bool,
     *   valueParam?: string
     * }>
     */
    private array $inlineFilters = [];

    /**
     * Names that are PIPEABLE (`value |> name`) without an inline template.
     *
     * These are the runtime-callable filters: `map`, `slug`, `keys`, … They have
     * no `php` codegen record, so filterability cannot be derived from
     * {@see $inlineFilters} — it is declared here, as a plain set, and the
     * runtime table supplies the implementation.
     *
     * The separation is deliberate: a name's reachability under the pipe is a
     * compile-time fact, while the callable that implements it is runtime data.
     * Keeping them apart makes the failure mode that used to exist here — a name
     * marked filterable with nothing to call — structurally visible: it would be
     * a key in this table with no entry in {@see $callables}, which
     * {@see hasFilter()} alone cannot hide because the runtime entry is the
     * whole point of being listed.
     *
     * A name here is NOT callable under call syntax (`slug(x)` is a compile
     * error): these filters exist only through the pipe. Names with an inline
     * template are implicitly both, and are therefore not listed here.
     *
     * @var array<string, true>
     */
    private array $filters = [];

    /**
     * Runtime callables, keyed by name — the `$__c_fn` table given to compiled
     * templates.
     *
     * A name lands here when it must be DISPATCHED at runtime (a user
     * `addFilter`/`addFunction`, a built-in closure, a module-provided
     * callable). Names that compile to inline PHP never appear here: a
     * `php`-templated filter is codegen, with no runtime callable to store.
     *
     * This is a SOURCE table, not a derived cache. Writing the callable here
     * instead of inside a compile-time record means the hot path needs no
     * extraction step at all — the engine simply reads this array. The tables
     * are intentionally not synced: {@see $inlineFilters} holds codegen, this
     * one holds behaviour.
     *
     * @var array<string, callable>
     */
    private array $callables = [];

    /**
     * Non-callable service objects stored under a named key and passed into
     * compiled templates via the `$__c_sv` array.
     *
     * Modules use this to inject shared state (e.g. a locale stack) that inline
     * filter PHP templates can access as `$__c_sv['__key']->method()`.
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
        $this->registerBuiltins();
    }

    /**
     * Populate {@see $inlineFilters}, {@see $filters} and {@see $callables} with
     * the built-ins.
     *
     * A name appears ONCE per question it answers. A `php` template makes a name
     * both pipeable and callable; a runtime callable makes it callable and — if
     * it is also listed in {@see $filters} — pipeable.
     */
    private function registerBuiltins(): void
    {
        // ── Inline filter templates (compiled to PHP, zero runtime dispatch) ──
        //
        // `{1}` is the piped value; `{2}`, `{3}`, … are the declared `params`.
        // Entries whose value param does not lead declare `valueParam` (Phase 2).
        $this->inlineFilters += [
            'abs' => [
                'php' => '\abs({1} + 0)',
            ],
            'capitalize' => [
                'php' => '($__c_tmp = (string){1}) === "" ? "" : \mb_strtoupper(\mb_substr($__c_tmp, 0, 1)) . \mb_strtolower(\mb_substr($__c_tmp, 1))',
            ],
            'ceil' => [
                'php' => '\ceil((float){1})',
            ],
            'data_uri' => [
                'php'      => '"data:" . {2} . ";base64," . \base64_encode((string){1})',
                'params'   => ['mime'],
                'defaults' => ['mime' => "'application/octet-stream'"],
            ],
            'date' => [
                // Params-led template (valueParam): slot order = params order, so
                // `{1}` is the format and `{2}` is the date value. `date` mirrors
                // PHP's own `date($format, $timestamp)` signature.
                'php'        => '\date({1}, ($__c_tmp = {2}) instanceof \DateTimeInterface ? $__c_tmp->getTimestamp() : (\is_int($__c_tmp) ? $__c_tmp : (int) \strtotime((string) $__c_tmp)))',
                'params'     => ['format', 'date'],
                'defaults'   => ['format' => "'Y-m-d'", 'date' => '\\time()'],
                'valueParam' => 'date',
            ],
            'date_modify' => [
                'php'    => '(int) ((new \DateTimeImmutable("@" . (($__c_tmp = {1}) instanceof \DateTimeInterface ? $__c_tmp->getTimestamp() : (\is_int($__c_tmp) ? $__c_tmp : (int) \strtotime((string) $__c_tmp)))))->modify({2})->getTimestamp())',
                'params' => ['modifier'],
            ],
            'default' => [
                'php'      => '({1} ?? {2})',
                'params'   => ['fallback'],
                'defaults' => ['fallback' => 'null'],
            ],
            'empty' => [
                'php'      => '({1} ?: {2})',
                'params'   => ['fallback'],
                'defaults' => ['fallback' => '""'],
            ],
            // escape family — `e` and `esc` are aliases of `escape`.
            'e' => [
                'php' => '\htmlspecialchars((string){1}, \ENT_QUOTES | \ENT_SUBSTITUTE, "UTF-8")',
            ],
            'esc' => [
                'php' => '\htmlspecialchars((string){1}, \ENT_QUOTES | \ENT_SUBSTITUTE, "UTF-8")',
            ],
            'escape' => [
                'php' => '\htmlspecialchars((string){1}, \ENT_QUOTES | \ENT_SUBSTITUTE, "UTF-8")',
            ],
            'floor' => [
                'php' => '\floor((float){1})',
            ],
            'join' => [
                // Params-led (valueParam='array'): `{1}` is the glue, `{2}` is the
                // array. Mirrors PHP `implode($separator, $array)`. The `array`
                // param has NO default, so `join(items)` fails loudly instead of
                // silently implying an empty separator.
                'php'        => '\implode({1}, (array){2})',
                'params'     => ['glue', 'array'],
                'defaults'   => ['glue' => "''"],
                'valueParam' => 'array',
            ],
            // `json` is BOTH an inline filter and a registered callable — the
            // one name that legitimately carries a `php` template AND a
            // `callable` (defined with the callables below).
            'json' => [
                // JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
                'php' => '\json_encode({1}, 0x200340)',
            ],
            'lower' => [
                'php' => '\mb_strtolower((string){1})',
            ],
            'merge' => [
                'php'      => '[...(array){1}, ...(array){2}]',
                'params'   => ['other'],
                'defaults' => ['other' => '[]'],
            ],
            'nl2br' => [
                'php' => '\nl2br((string){1})',
            ],
            'number' => [
                'php'      => '\number_format((float){1}, {2})',
                'params'   => ['decimals'],
                'defaults' => ['decimals' => '2'],
            ],
            // `raw` is handled specially by the compiler to disable auto-escaping; it is not a real filter.
            'replace' => [
                'php'      => '\str_replace({2}, {3}, (string){1})',
                'params'   => ['search', 'replace'],
                'defaults' => ['replace' => "''"],
            ],
            'reverse' => [
                'php' => '(\is_array($__c_tmp = {1}) ? \array_reverse($__c_tmp) : \implode("", \array_reverse(\preg_split("//u", (string) $__c_tmp, -1, \PREG_SPLIT_NO_EMPTY) ?: [])))',
            ],
            'round' => [
                'php'      => '\round((float){1}, {2})',
                'params'   => ['precision'],
                'defaults' => ['precision' => '0'],
            ],
            'slice' => [
                'php'      => '(\is_array($__c_tmp = {1}) ? \array_slice($__c_tmp, {2}, {3}) : \mb_substr((string) $__c_tmp, {2}, {3}))',
                'params'   => ['start', 'length'],
                'defaults' => ['length' => 'null'],
            ],
            'split' => [
                'php'      => '\explode({2}, (string){1}, {3})',
                'params'   => ['delimiter', 'limit'],
                'defaults' => ['limit' => '\\PHP_INT_MAX'],
            ],
            'sprintf' => [
                'php'      => '\sprintf',
                'params'   => ['args'],
                'variadic' => true,
            ],
            'striptags' => [
                'php'      => '\strip_tags((string) {1}, {2})',
                'params'   => ['allowedTags'],
                'defaults' => ['allowedTags' => "''"],
            ],
            'title' => [
                'php' => '\mb_convert_case((string){1}, \MB_CASE_TITLE)',
            ],
            'trim' => [
                'php' => '\trim((string){1})',
            ],
            'truncate' => [
                'php'    => '(\mb_strlen($__c_tmp = ((string){1})) <= {2} ? $__c_tmp : \mb_substr($__c_tmp, 0, {2}) . {3})',
                'params' => ['length', 'ellipsis'],
                // Double-quoted so PHP interprets `\u{2026}` as the actual
                // ellipsis character "…" at runtime. The value is substituted
                // into the emitted PHP verbatim, so it must BE valid PHP code:
                // a single-quoted literal would pass `\u{2026}` through as seven
                // literal characters.
                'defaults' => ['ellipsis' => '"\u{2026}"'],
            ],
            'unicode' => [
                'php'      => 'new \Clarity\Engine\UnicodeString((string){1}, {2}, {3})',
                'params'   => ['start', 'length'],
                'defaults' => ['start' => '0', 'length' => 'null'],
            ],
            'upper' => [
                'php' => '\mb_strtoupper((string){1})',
            ],
            'url_encode' => [
                'php' => '\rawurlencode((string) {1})',
            ],
        ];

        // `format` is an ALIAS of `sprintf`, kept for Twig parity.
        $this->inlineFilters['format'] = $this->inlineFilters['sprintf'];

        // ── Runtime-callable filters (pipeable via $filters, dispatched via $callables) ──

        $this->filters['format_datetime']   = true;
        $this->callables['format_datetime'] = static function (mixed $v, string $dateStyle = 'medium', string $timeStyle = 'medium', ?string $locale = null, ?string $timezone = null): string {
            if (\is_int($v)) {
                $timestamp = $v;
            } else {
                $ts = \strtotime((string) $v);
                if ($ts === false) {
                    return '';
                }
                $timestamp = $ts;
            }

            $dt = new \DateTime("@$timestamp");
            $dt->setTimezone(new \DateTimeZone($timezone ?? \date_default_timezone_get()));

            static $match = [
                'none'   => \IntlDateFormatter::NONE,
                'short'  => \IntlDateFormatter::SHORT,
                'medium' => \IntlDateFormatter::MEDIUM,
                'long'   => \IntlDateFormatter::LONG,
                'full'   => \IntlDateFormatter::FULL,
            ];

            $fmt = new \IntlDateFormatter(
                $locale ?? \Locale::getDefault(),
                $match[$dateStyle] ?? $dateStyle,
                $match[$timeStyle] ?? $timeStyle,
                $dt->getTimezone()->getName()
            );

            if (!$fmt) {
                return '';
            }

            $out = $fmt->format($dt);
            return $out === false ? '' : $out;
        };

        $this->filters['sort']   = true;
        $this->callables['sort'] = static function (mixed $v): array {
            $arr = (array) $v;
            \sort($arr);
            return $arr;
        };

        $this->filters['shuffle']   = true;
        $this->callables['shuffle'] = static function (mixed $v): array {
            $arr = (array) $v;
            \shuffle($arr);
            return $arr;
        };

        $this->filters['batch']   = true;
        $this->callables['batch'] = static function (mixed $v, int $size, mixed $fill = null): array {
            $size   = \max(1, $size);
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
        $this->filters['map']   = true;
        $this->callables['map'] = static fn(mixed $v, callable $fn): array => \array_map($fn, (array) $v);

        $this->filters['filter']   = true;
        $this->callables['filter'] = static fn(mixed $v, ?callable $fn = null): array =>
            \array_filter((array) $v, $fn);

        $this->filters['reduce']   = true;
        $this->callables['reduce'] = static fn(mixed $v, callable $fn, mixed $initial = null): mixed =>
            \array_reduce((array) $v, $fn, $initial);

        $this->filters['slug']   = true;
        $this->callables['slug'] = static function (mixed $v, string $separator = '-'): string {
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

        // ── Registered functions (call syntax `name(...)`) ──
        //
        // Names that are BOTH filter and function (`json`, `keys`, `values`,
        // `first`, `last`) appear in $filters AND $callables. The rest are
        // call-only: their first argument is not a piped value (`context`,
        // `include`, `dump`, `dd`), so they are absent from $filters and a pipe
        // like `{{ x |> dump }}` is rejected at compile time.

        $this->callables['context'] = static fn(array $vars = []): array => $vars;

        $this->callables['include'] = function (string $view, array $context = []): string {
            if ($this->includeRenderer === null) {
                throw new \LogicException('The built-in include() function is not available in this Clarity runtime.');
            }
            return ($this->includeRenderer)($view, $context);
        };

        $this->callables['json'] = static function (mixed ...$args): string {
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
        $this->callables['dump'] = function (string $ctx, mixed ...$args): string {
            if ($this->dumpHandler !== null) {
                return ($this->dumpHandler)($ctx, ...$args);
            }
            $out = '<pre style="background:#f7f7f9;padding:8px;border:1px solid #ddd;font-family:monospace;font-size:13px;overflow:auto">';
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
        $this->callables['dd'] = function (string $ctx, mixed ...$args): never {
            if ($this->ddHandler !== null) {
                ($this->ddHandler)($ctx, ...$args);
                // ddHandler must exit(); this is a safety net:
            }
            if (\PHP_SAPI !== 'cli' && \PHP_SAPI !== 'phpdbg' && !\extension_loaded('xdebug')) {
                \header('Content-Type: text/plain; charset=utf-8');
            }
            foreach ($args as $v) {
                \var_dump($v);
            }
            exit(1);
        };

        $this->filters['keys']   = true;
        $this->callables['keys'] = static fn(mixed $v): array => \array_keys(self::fnToArray($v));

        $this->filters['values']   = true;
        $this->callables['values'] = static fn(mixed $v): array => \array_values(self::fnToArray($v));

        $this->filters['first']   = true;
        $this->callables['first'] = static function (mixed $v): mixed {
            $arr = self::fnToArray($v);
            return $arr[array_key_first($arr)] ?? null;
        };

        $this->filters['last']   = true;
        $this->callables['last'] = static function (mixed $v): mixed {
            $arr = self::fnToArray($v);
            return $arr[array_key_last($arr)] ?? null;
        };

        $this->filters['length']   = true;
        $this->callables['length'] = static fn(mixed $v): int => self::fnLen($v);

        // `len` is an ALIAS of `length` — the same callable instance, so the two
        // can never drift. Nothing else needs to be copied: `len` is filterable
        // because it is listed in $filters, exactly like `length`.
        $this->filters['len']   = true;
        $this->callables['len'] = $this->callables['length'];
    }

    /**
     * Check whether a named filter is registered — i.e. may be used with `|>`.
     *
     * True when the name has a runtime-backed filter declaration in
     * {@see $filters}, or an inline template in {@see $inlineFilters} (which is
     * pipeable by construction). A runtime callable alone is NOT enough:
     * `context`, `include`, `dump` and `dd` are call-only builtins whose first
     * argument is not a piped value, so they must not become filterable just by
     * sharing the callable table.
     */
    public function hasFilter(string $name): bool
    {
        return isset($this->filters[$name]) || isset($this->inlineFilters[$name]);
    }

    /**
     * Register a callable as a user-defined filter.
     *
     * The callable receives ($value, ...$args). It is reachable in the filter
     * form (`value |> name`); call syntax is not enabled unless the name is also
     * registered as a function.
     *
     * @param string   $name Filter name used in templates (e.g. 'currency').
     * @param callable $fn   Callable receiving ($value, ...$args).
     * @return static
     */
    public function addFilter(string $name, callable $fn): static
    {
        $this->filters[$name]   = true;
        $this->callables[$name] = $fn;
        return $this;
    }

    /**
     * Register an additional inline filter that is compiled directly into the
     * generated PHP (zero runtime call overhead).
     *
     * The definition must follow the same structure as the built-in records:
     *   'php'        – PHP expression template with {1} for the piped value and
     *                  {2}, {3}, … for each additional parameter.
     *   'params'     – (optional) ordered list of parameter names.
     *   'defaults'   – (optional) map of paramName → PHP default expression.
     *   'variadic'   – (optional) true for variadic filters like 'sprintf'.
     *   'valueParam' – (optional) parameter that receives the piped value.
     *
     * Registering an inline template makes the name BOTH pipeable and callable
     * (the same template backs both forms), so nothing else has to be declared.
     *
     * @param string $name       Filter name used in templates.
     * @param array{php: string, params?: string[], defaults?: array<string, string>, variadic?: bool, valueParam?: string} $definition
     */
    public function addInlineFilter(string $name, array $definition): void
    {
        $this->inlineFilters[$name] = \array_replace($this->inlineFilters[$name] ?? [], $definition);
    }

    /**
     * Check whether a named inline (compile-time) filter is registered.
     */
    public function hasInlineFilter(string $name): bool
    {
        return isset($this->inlineFilters[$name]);
    }

    /**
     * Get the compile-time definition of a named inline filter.
     *
     * Only `php`-templated names are returned; a purely callable filter has no
     * codegen template and yields null.
     */
    public function getInlineFilter(string $name): ?array
    {
        return $this->inlineFilters[$name] ?? null;
    }

    /**
     * Store a non-callable service object under a named key so that compiled
     * template render bodies can access it via `$__c_sv['key']->method()`.
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
     * Get every registered callable as a name → callable map.
     *
     * This is the ONE runtime table: compiled templates receive it as
     * `$__c_fn` and dispatch BOTH the pipe form and the call form through it
     * (`$__c_fn['slug'](…)`). There is no runtime filter table, because whether
     * a name may be piped is a *compile-time* decision ({@see $filters} +
     * {@see $inlineFilters}) enforced by the compiler — the runtime does not
     * need to re-check it.
     *
     * Every registration that must be dispatched at runtime is present
     * regardless of filterability, so `context`, `include`, `dump` and `dd`
     * (call-only) and `json` (both forms) are all included. There is no
     * filtering or rebuilding step: {@see $callables} IS the table, so this
     * returns it directly and costs nothing.
     *
     * @return array<string, callable>
     */
    public function allCallables(): array
    {
        return $this->callables;
    }

    /**
     * Register a user-defined function.
     *
     * The callable receives any positional arguments. The name becomes callable
     * in templates via `name(...)`.
     *
     * @param string   $name Function name used in templates (e.g. 'greet').
     * @param callable $fn   Callable receiving any positional arguments.
     * @return static
     */
    public function addFunction(string $name, callable $fn): static
    {
        $this->callables[$name] = $fn;
        return $this;
    }

    /**
     * Check whether a named function (call syntax) is registered.
     *
     * Any name with something to call — a runtime callable, or an inline `php`
     * template (which compiles to the call itself) — is callable.
     */
    public function hasFunction(string $name): bool
    {
        return $this->hasCallable($name);
    }

    /**
     * Check whether a name can be invoked under call syntax `name(...)`.
     */
    public function hasCallable(string $name): bool
    {
        return isset($this->callables[$name]) || isset($this->inlineFilters[$name]);
    }

    /**
     * Resolve the PHP callable for a name at call sites, or null when the name
     * is inline-only (the caller then derives the call inline from `php`).
     */
    public function getCallable(string $name): ?callable
    {
        return $this->callables[$name] ?? null;
    }

    /**
     * Registry of custom directive handlers for the Clarity compiler.
     *
     * Modules register directive keywords (e.g. `with_locale`) whose compilation is delegated to user-supplied callables instead of being handled by the built-in match table in {@see Compiler::compileDirective()}.
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
     * $engine->addDirective('with_locale', function(string $rest, string $path, int $line, callable $processExpr): string {
     *     $param = $processExpr(trim($rest));
     *     return "\$__c_sv['locale']->push({$param});";
     * });
     * $engine->addDirective('endwith_locale', fn(...) => "\$__c_sv['locale']->pop();");
     * ```
     *
     * @param string   $keyword  Directive keyword (lowercase, e.g. 'with_locale').
     * @param callable $handler  See class docblock for expected signature.
     */
    public function addDirective(string $keyword, callable $handler): static
    {
        $this->directiveHandlers[$keyword] = $handler;
        return $this;
    }

    /**
     * Check whether a handler is registered for the given keyword.
     */
    public function hasDirective(string $keyword): bool
    {
        return isset($this->directiveHandlers[$keyword]);
    }

    /**
     * Invoke the registered handler for $keyword and return compiled PHP.
     *
     * @param string   $keyword     Directive keyword.
     * @param string   $rest        Raw text after the keyword inside {% … %}.
     * @param string   $sourcePath  Source file path (for error messages).
     * @param int      $tplLine     Template line number (for error messages).
     * @param callable $processExpr fn(string $clarityExpr): string converter.
     * @param Compiler $compiler    The compiler invoking this directive.
     * @return string Compiled PHP statement(s).
     * @throws ClarityException If the handler itself throws one.
     */
    public function compileDirective(
        string $keyword,
        string $rest,
        string $sourcePath,
        int $tplLine,
        callable $processExpr,
        Compiler $compiler,
    ): string {
        return ($this->directiveHandlers[$keyword])(
            $rest,
            $sourcePath,
            $tplLine,
            $processExpr,
            $compiler,
        );
    }

    private static function fnToArray(mixed $v): array
    {
        return match (true) {
            \is_array($v)              => $v,
            $v instanceof \Traversable => \iterator_to_array($v, true),
            \is_object($v)             => \get_object_vars($v),
            default                    => []
        };
    }

    private static function fnLen(mixed $v): int
    {
        return match (true) {
            \is_string($v)           => \mb_strlen($v),
            \is_array($v)            => \count($v),
            $v instanceof Stringable => \mb_strlen((string) $v),
            $v instanceof Countable  => \count($v),
            default                  => 1
        };
    }

}