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
 * This is deliberately a heuristic, not a JavaScript lexer. It tracks one bit of
 * state — "is the current line inside a quoted string literal?" — incrementally
 * across template segments, because the text between two expressions carries the
 * answer forward.
 *
 * Deliberate simplifications, all biased towards answering "inside a string",
 * because that form cannot produce a syntax error:
 *
 *   - Template literals (backticks) are not tracked at all. `${…}` is JavaScript
 *     interpolation, which Clarity has no business reading.
 *   - A quote of the OTHER kind inside a string (`"it's"`) is ignored rather than
 *     treated as a terminator: from mid-string there is no way to tell a nested
 *     apostrophe from a new literal. Erring towards "inside" is the safe way.
 *   - A newline while inside a string ends it. A raw line break cannot appear in
 *     a JS string literal, so this is what stops one stray apostrophe in a
 *     comment from making the rest of the file look quoted.
 */
trait ScriptQuoteTrait
{
    /** Quote character currently open, or '' when outside any string literal. */
    private string $scriptQuoteChar = '';

    /**
     * Forget everything about the previous script. Called when the escaping
     * context changes, so state cannot leak from one `<script>` to the next.
     */
    private function resetScriptQuoteState(): void
    {
        $this->scriptQuoteChar = '';
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
            // a hard reset so a stray quote cannot poison the rest of the file.
            if ($char === "\n" || $char === "\r") {
                $this->scriptQuoteChar = '';
                continue;
            }

            // A backslash escapes the next character, which therefore cannot
            // open or close a string.
            if ($char === '\\') {
                $i++;
                continue;
            }

            if ($this->scriptQuoteChar === '') {
                if ($char === '"' || $char === "'") {
                    $this->scriptQuoteChar = $char;
                }
                continue;
            }

            // Inside a string: only the SAME delimiter closes it.
            if ($char === $this->scriptQuoteChar) {
                $this->scriptQuoteChar = '';
            }
        }
    }
}
