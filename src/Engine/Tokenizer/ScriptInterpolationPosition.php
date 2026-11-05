<?php

namespace Clarity\Engine\Tokenizer;

/**
 * Where a `{{ … }}` tag sits inside JavaScript, which selects the output form.
 *
 * This exists because `js` context has two output forms and the position picks
 * between them. A wrong pick changes what is rendered, so every position is named
 * and every caller must handle each one — no boolean that silently merges cases.
 *
 * @see ScriptQuoteTrait for how the position is determined, and for why the two
 *      forms are escaped string content versus a complete JSON literal.
 */
enum ScriptInterpolationPosition
{
    /**
     * A value position — `var x = {{ v }};`. Emitted as a complete JSON literal,
     * which for a number is `3` and for a string is `"Ada"`.
     */
    case Code;

    /**
     * Inside a `"…"`, `'…'` or `// …` — emitted as escaped string content, which
     * cannot close the literal or comment it sits in.
     */
    case StringContent;

    /**
     * Inside `/…/ `. Rejected: neither form is meaningful inside a regular
     * expression, and a value there would be spliced into a pattern.
     */
    case RegexLiteral;

    /**
     * Inside a `` `…` `` outside `${ … }`. Rejected: a template literal is not
     * escaped by either form, so a value containing a backtick or `${` could break
     * out — and, unlike a normal string, would then be executed.
     */
    case TemplateLiteral;
}
