<?php

namespace Clarity\Engine\Tokenizer;

/**
 * Validates where a script interpolation sits, so an unquoted one is caught at
 * compile time.
 *
 * `js` context emits escaped string CONTENT, so the template supplies the quotes:
 * `var name = "{{ user.name }}";`. There is no second form — see
 * {@see ExpressionCoreTrait::jsContextExpression()} for why.
 *
 * This trait never chooses how a value is rendered, only whether a tag is
 * allowed to compile. Its judgement therefore cannot change output: the worst a
 * misjudgement can do is reject a template that would have worked.
 *
 * ## Why the scan lexes instead of counting quotes
 *
 * Counting quotes would be enough if JavaScript had no comments or regular
 * expressions, and it is exactly how an unquoted tag could get accepted. One
 * quote in the wrong place opens a phantom string that swallows the rest of the
 * line:
 *
 *     <script>/* ' *\/ var x = {{ v }};</script>
 *     <script>var r = /'[/; var x = {{ v }};</script>
 *
 * The tag then looked quoted, and its value was emitted as bare JavaScript — a
 * template author's slip turned into script injection from data the template did
 * not control. Comments and regular expressions are therefore lexed here.
 *
 * ## What is accepted
 *
 * A tag is accepted inside a string literal or a comment, because both are inert:
 * the value is escaped string content that cannot close the construct it sits in.
 * A tag in a regular expression, or in code position, is rejected — that is where
 * a value can become code, and a regex is not a construct the escape rules were
 * designed to fill.
 *
 * ## Fail-safe by construction
 *
 * A lexing mistake leaves the scanner inside a literal or comment. Comments are
 * accepted and regex literals and code are rejected, and a stray quote in code is
 * what the check exists to catch — so guessing wrong lands on "reject" for the
 * cases that matter, never on "emit as bare JavaScript".
 *
 * ## Known limitations
 *
 *   - Template literals (`${…}`) are not tracked: a tag between backticks is
 *     rejected. Put it in a normal string.
 *   - A comment opener split across two text segments (`/` ending one segment,
 *     `*` starting the next) is not recognised; a tag between them is rejected.
 */
trait ScriptQuoteTrait
{
    /**
     * Matches the script/style tag boundaries that bracket script text. Only a
     * fully formed opening tag (with its closing `>`) counts, so an interpolation
     * inside a start-tag's attributes stays HTML context.
     */
    private const SCRIPT_BOUNDARY_PATTERN =
        '#<script\b[^>]*>|</script\b[^>]*>|<style\b[^>]*>|</style\b[^>]*>#i';

    /** Delimiter of the string literal currently open, or '' when outside one. */
    private string $scriptQuoteChar = '';

    /**
     * Characters that can legally precede the start of a value — where a string
     * literal may open, and equally where a regex literal may begin.
     *
     * `/` is deliberately absent: after a value-position slash the branch in
     * {@see advanceScriptQuoteState()} starts a regex instead, and `/` after an
     * operand is division.
     */
    private const SCRIPT_STRING_OPENERS = '=([,:-?+*%!&|<>{;}~^';

    /**
     * Keywords after which a value (so a string or regex literal) may begin.
     * Needed because the preceding non-space character of `return "x"` is a
     * letter, not an operator.
     */
    private const SCRIPT_STRING_KEYWORDS = [
        'return' => true, 'typeof' => true, 'case' => true, 'in' => true,
        'of' => true, 'new' => true, 'delete' => true, 'void' => true,
        'do' => true, 'else' => true, 'throw' => true, 'yield' => true,
        'await' => true, 'instanceof' => true,
    ];

    /** Last non-whitespace character seen outside any literal or comment. */
    private string $scriptLastSignificant = '';

    /** Identifier characters immediately before the current position. */
    private string $scriptWord = '';

    /** Inside a `// …` comment. Ended by the line break. */
    private bool $scriptInLineComment = false;

    /** Inside a `/* … *\/` comment. The one state that may span lines. */
    private bool $scriptInBlockComment = false;

    /** Inside a `/…/` regular expression literal. */
    private bool $scriptInRegex = false;

    /** Inside the `[…]` character class of that regular expression. */
    private bool $scriptInRegexClass = false;

    /**
     * Whether the text being scanned is inside a `<script>` element.
     *
     * `null` until a boundary is seen, so a template that uses `{# @context js #}`
     * without a `<script>` tag falls back to the escaping context.
     */
    private ?bool $scriptInScript = null;

    /**
     * Forget everything about the previous template. Called when the escaping
     * context changes, so state cannot leak from one template — or from one
     * context to the next — into another.
     */
    private function resetScriptQuoteState(): void
    {
        $this->resetScriptLexState();
        $this->scriptInScript = null;
    }

    /** Reset only the lexical state, keeping the `<script>` nesting answer. */
    private function resetScriptLexState(): void
    {
        $this->scriptQuoteChar       = '';
        $this->scriptLastSignificant = '';
        $this->scriptWord            = '';
        $this->scriptInLineComment   = false;
        $this->scriptInBlockComment  = false;
        $this->scriptInRegex         = false;
        $this->scriptInRegexClass    = false;
    }

    /**
     * Whether the expression being compiled sits somewhere a value is inert.
     *
     * Used to ACCEPT or REJECT the template, never to choose an output form.
     */
    private function currentScriptQuoteInside(): bool
    {
        if (!$this->scriptInScriptText()) {
            return false;
        }

        return $this->scriptQuoteChar !== ''
            || $this->scriptInLineComment
            || $this->scriptInBlockComment;
    }

    /**
     * Whether the current position is inside a `<script>` element.
     *
     * Falls back to the escaping context until a boundary has been seen, which is
     * what makes `{# @context js #}` work in a template with no script tag.
     */
    private function scriptInScriptText(): bool
    {
        return $this->scriptInScript ?? ($this->escapeContext === 'js');
    }

    /**
     * Advance the lexical state over a template text segment.
     *
     * Called for every TEXT segment in source order, with the RAW text (before
     * the post-tag line-break rule), because trimming a newline would change the
     * answer. Boundaries are tracked here rather than taken from the compiler's
     * escaping context, so a segment that opens and closes a script — or the very
     * segment being scanned — cannot leave the two out of step.
     */
    private function advanceScriptQuoteState(string $text): void
    {
        $length = \strlen($text);
        if ($length === 0) {
            return;
        }

        // Skip work when nothing here can change the answer: not inside a script
        // and no boundary ahead. `scriptInScript` is null until a boundary is seen,
        // so it falls back to the escaping context — which is what makes
        // `{# @context js #}` work in a template that has no script tag.
        if (!$this->scriptInScriptText() && !self::hasScriptBoundary($text)) {
            return;
        }

        $offset = 0;

        while ($offset < $length) {
            $found = \preg_match(
                self::SCRIPT_BOUNDARY_PATTERN,
                $text,
                $match,
                \PREG_OFFSET_CAPTURE,
                $offset,
            );

            if ($found !== 1) {
                $this->lexScriptText($offset === 0 ? $text : \substr($text, $offset));
                return;
            }

            [$tag, $tagOffset] = $match[0];
            $this->lexScriptText(\substr($text, $offset, $tagOffset - $offset));

            $isClosing = $tag[1] === '/';
            $isScript  = \stripos($tag, 'script') !== false;

            $this->resetScriptLexState();
            $this->scriptInScript = $isScript && !$isClosing;

            $offset = $tagOffset + \strlen($tag);
        }
    }

    /** Whether the text contains any `<script>`/`<style>` boundary. */
    private static function hasScriptBoundary(string $text): bool
    {
        return \preg_match(self::SCRIPT_BOUNDARY_PATTERN, $text) === 1;
    }

    /**
     * Lex one run of JavaScript source text, updating the literal/comment state.
     *
     * Does nothing outside a `<script>`, where the text is markup rather than
     * script and its quotes are not string delimiters.
     */
    private function lexScriptText(string $text): void
    {
        if ($text === '' || !$this->scriptInScriptText()) {
            return;
        }

        $length = \strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            $next = $i + 1 < $length ? $text[$i + 1] : '';

            // A line break ends a string, a line comment and a regex literal —
            // none of them may contain one. This hard reset is also the safety
            // net: a misjudged opener cannot poison the rest of the template.
            // A block comment is the one state that legitimately spans lines.
            if ($char === "\n" || $char === "\r") {
                $this->scriptQuoteChar      = '';
                $this->scriptInLineComment  = false;
                $this->scriptInRegex        = false;
                $this->scriptInRegexClass   = false;
                $this->scriptWord           = '';
                continue;
            }

            if ($this->scriptInLineComment) {
                continue;
            }

            if ($this->scriptInBlockComment) {
                if ($char === '*' && $next === '/') {
                    $this->scriptInBlockComment  = false;
                    $this->scriptLastSignificant = '/';
                    $i++;
                }
                continue;
            }

            if ($this->scriptInRegex) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === '[') {
                    $this->scriptInRegexClass = true;
                    continue;
                }
                // A `/` inside a character class is an ordinary character; only
                // one outside it closes the literal.
                if ($char === '/' && !$this->scriptInRegexClass) {
                    $this->scriptInRegex         = false;
                    $this->scriptLastSignificant = '/';
                    $this->scriptWord            = '';
                    continue;
                }
                if ($char === ']') {
                    $this->scriptInRegexClass = false;
                }
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

            if ($char === '/') {
                if ($next === '/') {
                    $this->scriptInLineComment = true;
                    $i++;
                    continue;
                }
                if ($next === '*') {
                    $this->scriptInBlockComment = true;
                    $i++;
                    continue;
                }
                // In a value position a slash begins a regex; anywhere else it is
                // division, so it falls through to the ordinary-character path.
                if ($this->scriptValueMayStart()) {
                    $this->scriptInRegex      = true;
                    $this->scriptInRegexClass = false;
                    continue;
                }
            }

            if ($char === '"' || $char === "'") {
                if ($this->scriptValueMayStart()) {
                    $this->scriptQuoteChar = $char;
                    $this->scriptWord      = '';
                    continue;
                }

                // An apostrophe in prose (`don't`) lands here: an ordinary
                // character, not a delimiter.
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
     * Whether a value may begin at the current position — the test that decides
     * both "may a string literal open here" and "may a regex literal begin here".
     */
    private function scriptValueMayStart(): bool
    {
        if ($this->scriptWord !== '') {
            return isset(self::SCRIPT_STRING_KEYWORDS[\strtolower($this->scriptWord)]);
        }

        return $this->scriptLastSignificant === ''
            || \str_contains(self::SCRIPT_STRING_OPENERS, $this->scriptLastSignificant);
    }
}
