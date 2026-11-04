<?php

namespace Clarity\Engine\Tokenizer;

/**
 * Decides whether an output expression sits inside a JavaScript string literal.
 *
 * Only relevant in `js` context, where an interpolation is normally escaped with
 * `json_encode()` — a COMPLETE JS literal, quotes included. That form is correct
 * between tokens (`var x = {{ value }};`) but produces a syntax error in the
 * quoted position authors naturally write (`var x = "{{ value }}";`, which used to
 * render `var x = ""v"";`). Because a syntax error discards the whole
 * `<script>` block, the engine has to know which of the two positions it is in.
 *
 * This is a heuristic, not a JavaScript lexer. Getting it wrong is expensive in
 * BOTH directions, so it is conservative about *opening* a string:
 *
 *   - Wrongly "outside" a real string emits `""v""` — a syntax error that kills
 *     the whole block.
 *   - Wrongly "inside" a bare position drops the quotes, so `{{ v }}` renders
 *     `hello world` instead of `"hello world"` — also a syntax error, and for
 *     every string value rather than only the quoted form.
 *
 * The central rule is that `"` or `'` only OPENS a string when the surrounding
 * code could legally begin one: after an operator or an opening bracket, or
 * after a keyword such as `return`. A quote anywhere else is an ordinary
 * character. That single test makes a stray apostrophe in prose behave without
 * needing to understand comments, regular expressions or template literals:
 *
 *   // don't touch        -> `don` is not a keyword   -> not a string
 *   /* don't *\/          -> same
 *   `it's`                -> `it` is not a keyword    -> not a string
 *   /"/.test(x)           -> `/` cannot open a string -> not a string
 *
 * Known limits. Both only misjudge the remainder of ONE line, because a raw line
 * break cannot occur inside a JS string literal:
 *
 *   - A regex literal directly after `(` that contains a quote, e.g.
 *     `x.replace(/'/g, …)`. That `'` is accepted as an opening delimiter, so the
 *     rest of the line looks quoted.
 *   - A string whose opening quote is preceded by something outside the accepted
 *     set (see {@see SCRIPT_STRING_OPENERS} for what is deliberately excluded).
 */
trait ScriptQuoteTrait
{
    /** Delimiter of the string literal currently open, or '' when outside one. */
    private string $scriptQuoteChar = '';

    /**
     * Characters that can legally precede the opening quote of a string literal.
     *
     * `/` is deliberately absent: it is far more likely to be a regular
     * expression or a comment (`/"/`, `// '`) than a division whose right operand
     * is a string, and excluding it handles both of those for free. `)` is absent
     * too, which keeps `replace(/'/g)` from opening a string here.
     */
    private const SCRIPT_STRING_OPENERS = '=([,:-?+*%!&|<>{;}~^';

    /**
     * Keywords after which a string literal may begin. Needed because the
     * preceding non-space character of `return "x"` is a letter, not an operator.
     */
    private const SCRIPT_STRING_KEYWORDS = [
        'return' => true, 'typeof' => true, 'case' => true, 'in' => true,
        'of' => true, 'new' => true, 'delete' => true, 'void' => true,
        'do' => true, 'else' => true, 'throw' => true, 'yield' => true,
        'await' => true, 'instanceof' => true,
    ];

    /** Last non-whitespace character seen outside any string literal. */
    private string $scriptLastSignificant = '';

    /** Identifier characters immediately before the current position. */
    private string $scriptWord = '';

    /**
     * Forget everything about the previous script. Called when the escaping
     * context changes, so state cannot leak from one `<script>` to the next.
     */
    private function resetScriptQuoteState(): void
    {
        $this->scriptQuoteChar       = '';
        $this->scriptLastSignificant = '';
        $this->scriptWord            = '';
    }

    /**
     * Whether the expression being compiled sits inside a string literal.
     *
     * There is no useful distinction between "just after the opening quote" and
     * "mid-literal": both need the delimiters suppressed, and the engine cannot
     * safely do anything smarter mid-string anyway.
     */
    private function currentScriptQuoteInside(): bool
    {
        return $this->escapeContext === 'js' && $this->scriptQuoteChar !== '';
    }

    /**
     * Advance the quote state over a template text segment.
     *
     * Called for every TEXT segment in `js` context, in source order. Must see
     * the RAW text (before the post-tag line-break rule), because trimming a
     * newline would change the answer.
     */
    private function advanceScriptQuoteState(string $text): void
    {
        if ($this->escapeContext !== 'js') {
            return;
        }

        $length = \strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            // A raw line break cannot be inside a JS string literal. Treat it as
            // a hard reset so a misjudged quote cannot poison later lines.
            if ($char === "\n" || $char === "\r") {
                $this->scriptQuoteChar = '';
                continue;
            }

            if ($this->scriptQuoteChar !== '') {
                // Inside a string: a backslash escapes whatever follows, and only
                // the SAME delimiter closes it. The other quote is ordinary text,
                // which is what makes `"it's"` work.
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $this->scriptQuoteChar) {
                    $this->scriptQuoteChar       = '';
                    $this->scriptLastSignificant = $char;
                    $this->scriptWord            = '';
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                if ($this->scriptStringMayOpen()) {
                    $this->scriptQuoteChar = $char;
                    $this->scriptWord      = '';
                    continue;
                }

                // An apostrophe in prose (`don't`) or the quote in `/"/` lands
                // here: an ordinary character, not a delimiter.
                $this->scriptLastSignificant = $char;
                $this->scriptWord            = '';
                continue;
            }

            if (\ctype_alnum($char) || $char === '_' || $char === '$') {
                $this->scriptWord           .= $char;
                $this->scriptLastSignificant = $char;
                continue;
            }

            // Whitespace keeps the pending word so `return "x"` still sees the
            // keyword; any other character ends it.
            if ($char === ' ' || $char === "\t") {
                continue;
            }

            $this->scriptWord            = '';
            $this->scriptLastSignificant = $char;
        }
    }

    /**
     * Whether a quote at the current position could begin a string literal.
     */
    private function scriptStringMayOpen(): bool
    {
        if ($this->scriptWord !== '') {
            return isset(self::SCRIPT_STRING_KEYWORDS[\strtolower($this->scriptWord)]);
        }

        return $this->scriptLastSignificant === ''
            || \str_contains(self::SCRIPT_STRING_OPENERS, $this->scriptLastSignificant);
    }
}
