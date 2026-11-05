<?php

namespace Clarity\Engine\Tokenizer;

/**
 * Decides how a script interpolation is emitted, from where it sits.
 *
 * `js` context has two output forms, and the position selects between them:
 *
 *     var name = "{{ user.name }}";   ->  var name = "Ada";        (string content)
 *     var obj  = {{ user }};          ->  var obj  = {"name":"Ada"}; (JSON literal)
 *
 * The template supplies the quotes when it wants a string; an unquoted tag is a
 * complete JSON literal, so `{{ n }}` renders `3` and `{{ tiers }}` renders
 * `[1,2]`.
 *
 * ## Why this trait exists
 *
 * Choosing between two forms means the choice can be WRONG, and a wrong choice
 * changes what is rendered. That is the risk this file is entirely about. It was
 * taken deliberately, after seeing that a bare `{{ x }}` is the form people
 * naturally write and that rejecting it forced a workaround for every number,
 * boolean and JSON payload.
 *
 * The mitigation is that a misjudgement must fail loudly:
 *
 *   - wrong in the code-position direction (a quoted tag read as code) emits a
 *     JSON literal, which for a string value is a double quote — a JavaScript
 *     syntax error on that line;
 *   - wrong in the string direction (a code tag read as quoted) emits escaped
 *     string content, which for a string value is a bare identifier or a
 *     ReferenceError.
 *
 * Neither silently produces a different-but-valid value, and the numbers case
 * coincides on purpose: `json_encode(12)` and the string content of `12` are both
 * `12`.
 *
 * ## Why the scan lexes instead of counting quotes
 *
 * Counting quotes would be enough if JavaScript had no comments or regular
 * expressions. One quote in the wrong place opens a phantom string that swallows
 * the rest of the line:
 *
 *     <script>/* ' *\/ var x = {{ v }};</script>
 *     <script>var r = /'[/; var x = {{ v }};</script>
 *
 * Read as quoted, that emitted the value as bare JavaScript — script injection
 * from data the template did not control. Comments and regular expressions are
 * therefore lexed here, so the quote cannot be misread.
 *
 * ## States
 *
 * | Position              | Emitted           | Why                              |
 * | --------------------- | ----------------- | -------------------------------- |
 * | inside `"…"` or `'…'` | escaped content   | cannot close the literal         |
 * | inside a comment      | escaped content   | inert either way                 |
 * | code position         | JSON literal      | a literal is valid where a value is expected |
 * | inside a regex        | **rejected**      | a value inside `/…/` is not meaningful |
 * | inside a template     | **rejected**      | `` ` `` and `${` are not escaped by either form, so a value containing them would break out or execute |
 *
 * ## Known limitations
 *
 *   - Template literals are tracked opaquely: `${ … }` is not parsed, so a tag
 *     inside the interpolation of a template literal is rejected too. Use a
 *     normal string.
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

    /** Inside a `` `…` `` template literal. */
    private bool $scriptInTemplate = false;

    /** Inside `${ … }` within a template literal. */
    private bool $scriptInTemplateExpr = false;

    /** Brace depth inside `${ … }`, so a nested `}` cannot end it early. */
    private int $scriptTemplateExprDepth = 0;

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
        $this->scriptQuoteChar          = '';
        $this->scriptLastSignificant    = '';
        $this->scriptWord               = '';
        $this->scriptInLineComment      = false;
        $this->scriptInBlockComment     = false;
        $this->scriptInRegex            = false;
        $this->scriptInRegexClass       = false;
        $this->scriptInTemplate         = false;
        $this->scriptInTemplateExpr     = false;
        $this->scriptTemplateExprDepth  = 0;
    }

    /**
     * Where the expression being compiled sits, which selects the output form.
     *
     * Deciding this is a real risk — a wrong answer changes what is rendered —
     * so the enum makes every caller handle each case explicitly rather than
     * guessing from a boolean.
     */
    private function scriptInterpolationPosition(): ScriptInterpolationPosition
    {
        if (!$this->scriptInScriptText()) {
            return ScriptInterpolationPosition::Code;
        }

        if ($this->scriptQuoteChar !== ''
            || $this->scriptInLineComment
            || $this->scriptInBlockComment
        ) {
            return ScriptInterpolationPosition::StringContent;
        }

        if ($this->scriptInRegex) {
            return ScriptInterpolationPosition::RegexLiteral;
        }

        // A tag directly in a template literal's text has no output form, but one
        // inside `${ … }` is a value again and takes the code form.
        if ($this->scriptInTemplate && !$this->scriptInTemplateExpr) {
            return ScriptInterpolationPosition::TemplateLiteral;
        }

        return ScriptInterpolationPosition::Code;
    }

    /**
     * Whether the expression being compiled is emitted as string content.
     */
    private function currentScriptQuoteInside(): bool
    {
        return $this->scriptInterpolationPosition() === ScriptInterpolationPosition::StringContent;
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

            // A template literal's text is opaque: `//`, `/*`, quotes and braces
            // mean nothing until a `${ … }` resumes ordinary JavaScript. A
            // backslash escapes the next character, and only a backtick closes it.
            if ($this->scriptInTemplate && !$this->scriptInTemplateExpr) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === '`') {
                    $this->scriptInTemplate      = false;
                    $this->scriptLastSignificant = '`';
                    $this->scriptWord            = '';
                    continue;
                }
                if ($char === '$' && $next === '{') {
                    $this->scriptInTemplateExpr    = true;
                    $this->scriptTemplateExprDepth = 0;
                    $i++;
                }
                continue;
            }

            if ($this->scriptInTemplateExpr) {
                // `${ … }` holds ordinary JavaScript, so the string, comment and
                // regex rules below apply again. Only the braces are counted, to
                // find the `}` that ends the interpolation — nested object
                // literals must not close it early.
                if ($char === '{') {
                    $this->scriptTemplateExprDepth++;
                } elseif ($char === '}') {
                    if ($this->scriptTemplateExprDepth === 0) {
                        $this->scriptInTemplateExpr = false;
                        $this->scriptWord           = '';
                        $this->scriptLastSignificant = '}';
                        continue;
                    }
                    $this->scriptTemplateExprDepth--;
                }
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

            if ($char === '`' && $this->scriptValueMayStart()) {
                $this->scriptInTemplate        = true;
                $this->scriptInTemplateExpr    = false;
                $this->scriptTemplateExprDepth = 0;
                $this->scriptWord              = '';
                continue;
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
