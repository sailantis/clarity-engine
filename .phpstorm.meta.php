<?php
// noinspection PhpUndefinedClassInspection

/**
 * PhpStorm meta file for Clarity template engine built-in filters,
 * functions, and custom block directives.
 *
 * This file declares the core Clarity extensions via the
 * override(\Clarity::filter(0), map([...])) convention so that
 * PhpThunder (and other tools that understand this convention) can
 * provide completion, hover, and signature help for Clarity templates
 * without requiring a full project index.
 */

namespace PHPSTORM_META
{

    // ── Filters ─────────────────────────────────────────────────────────

    override(\Clarity::filter(0), map([
        'abs' => [
            'return'      => 'int|float',
            'description' => 'Absolute value of a number.',
            'example'     => '{{ x |> abs }}',
        ],
        'batch' => [
            'return'      => 'array',
            'params'      => [['size'], ['fill', 'null']],
            'description' => 'Split array into chunks of the given size, optionally padded with a fill value.',
            'example'     => "{{ items |> batch(3) }}",
        ],
        'capitalize' => [
            'return'      => 'string',
            'description' => 'First character uppercase, rest lowercase (Unicode-aware).',
            'example'     => '{{ name |> capitalize }}',
        ],
        'ceil' => [
            'return'      => 'int',
            'description' => 'Round up to the nearest integer.',
            'example'     => '{{ x |> ceil }}',
        ],
        'data_uri' => [
            'return'      => 'string',
            'params'      => [['mime', "'application/octet-stream'"]],
            'description' => 'Generate a base64-encoded data: URI.',
            'example'     => "{{ content |> data_uri('image/png') }}",
        ],
        'date' => [
            'return'      => 'string',
            'params'      => [['format', "'Y-m-d'"]],
            'description' => 'Format a timestamp, DateTimeInterface, or date string.',
            'example'     => "{{ ts |> date('F j, Y') }}",
        ],
        'date_modify' => [
            'return'      => 'int',
            'params'      => [['modifier']],
            'description' => "Apply a date modifier (e.g. '+1 day') and return the Unix timestamp.",
            'example'     => "{{ ts |> date_modify('+1 day') }}",
        ],
        'default' => [
            'return'      => 'mixed',
            'params'      => [['fallback', 'null']],
            'description' => 'Return a fallback value if the piped value is null/undefined.',
            'example'     => "{{ x |> default('N/A') }}",
        ],
        'empty' => [
            'return'      => 'mixed',
            'params'      => [['fallback', '""']],
            'description' => 'Return a fallback if the piped value is empty/falsy.',
            'example'     => "{{ x |> empty('—') }}",
        ],
        'escape' => [
            'return'      => 'string',
            'description' => 'HTML-escape the value (htmlspecialchars). Rarely needed — auto-escaping is on by default.',
            'example'     => '{{ x |> escape }}',
        ],
        'esc' => [
            'return'      => 'string',
            'description' => 'Alias of escape.',
            'example'     => '{{ x |> esc }}',
        ],
        'first' => [
            'return'      => 'mixed',
            'description' => 'Get the first element of an array or first character of a string.',
            'example'     => '{{ items |> first }}',
        ],
        'floor' => [
            'return'      => 'int',
            'description' => 'Round down to the nearest integer.',
            'example'     => '{{ x |> floor }}',
        ],
        'format' => [
            'return'      => 'string',
            'variadic'    => true,
            'description' => 'sprintf-style string formatting.',
            'example'     => "{{ 'Hello %s' |> format(name) }}",
        ],
        'format_datetime' => [
            'return'      => 'string',
            'params'      => [['dateStyle', "'medium'"], ['timeStyle', "'medium'"], ['locale', 'null'], ['timezone', 'null']],
            'description' => 'Format a date using IntlDateFormatter with locale-aware styles.',
            'example'     => "{{ ts |> format_datetime('long', 'short') }}",
        ],
        'json' => [
            'return'      => 'string',
            'description' => 'JSON-encode the value. Use with |> raw to output unescaped.',
            'example'     => '{{ data |> json |> raw }}',
        ],
        'join' => [
            'return'      => 'string',
            'params'      => [['glue', "''"]],
            'description' => 'Join array elements into a string.',
            'example'     => "{{ items |> join(', ') }}",
        ],
        'keys' => [
            'return'      => 'array',
            'description' => 'Get the keys of an array.',
            'example'     => '{{ map |> keys }}',
        ],
        'last' => [
            'return'      => 'mixed',
            'description' => 'Get the last element of an array or last character of a string.',
            'example'     => '{{ items |> last }}',
        ],
        'length' => [
            'return'      => 'int',
            'description' => 'Count elements in an array or string length (mb_strlen).',
            'example'     => '{{ items |> length }}',
        ],
        'lower' => [
            'return'      => 'string',
            'description' => 'Convert to lowercase (mb_strtolower).',
            'example'     => '{{ name |> lower }}',
        ],
        'map' => [
            'return'      => 'array',
            'params'      => [['fn']],
            'description' => 'Transform each element via a lambda or filter reference.',
            'example'     => '{{ users |> map(u => u.name) }}',
        ],
        'merge' => [
            'return'      => 'array',
            'params'      => [['other', '[]']],
            'description' => 'Merge two arrays.',
            'example'     => '{{ a |> merge(b) }}',
        ],
        'nl2br' => [
            'return'      => 'string',
            'description' => 'Insert <br> tags before newlines. Use with |> raw.',
            'example'     => '{{ text |> nl2br |> raw }}',
        ],
        'number' => [
            'return'      => 'string',
            'params'      => [['decimals', '2']],
            'description' => 'Format a number with decimal places.',
            'example'     => '{{ price |> number(2) }}',
        ],
        'raw' => [
            'return'      => 'string',
            'description' => 'Disable auto-escaping for this output. Use with caution.',
            'example'     => '{{ html |> raw }}',
        ],
        'reduce' => [
            'return'      => 'mixed',
            'params'      => [['fn'], ['initial', 'null']],
            'description' => 'Reduce an array to a single value.',
            'example'     => '{{ nums |> reduce(sum, v => sum + v, 0) }}',
        ],
        'replace' => [
            'return'      => 'string',
            'params'      => [['search'], ['replace', "''"]],
            'description' => 'String replacement (str_replace).',
            'example'     => "{{ s |> replace('foo', 'bar') }}",
        ],
        'reverse' => [
            'return'      => 'array|string',
            'description' => 'Reverse an array or string (Unicode-aware).',
            'example'     => '{{ items |> reverse }}',
        ],
        'round' => [
            'return'      => 'int|float',
            'params'      => [['precision', '0']],
            'description' => 'Round to the given precision.',
            'example'     => '{{ x |> round(2) }}',
        ],
        'shuffle' => [
            'return'      => 'array',
            'description' => 'Return a shuffled copy of an array.',
            'example'     => '{{ items |> shuffle }}',
        ],
        'slice' => [
            'return'      => 'array|string',
            'params'      => [['start'], ['length', 'null']],
            'description' => 'Extract a portion of an array or string.',
            'example'     => '{{ items |> slice(0, 5) }}',
        ],
        'slug' => [
            'return'      => 'string',
            'params'      => [['separator', "'-'"]],
            'description' => 'Generate a URL-friendly slug.',
            'example'     => '{{ title |> slug }}',
        ],
        'sort' => [
            'return'      => 'array',
            'description' => 'Return a sorted copy of an array.',
            'example'     => '{{ items |> sort }}',
        ],
        'split' => [
            'return'      => 'array',
            'params'      => [['delimiter'], ['limit', 'PHP_INT_MAX']],
            'description' => 'Split a string into an array.',
            'example'     => "{{ s |> split(',') }}",
        ],
        'striptags' => [
            'return'      => 'string',
            'params'      => [['allowedTags', "''"]],
            'description' => 'Strip HTML/PHP tags.',
            'example'     => '{{ html |> striptags }}',
        ],
        'title' => [
            'return'      => 'string',
            'description' => 'Title-case every word (mb_convert_case).',
            'example'     => '{{ name |> title }}',
        ],
        'trim' => [
            'return'      => 'string',
            'description' => 'Remove leading/trailing whitespace.',
            'example'     => '{{ s |> trim }}',
        ],
        'truncate' => [
            'return'      => 'string',
            'params'      => [['length'], ['ellipsis', "'…'"]],
            'description' => 'Truncate a string to the given length.',
            'example'     => '{{ s |> truncate(100) }}',
        ],
        'unicode' => [
            'return'      => 'string',
            'params'      => [['start', '0'], ['length', 'null']],
            'description' => 'Wrap in UnicodeString for advanced operations.',
            'example'     => '{{ s |> unicode }}',
        ],
        'upper' => [
            'return'      => 'string',
            'description' => 'Convert to uppercase (mb_strtoupper).',
            'example'     => '{{ name |> upper }}',
        ],
        'url_encode' => [
            'return'      => 'string',
            'description' => 'URL-encode the value (rawurlencode).',
            'example'     => '{{ s |> url_encode }}',
        ],
        'values' => [
            'return'      => 'array',
            'description' => 'Get the values of an array (re-indexed).',
            'example'     => '{{ map |> values }}',
        ],
    ]));

    // ── Functions ───────────────────────────────────────────────────────

    override(\Clarity::function(0), map([
        'context' => [
            'return'      => 'array',
            'params'      => [['vars', '[]']],
            'description' => 'Returns the current template variables array.',
            'example'     => '{{ context() }}',
        ],
        'include' => [
            'return'      => 'string',
            'params'      => [['view'], ['context', '[]']],
            'description' => 'Render another template dynamically.',
            'example'     => "{{ include('partials/header') }}",
        ],
        'dump' => [
            'return'      => 'string',
            'variadic'    => true,
            'description' => 'Debug dump (only in debug mode, eliminated in production).',
            'example'     => '{{ dump(x, y) }}',
        ],
        'dd' => [
            'return'      => 'never',
            'variadic'    => true,
            'description' => 'Debug dump and die (always active).',
            'example'     => '{{ dd(x) }}',
        ],
        'keys' => [
            'return'      => 'array',
            'params'      => [['map']],
            'description' => 'Get the keys of an array.',
            'example'     => '{{ keys(map) }}',
        ],
        'values' => [
            'return'      => 'array',
            'params'      => [['map']],
            'description' => 'Get the values of an array (re-indexed).',
            'example'     => '{{ values(map) }}',
        ],
        'len' => [
            'return'      => 'int',
            'params'      => [['value']],
            'description' => 'Count elements in an array or unicode string length.',
            'example'     => '{{ len(items) }}',
        ],
    ]));

    // ── Module-registered filters (IntlFormatModule, TranslationModule) ─

    override(\Clarity::filter(0), map([
        'format_number' => [
            'return'      => 'string',
            'params'      => [['decimals', '2'], ['locale', 'null']],
            'description' => 'Locale-aware number formatting (NumberFormatter).',
            'example'     => '{{ 1234567.89 |> format_number(2) }}',
        ],
        'format_currency' => [
            'return'      => 'string',
            'params'      => [['currency', "'EUR'"], ['locale', 'null']],
            'description' => 'Locale-aware currency formatting.',
            'example'     => '{{ price |> format_currency("USD") }}',
        ],
        'currency_name' => [
            'return'      => 'string',
            'params'      => [['locale', 'null']],
            'description' => 'Get the display name of a currency code.',
            'example'     => "{{ currency_name('USD') }}",
        ],
        'currency_symbol' => [
            'return'      => 'string',
            'params'      => [['locale', 'null']],
            'description' => 'Get the symbol of a currency code.',
            'example'     => "{{ currency_symbol('USD') }}",
        ],
        'percent' => [
            'return'      => 'string',
            'params'      => [['decimals', '0'], ['locale', 'null']],
            'description' => 'Format a value as a percentage.',
            'example'     => '{{ 0.1234 |> percent }}',
        ],
        'scientific' => [
            'return'      => 'string',
            'params'      => [['locale', 'null']],
            'description' => 'Format a number in scientific notation.',
            'example'     => '{{ 42 |> scientific }}',
        ],
        'spellout' => [
            'return'      => 'string',
            'params'      => [['locale', 'null']],
            'description' => 'Spell out a number as words (NumberFormatter SPELLOUT).',
            'example'     => '{{ 42 |> spellout }}',
        ],
        'ordinal' => [
            'return'      => 'string',
            'params'      => [['locale', 'null']],
            'description' => 'Format a number as an ordinal (e.g. "1st", "2nd").',
            'example'     => '{{ 1 |> ordinal }}',
        ],
        'format_date' => [
            'return'      => 'string',
            'params'      => [['style', "'medium'"], ['locale', 'null'], ['timezone', 'null']],
            'description' => 'Locale-aware date formatting (IntlDateFormatter).',
            'example'     => "{{ order.created_at |> format_date('long') }}",
        ],
        'format_time' => [
            'return'      => 'string',
            'params'      => [['style', "'medium'"], ['locale', 'null'], ['timezone', 'null']],
            'description' => 'Locale-aware time formatting (IntlDateFormatter).',
            'example'     => "{{ now |> format_time('short') }}",
        ],
        'format_relative' => [
            'return'      => 'string',
            'params'      => [['locale', 'null']],
            'description' => 'Relative time formatting (e.g. "3 minutes ago").',
            'example'     => '{{ order.created_at |> format_relative }}',
        ],
        'transliterate' => [
            'return'      => 'string',
            'params'      => [['rules', "'Any-Latin; Latin-ASCII'"]],
            'description' => 'Transliterate text using ICU transliteration rules.',
            'example'     => "{{ 'Hëllo Wörld' |> transliterate }}",
        ],
        'format_message' => [
            'return'      => 'string',
            'params'      => [['vars', '[]'], ['locale', 'null']],
            'description' => 'ICU MessageFormat (plurals, selects, etc.).',
            'example'     => "{{ '{count, plural, one{# item} other{# items}}' |> format_message({count: n}) }}",
        ],
        't' => [
            'return'      => 'string',
            'params'      => [['vars', 'null'], ['domain', 'null']],
            'description' => 'Translate a key from domain-separated locale files.',
            'example'     => "{{ 'logout' |> t }}",
        ],
    ]));

    // ── Module-registered functions (IntlFormatModule) ──────────────────

    override(\Clarity::function(0), map([
        'country_name' => [
            'return'      => 'string',
            'params'      => [['displayLocale', 'null'], ['locale', 'null']],
            'description' => 'ISO country code → display name.',
            'example'     => "{{ country_name('DE') }}",
        ],
        'language_name' => [
            'return'      => 'string',
            'params'      => [['displayLocale', 'null'], ['locale', 'null']],
            'description' => 'Language code → display name.',
            'example'     => "{{ language_name('de') }}",
        ],
        'locale_name' => [
            'return'      => 'string',
            'params'      => [['displayLocale', 'null'], ['locale', 'null']],
            'description' => 'Locale identifier → display name.',
            'example'     => "{{ locale_name('en_US') }}",
        ],
        'timezone_name' => [
            'return'      => 'string',
            'params'      => [['displayLocale', 'null']],
            'description' => 'Timezone identifier → display name.',
            'example'     => "{{ timezone_name('America/New_York') }}",
        ],
    ]));

    // ── Custom directives (from modules) ──────────────────────────
    // Core directives (if, for, block, macro, etc.) are compiler-level and
    // not declared here — they are hardcoded in BuiltinDirectives.  Only
    // module-registered directives are declared via meta.

    override(\Clarity::directive(0), map([
        'with_locale' => [
            'syntax'      => '{% with_locale locale %}',
            'closer'      => 'endwith_locale',
            'description' => 'Push a locale onto the locale stack for the body.',
            'example'     => "{% with_locale 'de' %}\n    {{ text }}\n{% endwith_locale %}",
        ],
        'with_t_domain' => [
            'syntax'      => '{% with_t_domain domain %}',
            'closer'      => 'endwith_t_domain',
            'description' => 'Push a translation domain onto the domain stack for the body.',
            'example'     => "{% with_t_domain 'emails' %}\n    {{ 'welcome' |> t }}\n{% endwith_t_domain %}",
        ],
    ]));

}