<?php

namespace Clarity\Engine\Tokenizer;

/**
 * Validates that a script interpolation is inside a string literal.
 *
 * `js` context emits escaped string CONTENT, so the template is required to
 * supply the quotes: `var name = "{{ user.name }}";`. There is no second form.
 *
 * This trait exists only to catch the unquoted form at COMPILE time and point at
 * the fix. It never chooses how a value is rendered, which is why its judgement
 * cannot change output — the worst it can do is reject a template that would
 * have worked.
 *
 * A quote OPENS a string only when the surrounding code could legally begin one:
 * after an operator or an opening bracket, at the start of a statement, or after
 * a keyword such as `return`. A quote anywhere else is an ordinary character,
 * which is what keeps prose out of the way without needing to understand
 * comments, regular expressions or template literals:
 *
 *   // don't touch        -> `don` is not a keyword   -> not a string
 *   `it's`               -> `it` is not a keyword    -> not a string
 *   /"/.test(x)          -> `/` cannot open a string -> not a string
 *
 * Known false rejects, both affecting only ONE line because a raw line break
 * cannot occur inside a JS string literal:
 *
 *   - a regex literal containing a quote directly after `(`, as in
 *     `'{{ x }}'.match(/'/)` or `x.replace(/'/g, '…')`;
 *   - a string whose opening quote is preceded by something outside the accepted
 *     set (see {@see SCRIPT_STRING_OPENERS}).
 *
 * Both are worked around by moving the expression to its own line, or by using
 * `| raw` for a JSON payload, which bypasses this check entirely.
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
     * Used to ACCEPT or REJECT the template, never to choose an output form. An
     * unquoted interpolation is a compile error rather than a different escaping
     * strategy, so a misjudged line produces a build failure, not wrong output.
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
