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
 *   (DateTimeInterface values reach this filter directly; the filter reads
 *   their timestamp without converting them)
 * - `date_modify($modifier, $format='c')` : Apply a date modifier (e.g. '+1 day'),
 *                                          return the result formatted with `$format`
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
 * - `vars()`: Returns current template variables array
 * - `context()`: Deprecated alias of `vars()`
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
     * Functions blocked by default.
     *
     * EMPTY on purpose, and nothing in the engine reads it any more: what a
     * template may call is decided by a {@see \Clarity\Engine\Policy}, whose
     * `functions` allowlist is the same idea stated positively and works in every
     * policy rather than only where the sandbox is already off.
     *
     * It is kept as a named constant because applications set it and because
     * `Policy::denyFunctions()` is the replacement for the guardrails it used to
     * describe:
     *
     *     $engine->setPolicy(Policy::unrestricted()->denyFunctions('exec', 'system'));
     *
     * What remains out of reach in every policy is the engine's own render-frame
     * namespace: a template may not BIND a `__c_`-prefixed name (it would swap an
     * internal for the rest of the render).  `$$name` / `${expr}` variable-variable
     * expansion is an ordinary scope lookup in every policy (it resolves against
     * the render scope and loop locals, so it can reach neither a superglobal nor
     * an engine internal), and a policy's dynamic dereference is likewise a plain
     * local lookup — strictly weaker than the literal `$_SERVER` spelling a
     * `superglobals` grant already permits.
     *
     * @var array<string, true>
     */
    public const DEFAULT_DENIED_FUNCTIONS = [];

    private mixed $includeRenderer;

    /** @var array<string, callable> keyword → handler */
    private array $directiveHandlers = [];

    /**
     * Openers that declared a construct schema: opener keyword → member keyword → role.
     *
     * The role is `'required'` for the one closing tag and `'allowed'` for an
     * optional branch tag.  This is the SINGLE source of truth for pairing: the
     * compiler reads the reverse index derived from it to decide whether a tag
     * opens, closes, or branches.
     *
     * @var array<string, array<string, string>>
     */
    private array $directivePairings = [];

    /**
     * Member tags that asserted their owner: member keyword → owner keyword.
     *
     * This is an ASSERTION, not a second declaration: it never changes how a tag
     * compiles, it only lets a close/branch tag state which construct it belongs
     * to.  The consistency pass fails when the claim disagrees with the owner's
     * declaration — which catches "registered the close but forgot the opener",
     * the one authoring bug the opener-only metadata cannot see.
     *
     * @var array<string, string>
     */
    private array $directiveOwners = [];

    /**
     * Reverse index of {@see $directivePairings}: member keyword → opener keyword.
     *
     * Rebuilt by {@see assertPairingConsistency()} at the start of every compile,
     * because registration order is not fixed (an opener may be registered after
     * one of its members).
     *
     * @var array<string, string>
     */
    private array $directiveMemberToOpener = [];

    /**
     * Keywords the compiler dispatches itself, before the registry is consulted:
     * `compileBlock()`'s match arms.  A custom directive may not shadow them, or
     * the tag would be captured by the built-in arm and never reach its handler.
     */
    private const BUILTIN_DIRECTIVE_KEYWORDS = [
        'if', 'elseif', 'else', 'endif',
        'for', 'endfor', 'set',
        'macro', 'call',
        'extends', 'block', 'endblock', 'include',
        'php', 'parent',
    ];

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
     * context injection, and the `vars()` / `include()` special forms stay
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
     * Optional closure that handles dump() output once debug has been enabled.
     * Receives (string $ctx, mixed ...$args): string.
     */
    private ?\Closure $dumpHandler = null;

    /**
     * Optional closure that handles dd() output.
     * Receives (string $ctx, mixed ...$args): never.
     */
    private ?\Closure $ddHandler = null;

    /**
     * Install context-aware dump/dd handlers, produced by the engine's debug
     * runtime.  Called internally — not part of the public engine API.
     *
     * The registry does NOT ship a default for either name.  Debug output is
     * engine state (it owns the renderers and the DumpOptions), so with no
     * handler installed `dump()` is a no-op and `dd()` is an explicit error,
     * rather than a second, renderer-less formatter whose output would ignore
     * masking.  See {@see \Clarity\Debug\DebugRuntime}.
     *
     * Passing null restores that neutral state; it is what disabling debug does.
     */
    public function setDumpHandler(?\Closure $fn): void
    {
        $this->dumpHandler = $fn;
    }

    public function setDdHandler(?\Closure $fn): void
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
                'php' => '($__c_tmp = {1}) === "" ? "" : \mb_strtoupper(\mb_substr($__c_tmp, 0, 1)) . \mb_strtolower(\mb_substr($__c_tmp, 1))',
            ],
            'ceil' => [
                'php' => '\ceil({1})',
            ],
            'data_uri' => [
                'php'      => '"data:" . {2} . ";base64," . \base64_encode({1})',
                'params'   => ['mime'],
                'defaults' => ['mime' => "'application/octet-stream'"],
            ],
            'date' => [
                'php'        => '\date({1}, ($__c_tmp = {2}) instanceof \DateTimeInterface ? $__c_tmp->getTimestamp() : (\is_int($__c_tmp) ? $__c_tmp : \strtotime($__c_tmp)))',
                'params'     => ['format', 'date'],
                'defaults'   => ['format' => "'Y-m-d'", 'date' => '\\time()'],
                'valueParam' => 'date',
            ],
            'date_modify' => [
                'php'      => '(new \DateTimeImmutable("@" . (($__c_tmp = {1}) instanceof \DateTimeInterface ? $__c_tmp->getTimestamp() : (\is_int($__c_tmp) ? $__c_tmp : \strtotime($__c_tmp)))))->modify({2})->format({3})',
                'params'   => ['modifier', 'format'],
                'defaults' => ['format' => "'c'"],
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
            'escape' => [
                'php' => '\htmlspecialchars({1}, \ENT_QUOTES | \ENT_SUBSTITUTE, "UTF-8")',
            ],
            'floor' => [
                'php' => '\floor({1})',
            ],
            'join' => [
                // Params-led (valueParam='array'): `{1}` is the glue, `{2}` is the
                // array. Mirrors PHP `implode($separator, $array)`. The `array`
                // param has NO default, so `join(items)` fails loudly instead of
                // silently implying an empty separator.
                'php'        => '\implode({1}, {2})',
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
                'php' => '\mb_strtolower({1})',
            ],
            'merge' => [
                'php'      => '[...{1}, ...{2}]',
                'params'   => ['other'],
                'defaults' => ['other' => '[]'],
            ],
            'nl2br' => [
                'php' => '\nl2br({1})',
            ],
            'number' => [
                // `number_format()` is the one built-in whose *value* parameter takes
                // neither a string nor a null, so under `strictTypes` its coercion
                // would be a type error rather than a coercion. The other built-ins
                // hand their value to a `string` parameter, where weak mode still
                // accepts an int/float — this one cannot rely on that, so it casts.
                'php'      => '\number_format((float) ({1}), {2})',
                'params'   => ['decimals'],
                'defaults' => ['decimals' => '2'],
            ],
            // `raw` is handled specially by the compiler to disable auto-escaping; it is not a real filter.
            'replace' => [
                'php'      => '\str_replace({2}, {3}, {1})',
                'params'   => ['search', 'replace'],
                'defaults' => ['replace' => "''"],
            ],
            'reverse' => [
                'php' => '(\is_array($__c_tmp = {1}) ? \array_reverse($__c_tmp) : \implode("", \array_reverse(\preg_split("//u", $__c_tmp, -1, \PREG_SPLIT_NO_EMPTY) ?: [])))',
            ],
            'round' => [
                'php'      => '\round({1}, {2})',
                'params'   => ['precision'],
                'defaults' => ['precision' => '0'],
            ],
            'slice' => [
                'php'      => '(\is_array($__c_tmp = {1}) ? \array_slice($__c_tmp, {2}, {3}) : \mb_substr($__c_tmp, {2}, {3}))',
                'params'   => ['start', 'length'],
                'defaults' => ['length' => 'null'],
            ],
            'split' => [
                'php'      => '\explode({2}, {1}, {3})',
                'params'   => ['delimiter', 'limit'],
                'defaults' => ['limit' => '\\PHP_INT_MAX'],
            ],
            'sprintf' => [
                'php'      => '\sprintf',
                'params'   => ['args'],
                'variadic' => true,
            ],
            'striptags' => [
                'php'      => '\strip_tags({1}, {2})',
                'params'   => ['allowedTags'],
                'defaults' => ['allowedTags' => "''"],
            ],
            'title' => [
                'php' => '\mb_convert_case({1}, \MB_CASE_TITLE)',
            ],
            'trim' => [
                'php' => '\trim({1})',
            ],
            'truncate' => [
                'php'    => '(\mb_strlen($__c_tmp = ({1})) <= {2} ? $__c_tmp : \mb_substr($__c_tmp, 0, {2}) . {3})',
                'params' => ['length', 'ellipsis'],
                // Double-quoted so PHP interprets `\u{2026}` as the actual
                // ellipsis character "…" at runtime. The value is substituted
                // into the emitted PHP verbatim, so it must BE valid PHP code:
                // a single-quoted literal would pass `\u{2026}` through as seven
                // literal characters.
                'defaults' => ['ellipsis' => '"\u{2026}"'],
            ],
            'upper' => [
                'php' => '\mb_strtoupper({1})',
            ],
            'url_encode' => [
                'php' => '\rawurlencode({1})',
            ],
        ];

        // `format` is an ALIAS of `sprintf`, kept for Twig parity.
        $this->inlineFilters['format'] = $this->inlineFilters['sprintf'];

        // escape family — `e` and `esc` are aliases of `escape`.
        $this->inlineFilters['esc'] = $this->inlineFilters['escape'];
        $this->inlineFilters['e']   = $this->inlineFilters['escape'];

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
        // call-only: their first argument is not a piped value (`vars`,
        // `include`, `dump`, `dd`), so they are absent from $filters and a pipe
        // like `{{ x |> dump }}` is rejected at compile time.

        // The scope snapshot. The compiler special-cases this name and emits the
        // call inline (gathering loop/macro locals that `$__c_va` does not hold);
        // the entry here exists so the name resolves, and so a hand-built
        // tokenizer without the special case still has something to call.
        $this->callables['vars'] = static fn(array $vars = []): array => $vars;

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

        // ── Debug entries ────────────────────────────────────────────────────
        // `dump` and `dd` are registered here so the names exist in the ONE
        // call model from boot; the CONTEXT-AWARE behaviour is installed by
        // {@see \Clarity\Debug\DebugRuntime} when the engine turns debug on.
        //
        // Leaving the behaviour out of the registry is deliberate: debug is
        // engine state (it owns the renderers and the DumpOptions), so a
        // formatter that could not see them would be a second implementation
        // that silently ignores masking.  There is therefore ONE `dump`, both as
        // a call `dump(x)` and as a filter step `{{ x |> dump }}` — with debug
        // off it is a no-op, and `dd()` refuses rather than dumping raw values.
        //
        // Both are context-injected, so the compile-time escape context arrives
        // as the first argument.

        // `dump($x)` evaluates its argument (side effects still happen) and
        // yields '' until the engine installs a handler.  The compiler also
        // prunes the whole call in production, so this is the belt to that
        // braces — it is what makes a quoted reference behave identically.
        $this->callables['dump'] = function (string $ctx, mixed ...$args): string {
            return $this->dumpHandler !== null ? ($this->dumpHandler)($ctx, ...$args) : '';
        };
        $this->filters['dump'] = true;

        // `dd` is a plain call — dd() never has a filter form, since it never
        // yields a value to chain.
        $this->callables['dd'] = function (string $ctx, mixed ...$args): never {
            if ($this->ddHandler !== null) {
                ($this->ddHandler)($ctx, ...$args);
            }
            throw new \LogicException(
                'dd() requires debug mode: call $engine->setDebugMode(true) before rendering.'
            );
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

        // ── Collection functions (call syntax) ──
        //
        // These have no piped-value form: their argument IS the subject
        // (`range(1, 5)`, `cycle(['a','b'], 1)`), so they are callable but not
        // filterable — the same shape as `vars()`.
        $this->callables['range'] = static function (mixed $low, mixed $high, mixed $step = 1): array {
            $step = (int) $step;
            if ($step === 0) {
                return [];
            }
            return \range((int) $low, (int) $high, $step);
        };

        $this->callables['cycle'] = static function (mixed $values, mixed $position): mixed {
            $arr = \is_array($values) ? $values : [$values];
            $n   = \count($arr);
            if ($n === 0) {
                return null;
            }
            $pos = (int) $position % $n;
            if ($pos < 0) {
                $pos += $n;
            }
            return \array_values($arr)[$pos];
        };

        // `attribute(obj, name)`: a dynamic read that respects the same access
        // model as `a.b` / `a:b` — array key for arrays, public property for
        // objects. An optional third argument supplies a fallback.
        $this->callables['attribute'] = static function (mixed $subject, mixed $name, mixed $default = null): mixed {
            $name = (string) $name;
            if (\is_array($subject)) {
                return $subject[$name] ?? $default;
            }
            if (\is_object($subject)) {
                $vars = \get_object_vars($subject);
                return $vars[$name] ?? $default;
            }
            return $default;
        };

        // ── Twig-style operator tests (call syntax) ──
        //
        // These back the infix/prefix tests the tokenizer rewrites into calls:
        //   x in y            -> in(x, y)
        //   x starts with y   -> starts_with(x, y)
        //   x is same as(y)   -> same_as(x, y)
        //   x is even         -> is_even(x)
        //
        // They are ordinary runtime callables (value in, bool out).  The three
        // tests that must TOLERATE an absent left operand — `defined`, `is null`
        // and `is empty` — are intercepted by the compiler instead, because a
        // value-passing callable cannot tell "absent" from "null"; their entries
        // below exist so `hasFunction()` answers true and the call reaches the
        // interception point.
        $this->callables['in'] = static function (mixed $needle, mixed $haystack): bool {
            if (\is_string($haystack)) {
                return \is_scalar($needle) && $needle !== '' && \str_contains($haystack, (string) $needle);
            }
            if ($haystack instanceof \Traversable) {
                $haystack = \iterator_to_array($haystack, false);
            }
            if (\is_array($haystack)) {
                // Twig's `in`: a LIST matches by value, a MAPPING by key. Both
                // are checked so `2 in [1,2]` (a value) and `'a' in ['a'=>1]`
                // (a key) each work; loose comparison keeps the string/number
                // mix a template routinely meets from failing outright.
                return \in_array($needle, $haystack, false) || \array_key_exists($needle, $haystack);
            }
            return false;
        };

        $this->callables['starts_with'] = static fn(mixed $s, mixed $prefix): bool =>
            \str_starts_with((string) $s, (string) $prefix);

        $this->callables['ends_with'] = static fn(mixed $s, mixed $suffix): bool =>
            \str_ends_with((string) $s, (string) $suffix);

        $this->callables['matches'] = static function (mixed $s, mixed $pattern): bool {
            $pattern = (string) $pattern;
            if ($pattern === '' || @\preg_match($pattern, '') === false) {
                return false;
            }
            return \preg_match($pattern, (string) $s) === 1;
        };

        $this->callables['divisible_by'] = static fn(mixed $n, mixed $d): bool =>
            (int) $d !== 0 && (int) $n % (int) $d === 0;

        $this->callables['same_as'] = static fn(mixed $a, mixed $b): bool => $a === $b;

        $this->callables['is_even']  = static fn(mixed $n): bool => (int) $n % 2 === 0;
        $this->callables['is_odd']   = static fn(mixed $n): bool => (int) $n % 2 !== 0;
        $this->callables['iterable'] = static fn(mixed $v): bool =>
            \is_array($v) || $v instanceof \Traversable;

        // Intercepted in the compiler (see Tokenizer::buildDefinedTest()); the
        // bodies are a last-resort fallback for direct call syntax.
        $this->callables['defined']  = static fn(mixed $v): bool => $v !== null;
        $this->callables['is_null']  = static fn(mixed $v): bool => $v === null;
        $this->callables['is_empty'] = static fn(mixed $v): bool => empty($v);
    }

    /**
     * Check whether a named filter is registered — i.e. may be used with `|>`.
     *
     * True when the name has a runtime-backed filter declaration in
     * {@see $filters}, or an inline template in {@see $inlineFilters} (which is
     * pipeable by construction). A runtime callable alone is NOT enough:
     * `context` and `include` are call-only builtins whose first argument is not
     * a piped value, so they must not become filterable just by sharing the
     * callable table.
     *
     * `dump` is pipeable even though its callable is not value-first: it is
     * declared in {@see $filters}, and the compiler emits a pass-through probe
     * for it instead of dispatching the callable with the piped value.
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
     * regardless of filterability, so `context`, `include` and `dd` (call-only)
     * and `json`, `dump` (both forms) are all included. There is no
     * filtering or rebuilding step: {@see $callables} IS the table, so this
     * returns it directly and costs nothing.
     *
     * The engine rebinds the `dump`/`dd` entries to the debug formatter before
     * handing the table to a template — see
     * {@see \Clarity\ClarityEngine::runtimeCallables()}.
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
     * Modules register directive keywords (e.g. `with_locale`) whose compilation is delegated to user-supplied callables instead of being handled by the built-in match table in {@see \Clarity\Engine\Compiler::compileBlock()}.
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
     * Paired (block) directives
     * -------------------------
     * A directive that wraps a body is declared by its OPENER, which lists every
     * member tag and that tag's role:
     * ```php
     * $engine->addDirective('cache', $openHandler, [
     *     'endcache'  => 'required',   // the closing tag
     *     'cacheelse' => 'allowed',    // optional branch tag, at most once
     * ]);
     * $engine->addDirective('endcache',  $closeHandler);
     * $engine->addDirective('cacheelse', $branchHandler);
     * ```
     * Members need no metadata to FUNCTION: the opener's declaration is what makes
     * the compiler treat them as a close/branch tag.  A member MAY additionally
     * assert its owner, in the same `keyword => role` direction:
     * ```php
     * $engine->addDirective('endcache', $closeHandler, ['cache' => 'owner']);
     * ```
     * That assertion never changes compilation; it makes the start of every compile
     * verify that `cache` exists and does declare `endcache` (as `'required'` or
     * `'allowed'`).  It is the guard against registering a close without its opener.
     *
     * @param string        $keyword  Directive keyword (lowercase, e.g. 'with_locale').
     * @param callable      $handler  See class docblock for expected signature.
     * @param array<string, string>|null $pairing
     *   Opener form: member keyword → `'required'` (exactly one) or `'allowed'`.
     *   Member form: `['owner' => '<opener keyword>']` — a single entry, assertion only.
     * @throws ClarityException On an invalid role, a reserved keyword, a mixed or
     *                          self-referential declaration.
     */
    public function addDirective(string $keyword, callable $handler, ?array $pairing = null): static
    {
        if (!self::isDirectiveKeyword($keyword)) {
            throw new ClarityException(
                "Invalid directive keyword '{$keyword}': a keyword must match [a-zA-Z_][a-zA-Z0-9_]*."
            );
        }

        if (\in_array($keyword, self::BUILTIN_DIRECTIVE_KEYWORDS, true)) {
            throw new ClarityException(
                "Directive '{$keyword}' is a built-in keyword and cannot be re-registered: "
                    . 'the compiler dispatches it before the registry is consulted.'
            );
        }

        if ($pairing !== null) {
            $this->assertDirectivePairing($keyword, $pairing);
        }

        $this->directiveHandlers[$keyword] = $handler;
        return $this;
    }

    /**
     * Validate one `addDirective()` pairing argument and record it.
     *
     * The two forms are told apart by their VALUES, not by a flag: an entry whose
     * value is `'owner'` is the member form, `'required'`/`'allowed'` are the opener
     * form.  Mixing the two in one call is refused, because it would leave the
     * direction of the mapping ambiguous.
     *
     * @param string                $keyword
     * @param array<string, string> $pairing
     */
    private function assertDirectivePairing(string $keyword, array $pairing): void
    {
        if (\array_is_list($pairing)) {
            throw new ClarityException(
                "The pairing argument for '{$keyword}' must map keywords to roles, "
                    . "e.g. ['endcache' => 'required'] or ['cache' => 'owner']."
            );
        }

        $ownerEntries = 0;
        $required     = 0;

        foreach ($pairing as $member => $role) {
            if (!\is_string($member) || !self::isDirectiveKeyword($member)) {
                throw new ClarityException(
                    "Invalid directive keyword '" . (string) $member . "' in the pairing argument of '{$keyword}'."
                );
            }

            if (\in_array($member, self::BUILTIN_DIRECTIVE_KEYWORDS, true)) {
                throw new ClarityException(
                    "Directive '{$keyword}' cannot declare the built-in keyword '{$member}' as a member; "
                        . 'the compiler dispatches it before the registry is consulted.'
                );
            }

            if ($role === 'owner') {
                $ownerEntries++;
                if ($ownerEntries > 1 || \count($pairing) > 1) {
                    throw new ClarityException(
                        "'{$keyword}' may assert only one owner, e.g. ['cache' => 'owner']."
                    );
                }
                if ($member === $keyword) {
                    throw new ClarityException("A directive cannot own itself: '{$keyword}'.");
                }
                if (\in_array($member, self::BUILTIN_DIRECTIVE_KEYWORDS, true)) {
                    throw new ClarityException(
                        "Directive '{$keyword}' cannot be owned by the built-in keyword '{$member}'."
                    );
                }
                if (isset($this->directivePairings[$keyword])) {
                    throw new ClarityException(
                        "'{$keyword}' already declares member tags, so it cannot also assert an owner."
                    );
                }

                $this->directiveOwners[$keyword] = $member;
                return;
            }

            if ($role !== 'required' && $role !== 'allowed') {
                throw new ClarityException(
                    "Unknown pairing role '" . (string) $role . "' for '{$member}' in the declaration of "
                        . "'{$keyword}': expected 'required', 'allowed', or 'owner'."
                );
            }

            if (isset($this->directiveOwners[$keyword])) {
                throw new ClarityException(
                    "'{$keyword}' asserts an owner, so it cannot also declare member tags."
                );
            }

            if ($member === $keyword) {
                throw new ClarityException("A directive cannot be its own member: '{$keyword}'.");
            }

            if (isset($this->directivePairings[$keyword][$member])) {
                throw new ClarityException(
                    "'{$member}' is declared twice in the declaration of '{$keyword}'."
                );
            }

            if ($role === 'required' && ++$required > 1) {
                throw new ClarityException(
                    "The declaration of '{$keyword}' has more than one 'required' close tag."
                );
            }

            $this->directivePairings[$keyword][$member] = $role;
        }

        if ($required === 0) {
            throw new ClarityException(
                "The declaration of '{$keyword}' needs exactly one 'required' close tag."
            );
        }
    }

    private static function isDirectiveKeyword(string $keyword): bool
    {
        return (bool) \preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $keyword);
    }

    /**
     * Rebuild the reverse pairing index and assert every declaration is coherent.
     *
     * Called at the start of every compile: registration order is not fixed, so a
     * close tag may be registered before the opener that declares it, and only a
     * pass over the finished tables can catch a missing handler or a contradiction.
     *
     * @throws ClarityException On a missing member handler, a member declared by
     *                          two openers, a member that is also an opener, or an
     *                          owner assertion the owner does not honour.
     */
    public function assertPairingConsistency(): void
    {
        if ($this->directivePairings === [] && $this->directiveOwners === []) {
            return;
        }

        $memberToOpener = [];

        foreach ($this->directivePairings as $opener => $members) {
            if (!isset($this->directiveHandlers[$opener])) {
                throw new ClarityException(
                    "Directive '{$opener}' declares member tags but has no registered handler."
                );
            }

            $closes = 0;
            foreach ($members as $role) {
                if ($role === 'required' && ++$closes > 1) {
                    throw new ClarityException(
                        "The declaration of '{$opener}' has more than one 'required' close tag."
                    );
                }
            }

            foreach ($members as $member => $role) {
                if (!isset($this->directiveHandlers[$member])) {
                    throw new ClarityException(
                        "Directive '{$opener}' declares '{$member}' as "
                            . ($role === 'required' ? 'its close tag' : 'a branch tag')
                            . ", but no handler is registered for '{$member}'."
                    );
                }

                if (isset($memberToOpener[$member]) && $memberToOpener[$member] !== $opener) {
                    throw new ClarityException(
                        "Directive '{$member}' is declared by both '{$memberToOpener[$member]}' and '{$opener}'."
                    );
                }

                if (isset($this->directivePairings[$member])) {
                    throw new ClarityException(
                        "Directive '{$member}' is declared as a member of '{$opener}' but also declares its own members."
                    );
                }

                $memberToOpener[$member] = $opener;
            }
        }

        foreach ($this->directiveOwners as $member => $owner) {
            if (!isset($this->directiveHandlers[$member])) {
                throw new ClarityException(
                    "Directive '{$member}' asserts owner '{$owner}' but has no registered handler."
                );
            }
            if (!isset($this->directiveHandlers[$owner])) {
                throw new ClarityException(
                    "Directive '{$member}' asserts owner '{$owner}', but no such directive is registered."
                );
            }
            if (!isset($this->directivePairings[$owner][$member])) {
                throw new ClarityException(
                    "Directive '{$member}' asserts owner '{$owner}', but '{$owner}' does not declare it as a close or branch tag."
                );
            }
        }

        $this->directiveMemberToOpener = $memberToOpener;
    }

    /**
     * Whether `$keyword` opens a paired construct (i.e. declares member tags).
     */
    public function isDirectiveOpener(string $keyword): bool
    {
        return isset($this->directivePairings[$keyword]);
    }

    /**
     * The close-tag keyword of the construct `$keyword` opens, or null.
     */
    public function getDirectiveCloseKeyword(string $keyword): ?string
    {
        foreach ($this->directivePairings[$keyword] ?? [] as $member => $role) {
            if ($role === 'required') {
                return $member;
            }
        }

        return null;
    }

    /**
     * Branch keywords declared by the construct `$keyword` opens.
     *
     * @return list<string>
     */
    public function getDirectiveBranchKeywords(string $keyword): array
    {
        $branches = [];
        foreach ($this->directivePairings[$keyword] ?? [] as $member => $role) {
            if ($role === 'allowed') {
                $branches[] = $member;
            }
        }

        return $branches;
    }

    /**
     * The construct a close/branch tag belongs to, or null when `$keyword` is an
     * ordinary, unpaired directive.
     */
    public function getDirectiveOwner(string $keyword): ?string
    {
        return $this->directiveMemberToOpener[$keyword] ?? null;
    }

    /**
     * Whether `$keyword` is the close tag of its construct (vs a branch tag).
     */
    public function isDirectiveClose(string $keyword): bool
    {
        $owner = $this->getDirectiveOwner($keyword);

        return $owner !== null && ($this->directivePairings[$owner][$keyword] ?? null) === 'required';
    }

    /**
     * Whether `$keyword` is a branch tag of its construct.
     */
    public function isDirectiveBranch(string $keyword): bool
    {
        $owner = $this->getDirectiveOwner($keyword);

        return $owner !== null && ($this->directivePairings[$owner][$keyword] ?? null) === 'allowed';
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