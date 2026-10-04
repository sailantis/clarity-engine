<?php

namespace Clarity\Engine\Tokenizer;

use Clarity\ClarityException;

/**
 * Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.
 */
trait ExpressionCoreTrait
{

    // -------------------------------------------------------------------------
    // Expression processing
    // -------------------------------------------------------------------------

    /**
     * Convert a Clarity expression string to a PHP expression string.
     *
     * The pipeline (|>) is processed first; the leftmost segment is the
     * expression and each subsequent segment is a filter call.
     *
     * @param string $expression Raw expression from inside {{ ... }} or the
     *                           right-hand side of {% set var = ... %}.
     * @param bool   $autoEscape When true and there is no |> raw at the end,
     *                           wraps the whole result in htmlspecialchars().
     * @return string PHP expression (no leading <?= or trailing ?>).
     */
    /**
     * Set the output-escaping context for the next processExpression() call.
     * Called by the Compiler as it tracks the current position in the template.
     *
     * @param string $context  'html' | 'js' | 'css'
     */
    public function setEscapeContext(string $context): void
    {
        $this->escapeContext = $context;
    }

    public function processExpression(string $expression): string
    {
        $this->autoEscape = true;
        [$expr, $filters] = $this->splitPipeline($this->normalizePipeOperator($expression));

        $phpExpr = $this->convertVarsAndOps($expr);

        // Wrap in filter calls (innermost first â†’ outermost last)
        foreach ($filters as $filterSegment) {
            $phpExpr = $this->buildFilterCall($filterSegment, $phpExpr);
        }

        if ($this->autoEscape) {
            $phpExpr = match ($this->escapeContext) {
                'js'    => '\\json_encode(' . $phpExpr . ', 271)', // HEX_TAG|HEX_AMP|HEX_APOS|HEX_QUOT|UNESCAPED_UNICODE
                'css'   => '(string)(' . $phpExpr . ')',           // raw â€” CSS values are not HTML-escaped
                default => "\\htmlspecialchars((string)({$phpExpr}), 11, 'UTF-8')",
            };
        }

        return $phpExpr;
    }

    /**
     * Convert a Clarity expression without pipeline â€” used for control
     * structure conditions (if, for, set) where auto-escape is meaningless.
     *
     * @param string $expression Raw Clarity expression.
     * @return string PHP expression.
     */
    public function processCondition(string $expression): string
    {
        [$expr, $filters] = $this->splitPipeline($this->normalizePipeOperator($expression));
        $phpExpr = $this->convertVarsAndOps($expr);

        foreach ($filters as $filterSegment) {
            $phpExpr = $this->buildFilterCall($filterSegment, $phpExpr);
        }

        return $phpExpr;
    }

    /**
     * Convert a Clarity variable chain to its PHP lvalue equivalent, for the
     * left-hand side of {% set var = ... %}.
     *
     * Scope-aware by construction: open mode seeds the render scope into locals,
     * so `{% set a = â€¦ %}` compiles to a plain `$a = â€¦` and both worlds read the
     * SAME slot.  Sandbox mode targets `$__c_va['a']` exactly as before.  The
     * choice lives in the chain emitter, so it cannot drift from the read path.
     *
     * @param string $var Clarity variable name (e.g. 'user.name', 'items[0]').
     * @return string PHP lvalue (e.g. '$user', or '$__c_va[\'user\'][\'name\']').
     */
    public function processLvalue(string $var): string
    {
        return $this->varChainToPhp(\trim($var));
    }

    /**
     * Convert a Clarity expression (no pipeline) to PHP by:
     * 1. Replacing var-chains with $__c_va[...] accesses
     * 2. Replacing logical/string operators with PHP equivalents
     * 3. Rejecting function-call syntax: any identifier followed by '(' throws
     *    a ClarityException at compile time â€” use the |> filter pipeline instead.
     *
     * Strategy: tokenize the expression into atoms (quoted strings, numbers,
     * identifiers/var-chains, operators, punctuation) and process each atom.
     */
    public function convertVarsAndOps(string $expr): string
    {
        static $keywordMap = [
            'and'   => '&&',
            'or'    => '||',
            'not'   => '!',
            'bor'   => '|',
            'band'  => '&',
            'bxor'  => '^',
            'bnot'  => '~',
            'blsh'  => '<<',
            'brsh'  => '>>',
            'true'  => 'true',
            'false' => 'false',
            'null'  => 'null',
        ];

        $len      = \strlen($expr);
        $i        = 0;
        $out      = '';
        $inSingle = false;
        $inDouble = false;

        // Ternary tracking, LOCAL to this call so parentheses get their own
        // scope for free: `(` recurses through processCondition(), and the paren
        // body is compiled by a fresh convertVarsAndOps() with its own state.
        // That is what lets `cond ? (foo ? bar : blubb) : blobb` nest to any
        // depth without a shared counter.
        //
        // `$ternarySeen` answers the one question the parser cannot answer from
        // spacing alone: whether a `:` is a ternary separator or an array-key
        // read. Once a ternary is open, a colon only reads as a key when it is
        // glued to both sides.
        //
        // `$ternaryPhases` records the branch each open ternary is in, because
        // PHP rejects a ternary CHAINED in an else position
        // (`a ? b : c ? d : e`) while accepting the nested then-branch form
        // (`a ? b ? c : d : e`). Catching that here turns a fatal PHP parse
        // error â€” which happens when the generated class is loaded and cannot be
        // caught â€” into a normal compile error with a line number.
        $ternarySeen     = false;
        $ternaryPhases   = [];
        $ternaryOpenedAt = [];

        while ($i < $len) {
            $ch = $expr[$i];

            if (($inSingle || $inDouble) && $ch === '\\' && ($i + 1) < $len) {
                $out .= $ch . $expr[$i + 1];
                $i += 2;
                continue;
            }

            // Quote handling
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                $out .= $ch;
                $i++;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                $out .= $ch;
                $i++;
                continue;
            }
            if ($inSingle || $inDouble) {
                // A string literal is TEXT, never PHP interpolation.  A `$`
                // inside a double-quoted string would otherwise be interpolated
                // by PHP (`"$name"`, and the deprecated `"${name}"`) â€” a leak of
                // PHP semantics into a template literal, and the reason
                // `{{ "${x}" }}` used to emit a deprecation instead of the
                // literal text.  Escaping the dollar keeps the literal literal.
                if ($inDouble && $ch === '$') {
                    $out .= '\\$';
                    $i++;
                    continue;
                }
                $out .= $ch;
                $i++;
                continue;
            }

            // A ternary `?` opens a branch that ends at the next `:` that is not
            // an array-key read. `??`, `?.`, `?[`, `?{` and `?->` are separate
            // operators and leave the state alone. `?:` (optional key) is glued
            // to a key, so it is not a ternary either.
            if ($ch === '?') {
                $next = $expr[$i + 1] ?? '';
                if ($next !== '?' && $next !== '.' && $next !== '[' && $next !== '{' && $next !== ':') {
                    $isArrow = $next === '-' && ($expr[$i + 2] ?? '') === '>';
                    if (
                        !$isArrow
                            && $i > 0 && \ctype_space($expr[$i - 1])
                            && $next !== '' && \ctype_space($next)
                    ) {
                        // A ternary opening inside an ELSE branch would be
                        // chained, and PHP rejects `a ? b : c ? d : e` outright.
                        // Because the parenthesised form recurses, an else
                        // branch that is wrapped in `(` never reaches here, so
                        // reaching it is unambiguous.
                        if (\end($ternaryPhases) === 'else') {
                            throw new ClarityException(
                                "Unparenthesised nested ternary: PHP parses 'a ? b : c ? d : e' as invalid. "
                                    . "Wrap the else-branch in parentheses, as in 'a ? b : (c ? d : e)', in '{$expr}'."
                            );
                        }

                        // `cond ? cond2 ? x : y : z` nests in the THEN branch,
                        // which PHP accepts and this engine already supported.
                        $ternarySeen = true;
                        $ternaryPhases[] = 'then';
                        $ternaryOpenedAt[] = $i;
                    }
                }
            }

            // Ternary separator: reached only when a ternary is open AND the
            // colon is not a key read. Chains are compiled before this point, so
            // a glued `a:b` is consumed as a key and never arrives here.
            //
            // A ternary colon may be spaced on either side (`x : y`, `x: y`,
            // `x :y`) because an open ternary makes the reading unambiguous. Two
            // spellings are errors rather than ambiguity:
            //   â€¢ `?:` glued both sides is the optional-key operator.
            //   â€¢ a colon glued to a following identifier would read as a key
            //     chain, which is the trap this whole rule exists to avoid.
            if ($ch === ':' && $ternarySeen && !$this->isChainColon($expr, $i, true)) {
                $phase  = array_pop($ternaryPhases) ?? 'then';
                $opened = array_pop($ternaryOpenedAt) ?? 0;

                // An empty then-branch would compile to PHP's `?:` shorthand
                // (`a ?: b`), which tests the CONDITION for truthiness instead
                // of reading an optional key. The two spellings differ by one
                // space, so this is reported rather than left to mean something
                // the author did not write.
                $then = \trim(\substr($expr, $opened + 1, $i - $opened - 1));
                if ($then === '') {
                    throw new ClarityException(
                        "Ternary is missing its then-branch in '{$expr}'. "
                            . "For an optional array key write 'name?:key' without the space."
                    );
                }

                $j = $i + 1;
                while ($j < $len && \ctype_space($expr[$j])) {
                    $j++;
                }
                $after = $expr[$j] ?? '';

                // `else if` is not a Clarity construct; an if-chain is a nested
                // ternary whose else-branch is itself a ternary. PHP only parses
                // that when the branch is parenthesised, so require it here
                // rather than emitting code PHP will refuse to load.
                if (
                    $this->startsIdentifier($after)
                        && substr($expr, $j, 2) === 'if'
                        && !$this->isIdentChar($expr[$j + 2] ?? '')
                ) {
                    throw new ClarityException(
                        "Nested ternary else-branch must be parenthesised: write "
                            . "'cond ? x : (cond2 ? y : z)' instead of using 'else if' in '{$expr}'."
                    );
                }

                // The `else` branch may not itself be an unparenthesised ternary:
                // PHP rejects `a ? b : c ? d : e` outright. `a ? b ? c : d : e`
                // is fine (then-branch), and a parenthesised else-branch starts a
                // fresh scope where a new ternary is legal â€” this call recurses
                // for it via processCondition(), so the tracked phase there is
                // independent.
                //
                // Spacing around this colon carries no meaning beyond what
                // isChainColon() already decided: reaching this point means the
                // colon is a separator, so `x : y`, `x: y` and `x :y` are all
                // ternaries. (A colon glued on BOTH sides is a key read and was
                // consumed above, which is what keeps `cond ? a:b : c` working.)
                $next = $expr[$i + 1] ?? '';
                if ($phase === 'then' && $next !== '(' && $next !== '') {
                    $ternaryPhases[] = 'else';
                }

                $ternarySeen = $ternaryPhases !== [];
            }

            // Map single-char operator ~ outside strings
            if ($ch === '~') {
                $out .= '.';
                $i++;
                continue;
            }

            // Disallow statement delimiters and backticks anywhere outside of strings
            if ($ch === ';' || $ch === '`' || ($ch === '?' && ($expr[$i + 1] ?? '') === '>') || ($ch === '<' && ($expr[$i + 1] ?? '') === '?')) {
                throw new ClarityException('Expressions must not contain statement delimiters or backticks.');
            }

            // Disallow heredoc/nowdoc openers: <<< would compile to a PHP heredoc
            // inside the generated echo statement, enabling code injection.
            if ($ch === '<' && substr($expr, $i, 3) === '<<<') {
                throw new ClarityException('Heredoc/nowdoc syntax (<<<) is not allowed in Clarity expressions.');
            }

            // A `?->` that reaches here has no `$` sigil, so it is a plain
            // expression. Left alone it would be copied through as raw PHP
            // nullsafe syntax â€” a leak the sigil rule exists to prevent â€” and a
            // SPACED `? ->` is a syntax error in PHP rather than a nullsafe read.
            if ($ch === '?' && ($expr[$i + 1] ?? '') === '-') {
                $afterArrow = $expr[$i + 2] ?? '';
                if ($afterArrow === '>') {
                    throw new ClarityException(
                        "PHP-style property access ('->') requires the \$ sigil: "
                            . "write \$a?->b or a?.b instead of '?->' in '{$expr}'."
                    );
                }
            }

            // A leading `\` opens a fully qualified class name. `\Foo::bar()` and
            // `new \Foo()` are read by the construct handlers, which tolerate the
            // separator; anything else is a class name in a place that cannot use
            // one, so it is reported rather than emitted as a stray backslash.
            if ($ch === '\\') {
                $static = $this->tryCompileStaticCall($expr, $i, $len);
                if ($static !== null) {
                    if (!$this->allows('staticCalls')) {
                        throw new ClarityException(
                            "Static calls ('::') are not allowed by this policy. "
                                . "Grant the 'staticCalls' rule to allow them."
                        );
                    }
                    $out .= $static['php'];
                    $i = $static['end'];
                    continue;
                }

                $qualified = $this->readQualifiedName($expr, $i, $len);
                if ($qualified !== null) {
                    [$rawName] = $qualified;
                    throw new ClarityException(
                        "A PHP class name ('{$rawName}') is not allowed here in "
                            . "'{$expr}'. Use 'new {$rawName}(…)' to build it, "
                            . "'{$rawName}::…' to reach a static member, or "
                            . "'x instanceof {$rawName}' to test it."
                    );
                }
            }

            // The `$` sigil introduces a PHP-style chain: $user->name, $a.b,
            // $a?.b. It is the ONLY way to spell `->`; a bare `a->b` is rejected
            // by parseVarChainAt() so raw PHP property syntax can never be
            // emitted from an unmarked expression.
            if ($ch === '$') {
                $sigilStart = $i + 1;
                $next       = $expr[$sigilStart] ?? '';

                // `${expr}` â€” and its shorthand `$$name` â€” read the variable
                // whose NAME is produced by an expression.  The two spellings
                // are the same construct (`$$name` is `${name}`) and compile to
                // the same lookup.
                //
                // The lookup resolves against `$__c_va` and the loop-local map
                // (never a PHP dynamic variable), so it can reach neither a
                // superglobal nor an engine internal: a name such as `__c_fn` is
                // simply absent from the scope.  An absent name is STRICT unless
                // a `??` follows, in which case the absent branch yields null so
                // the operator can supply the fallback â€” exactly the behaviour of
                // a literal `{{ name }}` / `{{ name ?? 'x' }}`.
                if ($next === '{' || $next === '$') {
                    if ($next === '{') {
                        [$inner, $end] = $this->extractBalancedSegment($expr, $sigilStart);
                        $nameRaw = \trim($inner);
                        if ($nameRaw === '') {
                            throw new ClarityException('Dynamic variable name must not be empty (${}).');
                        }
                        $namePhp = $this->processCondition($nameRaw);
                    } else {
                        $nameEnd = $sigilStart + 1; // after the second '$'
                        if (!self::isIdentifierStart($expr[$nameEnd] ?? '')) {
                            throw new ClarityException(
                                "Direct PHP variable access ('\$') is not allowed in Clarity expressions; "
                                    . "use a variable name after the sigil (\$name) or dot-notation (name.field)."
                            );
                        }
                        $nameEnd++;
                        while ($nameEnd < $len && self::isIdentifierChar($expr[$nameEnd])) {
                            $nameEnd++;
                        }
                        // `$$name` names the variable read from `name` itself.
                        $namePhp = $this->processCondition(\substr($expr, $sigilStart + 1, $nameEnd - $sigilStart - 1));
                        $end     = $nameEnd;
                    }

                    $k = $end;
                    while ($k < $len && \ctype_space($expr[$k])) {
                        $k++;
                    }
                    $coalesces = ($expr[$k] ?? '') === '?' && ($expr[$k + 1] ?? '') === '?';

                    $root = $this->buildDynamicLookup($namePhp, $coalesces);

                    // Chained access applies to the LOOKED-UP value:
                    // `${ref}.name`, `${ref}[0]`.
                    [$root, $i] = $this->compilePostfixAccessChain($expr, $end, $root);
                    $out .= $root;
                    continue;
                }

                if (!self::isIdentifierStart($next)) {
                    throw new ClarityException(
                        "Direct PHP variable access ('\$') is not allowed in Clarity expressions; "
                            . "use a variable name after the sigil (\$name) or dot-notation (name.field)."
                    );
                }

                $parsed = $this->parseVarChainAt($expr, $sigilStart, true, $ternarySeen, $this->allows('methodCalls'));
                if ($parsed === null) {
                    $out .= $ch;
                    $i++;
                    continue;
                }

                $i        = $parsed['end'];
                $segments = $parsed['segments'];
                $token    = \substr($expr, $sigilStart, $i - $sigilStart);

                // A `(` that survives chain parsing is a call on the ROOT value
                // (e.g. `$fn()`), not a method call â€” method calls are consumed
                // into their property segment.  Root invocation stays rejected:
                // a variable-driven callable is the function-level equivalent of
                // variable-variable expansion.  That rule is INDEPENDENT of the
                // policy, so its message must not name a rule that would not
                // help.
                $j = $i;
                while ($j < $len && \ctype_space($expr[$j])) {
                    $j++;
                }
                if ($j < $len && $expr[$j] === '(') {
                    $context = \substr($expr, \max(0, $sigilStart - 10), 70);

                    // A chain that reached a member (`$obj->m`) is a method call the
                    // rule can grant, so that message names the grant. Only a
                    // root-only chain (`$fn`) is the callable-variable case, which no
                    // grant would fix — the two are told apart by segment count.
                    if (\count($segments) > 1) {
                        throw new ClarityException(
                            "Method calls are not allowed by this policy: '\${$token}(...)' in context '{$context}'. "
                                . "Grant the 'methodCalls' rule to allow them."
                        );
                    }

                    throw new ClarityException(
                        "A call on the root value is not allowed: '\${$token}(...)' in context '{$context}'. "
                            . "A variable-driven callable is not a method call; call a property instead ('\$obj->m(...)')."
                    );
                }

                if (isset($this->localVars[$segments[0]['value']])) {
                    $out .= $this->buildVarChainPhpWithLocalRoot($segments);
                } else {
                    $out .= $this->varChainToPhpWithSegments('$' . $token, $segments);
                }
                continue;
            }

            if (
                $ch === '.'
                    && ($expr[$i + 1] ?? '') === '.'
                    && ($expr[$i + 2] ?? '') === '.'
            ) {
                throw new ClarityException('Spread operator is only allowed inside array and object literals.');
            }

            if ($ch === '[' || $ch === '{') {
                [$literalPhp, $i] = $this->parseCollectionLiteralAt($expr, $i);
                $out .= $literalPhp;
                continue;
            }

            // `new Foo(...)` names a class rather than a scope value, so it is
            // recognised explicitly: it is an accepted Clarity keyword whose
            // operand must not resolve through the scope.  The rule check
            // lives here, where the keyword is unambiguous.
            if (
                $ch === 'n' && \substr($expr, $i, 3) === 'new'
                    && !self::isIdentifierChar($expr[$i + 3] ?? '')
                    && !($i > 0 && self::isIdentifierChar($expr[$i - 1]))
            ) {
                if (!$this->allows('newExpressions')) {
                    throw new ClarityException(
                        "'new' is not allowed by this policy: it would let a template build any object it names. "
                            . "Grant the 'newExpressions' rule to allow it."
                    );
                }
                $end = null;
                $out .= $this->compileNewExpression($expr, $i + 3, $len, $end);
                $i = $end ?? $i + 3;
                continue;
            }

            // `instanceof` takes a CLASS name on the right, so the operand is
            // compiled as a name rather than as an expression.  This is grammar,
            // not a rule: it is how the operator has to work.
            if (
                $ch === 'i' && \substr($expr, $i, 10) === 'instanceof'
                    && !self::isIdentifierChar($expr[$i + 10] ?? '')
                    && !($i > 0 && self::isIdentifierChar($expr[$i - 1]))
            ) {
                $end = null;
                $out .= 'instanceof ' . $this->compileInstanceofOperand($expr, $i + 10, $len, $end);
                $i = $end ?? $i + 10;
                continue;
            }

            if ($ch === '(') {
                [$inner, $end] = $this->extractBalancedSegment($expr, $i);
                $out .= '(' . $this->processCondition($inner) . ')';
                $i = $end;
                continue;
            }

            // Identifier / var-chain detection
            if (self::isIdentifierStart($ch)) {
                $start = $i;

                // --- Performance: try the cache with just the raw identifier first.
                // For simple single-word names (the dominant case) this avoids calling
                // parseVarChainAt() at all.  We peek ahead to find the identifier end,
                // check the cache, and only fall through to full parsing on a miss or
                // when the identifier is followed by '.' or '['.
                $idEnd = $i + 1;
                while ($idEnd < $len && self::isIdentifierChar($expr[$idEnd])) {
                    $idEnd++;
                }
                $nextAfterIdent = $expr[$idEnd] ?? '';
                $nextTwo        = \substr($expr, $idEnd, 2);

                // Whitespace may separate an identifier from its continuation,
                // so the fast path must look past it before deciding that this
                // is a plain name. Doing so here (rather than after the token is
                // emitted) is what keeps `user.\nname` on the chain path.
                $contPos = $idEnd;
                while ($contPos < $len && \ctype_space($expr[$contPos])) {
                    $contPos++;
                }
                $contChar = $expr[$contPos] ?? '';
                $contTwo  = \substr($expr, $contPos, 2);

                // Twig-style infix/prefix TESTS: `x in y`, `x is defined`,
                // `x is not empty`, `x starts with y`, `x matches p`, …
                // Dispatched on a word boundary so an ordinary variable named
                // `in`/`is` (used as `{{ in }}`) is untouched. This runs BEFORE
                // the chain-continuation branch below, because a right operand
                // that starts with `[`/`(` would otherwise read as an index read
                // (`x in [1,2,3]` mis-compiling to `$__c_va['in'][…]`).
                if (!($start > 0 && self::isIdentifierChar($expr[$start - 1]))) {
                    if ($this->tryCompileOperatorTest($expr, $start, $idEnd, $ternarySeen, $out, $i)) {
                        continue;
                    }
                }

                // Static access on a bare class name: `DateTime::createFromFormat(…)`.
                // This MUST run before the chain branch below, which sees the
                // first `:` of `::` as a chain colon and would read
                // `:createFromFormat` as an (empty) array key.
                if (($expr[$idEnd] ?? '') === ':' && ($expr[$idEnd + 1] ?? '') === ':') {
                    $static = $this->tryCompileStaticCall($expr, $start, $len);
                    if ($static !== null) {
                        if (!$this->allows('staticCalls')) {
                            throw new ClarityException(
                                "Static calls ('::') are not allowed by this policy. "
                                    . "Grant the 'staticCalls' rule to allow them."
                            );
                        }
                        $out .= $static['php'];
                        $i = $static['end'];
                        continue;
                    }
                }

                // A `\` inside an identifier position is a NAMESPACE separator,
                // not an operator. `\DateTime` and `Foo\Bar` are single names, so
                // they are read by the construct handlers rather than left for the
                // operator loop (which would emit a stray backslash and an invalid
                // PHP expression).
                if (($expr[$idEnd] ?? '') === '\\') {
                    $qualified = $this->readQualifiedName($expr, $start, $len);
                    if ($qualified !== null) {
                        [$rawName] = $qualified;
                        throw new ClarityException(
                            "A PHP class name ('{$rawName}') is not allowed here in "
                                . "'{$expr}'. Use 'new {$rawName}(…)' to build it, "
                                . "'{$rawName}::…' to reach a static member, or "
                                . "'x instanceof {$rawName}' to test it."
                        );
                    }
                }

                // Open-mode filter placeholder: a lone `_` stands for the piped
                // value while compiling a PHP function's argument list.  It is
                // recognised ONLY there (and only when not a chain root), so a
                // template variable named `_` keeps working everywhere else.
                if (
                    $this->inOpenFilterArgs
                        && $idEnd - $start === 1
                        && $expr[$start] === '_'
                        && $contChar !== '('
                        && $contChar !== '.'
                        && $contChar !== '['
                        && $contChar !== '{'
                ) {
                    $out .= '(' . $this->openFilterValue . ')';
                    $i = $idEnd;
                    continue;
                }

                if (
                    $contChar !== '.' && $contChar !== '['
                        && $contChar !== '{' && $contChar !== ':'
                        && $contChar !== '?' && $contTwo !== '->'
                ) {
                    // Plain identifier â€” may be a keyword or a cacheable single-segment chain
                    $token = \substr($expr, $start, $idEnd - $start);
                    $i     = $idEnd;

                    $prevChar = ($start - 1 >= 0) ? $expr[$start - 1] : null;
                    $nextChar = $nextAfterIdent !== '' ? $nextAfterIdent : null;
                    $prevIsId = $prevChar !== null && self::isIdentifierChar($prevChar);
                    $nextIsId = $nextChar !== null && self::isIdentifierChar($nextChar);
                    $lower    = \strtolower($token);

                    // The `::` and namespace forms were already handled above,
                    // where the identifier's tail was visible: both either emit
                    // and `continue`, or throw. Reaching here means neither tail
                    // applied, so this is an ordinary name or a keyword.
                    if (!$prevIsId && !$nextIsId && isset($keywordMap[$lower])) {
                        $out .= $keywordMap[$lower];
                        continue;
                    }

                    // Function-call syntax: allowed only for explicitly registered functions.
                    $j = $i;
                    while ($j < $len && \ctype_space($expr[$j])) {
                        $j++;
                    }
                    if ($j < $len && $expr[$j] === '(') {
                        if ($this->registry !== null && $this->registry->hasFunction($token)) {
                            [$call, $i] = $this->buildFunctionCallInExpr($token, $expr, $j, $len);
                            $out .= $call;
                            continue;
                        }
                        // A bare call resolves to PHP only when the
                        // `phpFunctions` rule is on.  Registered names bypass
                        // this entirely — they are the engine's own vocabulary.
                        if ($this->policy->allows('phpFunctions')) {
                            if (!$this->isFunctionCallAllowed($token)) {
                                throw new ClarityException(
                                    "Function '{$token}()' is not allowed by this policy: it is not in the "
                                        . 'function allowlist, or it is denied. Add it with allowFunctions().'
                                );
                            }
                            [$call, $i] = $this->buildFunctionCallInExpr($token, $expr, $j, $len);
                            $out .= $call;
                            continue;
                        }
                        $context = \substr($expr, \max(0, $start - 10), \min(60, $len - $start + 10));
                        throw new ClarityException(
                            "Call to unregistered function '{$token}()' in context '{$context}'. "
                                . "Grant the 'phpFunctions' rule to allow PHP function calls, then add the name "
                                . 'with allowFunctions().'
                        );
                    }

                    // Check local vars (loop variables) before the cache: a locally-bound
                    // variable must resolve to its PHP local var, not to $__c_va['name'].
                    if (isset($this->localVars[$token])) {
                        $out .= $this->localVars[$token];
                        continue;
                    }

                    if (isset($this->varChainCache[$token])) {
                        $out .= $this->varChainCache[$token];
                    } else {
                        $parsed = $this->parseVarChainAt($expr, $start, false, $ternarySeen);
                        $php    = $parsed !== null
                            ? $this->varChainToPhpWithSegments($token, $parsed['segments'])
                            : $token;
                        if ($parsed !== null) {
                            $this->varChainCache[$token] = $php;
                        }
                        $out .= $php;
                    }
                    continue;
                }

                // Identifier followed by a chain continuation â€” full chain parsing required.
                // `allowArrow` stays FALSE (a bare `a->b` is rejected below), but the
                // call flag is the policy's, exactly as on the `$`-sigil path: the
                // rule, not the sigil, is what decides whether a method may be
                // called.  A bare `obj.m()` therefore compiles to the same PHP as
                // `$obj.m()`.
                $parsed = $this->parseVarChainAt($expr, $start, false, $ternarySeen, $this->allows('methodCalls'));
                if ($parsed === null) {
                    $out .= $ch;
                    $i++;
                    continue;
                }

                $segments = $parsed['segments'];

                // A chain that could not consume anything past the identifier is not
                // a chain at all (e.g. `a ? b`, `a - b`). Emit the bare name so the
                // operator path below handles the rest.
                if (\count($segments) === 1) {
                    $token = $segments[0]['value'];
                    $i     = $idEnd;
                    if (isset($this->localVars[$token])) {
                        $out .= $this->localVars[$token];
                    } elseif (isset($this->varChainCache[$token])) {
                        $out .= $this->varChainCache[$token];
                    } else {
                        $php = $this->varChainToPhpWithSegments($token, $segments);
                        $this->varChainCache[$token] = $php;
                        $out .= $php;
                    }
                    continue;
                }

                $i     = $parsed['end'];
                $token = \substr($expr, $start, $i - $start);

                // A call left over here follows the whole chain, so it is a call on a
                // KEY or INDEX read (`a:b(...)`, `a[b](...)`) â€” a call may attach only
                // to a property or dynamic-property segment, which the parser consumes
                // into the segment itself.  When the policy does not grant method
                // calls, that is the reason to report instead.
                $j = $i;
                while ($j < $len && \ctype_space($expr[$j])) {
                    $j++;
                }
                if ($j < $len && $expr[$j] === '(') {
                    $context = \substr($expr, \max(0, $start - 10), \min(60, $len - $start + 10));

                    if (!$this->allows('methodCalls')) {
                        throw new ClarityException(
                            "Method calls are not allowed by this policy: '{$token}(...)' in context '{$context}'. "
                                . "Grant the 'methodCalls' rule to allow them."
                        );
                    }

                    throw new ClarityException(
                        "A call must follow a property name, not a key or index read: '{$token}(...)' in context '{$context}'. "
                            . "Use a property chain ('a.b(...)') instead of a key ('a:b') or index ('a[b]') read."
                    );
                }
                // Check if the chain root is a locally-bound variable (loop var)
                if (isset($this->localVars[$segments[0]['value']])) {
                    $out .= $this->buildVarChainPhpWithLocalRoot($segments);
                } else {
                    $out .= $this->varChainToPhpWithSegments($token, $segments);
                }
                continue;
            }

            // A `.` that reached the fall-through is not concatenation and not a
            // chain continuation, so it is a mistake. Concatenation is `~`
            // (`a ~ b`); `.` always means property access. A valid chain never
            // arrives here, because parseVarChainAt() consumes the operator and
            // its member together.
            //
            // The one non-chain reading is a DECIMAL literal, which keeps its
            // dot: `1234.56` (digit on both sides) and `.5` (nothing binding on
            // the left). That distinction is what keeps a float from being
            // reported as a missing property name.
            if ($ch === '.') {
                $dotPrev = $i > 0 ? $expr[$i - 1] : '';
                $dotNext = $expr[$i + 1] ?? '';
                $binds   = \ctype_alnum($dotPrev) || $dotPrev === '_'
                    || $dotPrev === ')' || $dotPrev === ']' || $dotPrev === '}';

                if ((\ctype_digit($dotPrev) && \ctype_digit($dotNext)) || (!$binds && \ctype_digit($dotNext))) {
                    $out .= $ch;
                    $i++;
                    continue;
                }

                throw new ClarityException(
                    "Property access operator '.' must be followed by a property name in '{$expr}'. "
                        . "Use '~' for string concatenation."
                );
            }

            // default: copy
            $out .= $ch;
            $i++;
        }

        return $out;
    }

    /**
     * Parse a registered-function call starting at the opening '(' in $expr
     * and return the compiled PHP expression plus the new position after ')'.
     *
     * Each argument is compiled as a full Clarity expression (pipelines and
     * nested function calls work inside arguments). Named arguments use the
     * Clarity `name=expression` syntax and are emitted as PHP named arguments
     * (`name: phpExpr`).
     *
     * Generated code: $__c_fn['name']($phpArg1, name2: $phpArg2, ...)
     *
     * The `$__c_fn` local (unpacked from the class's `functions` property) is
     * used rather than `$this->functions` because the same emitter runs inside
     * `static fn` closures for quoted filter references, where `$this` is unbound.
     *
     * @param string $name      The function name (already validated as registered).
     * @param string $expr      The full expression string being compiled.
     * @param int    $openParen Position of the '(' character in $expr.
     * @param int    $len       Length of $expr.
     * @return array{0: string, 1: int}  [phpCallExpression, indexAfterClosingParen]
     */
    private function buildFunctionCallInExpr(string $name, string $expr, int $openParen, int $len): array
    {
        $i        = $openParen + 1; // skip '('
        $argStart = $i;
        $depth    = 1;
        $inSingle = false;
        $inDouble = false;

        while ($i < $len && $depth > 0) {
            $ch = $expr[$i];
            if (($inSingle || $inDouble) && $ch === '\\' && ($i + 1) < $len) {
                $i += 2;
                continue;
            }
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif (!$inSingle && !$inDouble) {
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            $i++;
        }

        $argsRaw = \substr($expr, $argStart, $i - $argStart);
        $i++; // consume closing ')'

        if (isset($this->prunedFunctions[$name])) {
            return ["''", $i];
        }

        switch ($name) {
            case 'vars':
            case 'context': // deprecated alias of vars(), kept so existing templates compile
                if (\trim($argsRaw) !== '') {
                    throw new ClarityException("{$name}() does not accept any arguments.");
                }
                return [$this->buildVarsCall(), $i];
            case 'include':
                $this->autoEscape = false;
                break;
        }

        // Open mode: a name that is not a registered function is emitted as a
        // direct PHP call.  Registered names keep precedence so engine- and
        // extension-defined functions are never shadowed by a PHP builtin.
        if ($this->registry === null || !$this->registry->hasFunction($name)) {
            $argList = \trim($argsRaw) !== '' ? $this->splitRespectingStrings($argsRaw, ',') : [];
            $callee  = '\\' . \ltrim($name, '\\');
            $call    = $callee . '(' . \implode(', ', $this->compileArgList($argList)) . ')';
            return [$call, $i];
        }

        // Context-injected function: prepend compile-time escape context as a
        // string literal first argument (e.g. dump/dd receive 'html'|'js'|'css').
        // These functions return raw HTML/JS markup — disable auto-escaping so
        // their output is never passed through htmlspecialchars/json_encode.
        if (isset($this->contextInjectedFunctions[$name])) {
            $this->autoEscape = false;
            $compiledArgs = ["'" . $this->escapeContext . "'"];
            if (\trim($argsRaw) !== '') {
                $argList = $this->splitRespectingStrings($argsRaw, ',');
                foreach ($this->compileArgList($argList) as $phpArg) {
                    $compiledArgs[] = $phpArg;
                }
            }
            return [$this->buildCall($name, $compiledArgs), $i];
        }

        // A registered callable (vars, include, json at runtime, user
        // addFunction) dispatches through $__c_fn.
        if ($this->registry->getCallable($name) !== null) {
            // map/filter/reduce take a lambda/filter-ref as their SECOND argument
            // in call form (`map(items, x => …)`); compile it like the filter form.
            if (isset(self::CALLABLE_ARG_FILTERS[$name]) && \trim($argsRaw) !== '') {
                $argList      = $this->splitCallableArgs($argsRaw, true);
                $compiledArgs = [];
                foreach ($argList as $idx => $arg) {
                    $compiledArgs[] = $idx === 1
                        ? $this->compileCallableArg(\trim($arg), $name)
                        : $this->processCondition(\trim($arg));
                }
                return [$this->buildCall($name, $compiledArgs), $i];
            }

            $compiledArgs = \trim($argsRaw) !== ''
                ? $this->compileArgList($this->splitRespectingStrings($argsRaw, ','))
                : [];
            return [$this->buildCall($name, $compiledArgs), $i];
        }

        // Inline-only filter used under call syntax: derive the call from the
        // same template as the filter form. This is where `round(x, 2)` and
        // `date('Y-m-d', ts)` compile without any runtime dispatch.
        $argList = \trim($argsRaw) !== '' ? $this->splitRespectingStrings($argsRaw, ',') : [];
        $inline  = $this->buildInlineCallForm($name, $argList);
        if ($inline !== null) {
            return [$inline, $i];
        }

        // Registered, but neither a runtime callable nor an inline template
        // (a bare `filter` marker with no implementation): call `$__c_fn` so the
        // failure is a clear runtime error rather than a compile-time dead end.
        $compiledArgs = \trim($argsRaw) !== ''
            ? $this->compileArgList($this->splitRespectingStrings($argsRaw, ','))
            : [];
        return [$this->buildCall($name, $compiledArgs), $i];
    }

    /**
     * Emit `vars()`: a snapshot of the template variables visible at this point.
     *
     * The two modes store variables differently, so the snapshot is built
     * differently — and both forms are chosen at COMPILE time:
     *
     *  • Sandbox — `$__c_va` IS the variable store. A `{% set %}` writes through
     *    it (even inside a loop), so the scope array is returned untouched.
     *
     *  • Open mode (`phpVariables`) — the scope is seeded into PHP locals with
     *    `extract()`, and every later write (a `{% set %}`, a `{% php %}`
     *    assignment, a loop variable) lands in a LOCAL. `$__c_va` is a snapshot of
     *    the seed, so it goes stale the moment anything is assigned; the locals
     *    are the store. `get_defined_vars()` is precisely "what is in scope
     *    here", which is what a variable dump should show — and it is exactly
     *    what a `{% php %}` block sees. `__c_`-prefixed names are engine internals
     *    and are filtered out.
     *
     * In BOTH modes a `{% for %}` variable and a macro parameter live only in a
     * PHP local (see {@see $dynamicBindings}) — nothing puts them into
     * `$__c_va`, so `{{ vars().v }}` inside `{% for v in … %}` would be absent
     * without gathering them. They are merged over the base scope, so a name that
     * is genuinely in the scope array keeps precedence: the snapshot must never
     * disagree with what printing the name reads.
     *
     * The merged array is a fresh COPY (`array_replace`), never assigned back into
     * the scope, so a `{% set %}` after this point writes the array every later
     * read sees. Assigning the merge back into `$__c_va` would break a
     * self-referential `{% set n = vars()|length %}`: the later `$__c_va['n'] = …`
     * would land in the copy the merge had already replaced.
     *
     * The locals are staged in `$__c_dyn` — a reserved name the render body
     * declares only when the template actually contains such a call, so the
     * `str_contains(…, '$__c_dyn')` probe in the emitter is a reliable signal.
     * That write is safe when the result is only read: a template may never bind a
     * `__c_`-prefixed name, and only one `vars()` call is in flight at a time.
     *
     * A plain scope read stays the bare `$__c_va` in sandbox mode — that case pays
     * nothing, exactly as a plain scope read does.
     */
    private function buildVarsCall(): string
    {
        // Open mode seeds the scope into PHP locals (`extract()`), and every later
        // write — a `{% set %}`, a `{% php %}` assignment, a loop variable — lands
        // in a LOCAL, so `$__c_va` stops being the variable store once anything is
        // assigned. There the locals ARE the scope, and `get_defined_vars()` is
        // exactly "what is in scope at this point". A `__c_`-prefixed name is an
        // engine internal and never a template variable, so those are dropped.
        //
        // Sandbox mode seeds nothing, so `$__c_va` IS the scope and is returned
        // untouched — the cheap path, and the behaviour a scope read always had.
        $scope = $this->localRoots
            ? '(\array_filter(\get_defined_vars(), static fn($k): bool => !\str_starts_with((string) $k, \'__c_\'), \ARRAY_FILTER_USE_KEY))'
            : '$__c_va';

        if ($this->dynamicBindings === []) {
            return $scope;
        }

        $entries = [];
        foreach (\array_keys($this->dynamicBindings) as $name) {
            // The mapped PHP local, not `'$' . $name`: a macro parameter is bound
            // to `$__c_m_<param>` (a namespaced local so it cannot collide with a
            // loop variable of the same name), while a loop variable is `$name`.
            $entries[] = \var_export($name, true) . ' => ' . ($this->localVars[$name] ?? '$' . $name);
        }

        return '(\array_replace(' . $scope . ', $__c_dyn = [' . \implode(', ', $entries) . ']))';
    }

    /**
     * Emit the lookup for `${expr}` / `$$name`: read the variable whose NAME is
     * produced by a runtime expression.
     *
     * The lookup uses the SAME variable model as a literal `{{ name }}`:
     *
     *  • sandbox mode     → `$__c_va[$name]`
     *  • open mode        → the render-frame LOCALS (`extract()` seeded the
     *                       scope into them), then a macro parameter's local
     *  • inside a loop    → loop locals first (they are real PHP locals, not
     *                       scope entries), then the above
     *
     * Open mode needs the locals because that is where a `{% set %}` and a
     * `{% php %}` assignment actually land: `$__c_va` is then only the seed
     * snapshot.  Reading `$__c_va` alone would make `${'x'}` disagree with a
     * literal `{{ x }}` whenever `x` was assigned rather than passed in.
     *
     * Presence is tested with `array_key_exists`, NOT `isset`: a NULL value is
     * PRESENT, exactly as a literal `{{ name }}` treats it (isset would report it
     * absent and trigger the strict throw).
     *
     * Security: the name only ever indexes `$__c_va` or the filtered locals /
     * compile-time known locals map.  It is never emitted as a PHP dynamic
     * variable, so it can reach neither a superglobal nor an engine internal.
     * The open-mode locals are filtered through the SAME predicate
     * {@see buildVarsCall()} uses for `vars()` — every `__c_`-prefixed key is
     * dropped — and a template can never bind such a name
     * (see Compiler::assertBindableName()), so `__c_fn` / `$this` stay
     * unreachable.  `get_defined_vars()` contains no superglobals.
     *
     * Absent names are STRICT (a line-numbered ClarityException) unless a `??`
     * follows the lookup, in which case the absent branch is `null` so the
     * operator supplies the fallback — mirroring a literal `{{ name ?? 'x' }}`.
     *
     * @param bool $coalesces Whether the character after the lookup is `??`.
     */
    private function buildDynamicLookup(string $namePhp, bool $coalesces): string
    {
        // When a `??` follows, the NAME expression is made null-safe too, so
        // `${ref} ?? 'x'` behaves like a literal `{{ name ?? 'x' }}`: absence in
        // either the name or the looked-up variable yields the fallback.  Without
        // this, an absent `ref` would warn while the name is evaluated â€” and that
        // happens INSIDE the guarding ternary, so the outer `??` would not
        // suppress it.
        $nameExpr = $coalesces
            ? '(string) ((' . $namePhp . ') ?? \'\')'
            : '(string) (' . $namePhp . ')';

        // The guard always assigns `$__c_tmp = (string)(name)` before testing, so
        // the miss branch can reuse it rather than evaluating the expression twice.
        $miss = $coalesces
            ? 'null'
            : 'throw new \\Clarity\\ClarityException("Undefined variable: " . $__c_tmp)';

        if ($this->localRoots) {
            // Open mode: the store is the render frame's locals, so the lookup
            // has to read them — filtered exactly as `vars()` is, so an engine
            // internal can never be named.  A name PHP keeps under a
            // `__c_`-prefixed local (a macro parameter, `$__c_m_p`) is necessarily
            // dropped by that filter, so the compile-time map supplies it.
            $scope = '(\array_filter(\get_defined_vars(), static fn($k): bool => '
                . '!\str_starts_with((string) $k, \'__c_\'), \ARRAY_FILTER_USE_KEY))';

            if ($this->localVars !== []) {
                $entries = [];
                foreach ($this->localVars as $tplName => $phpVar) {
                    $entries[] = \var_export($tplName, true) . ' => ' . $phpVar;
                }
                $scope = '(\array_replace(' . $scope . ', [' . \implode(', ', $entries) . ']))';
            }

            return '((array_key_exists($__c_tmp = ' . $nameExpr . ', $__c_loc = ' . $scope . ')'
                . ' ? $__c_loc[$__c_tmp]'
                . ' : (' . $miss . ')))';
        }

        if (!empty($this->localVars)) {
            $entries = [];
            foreach ($this->localVars as $tplName => $phpVar) {
                $entries[] = \var_export($tplName, true) . ' => 1';
            }
            $mapLiteral = '[' . \implode(', ', $entries) . ']';

            // The dynamic read `\${$__c_tmp}` is only reached when the name is
            // one of the compile-time known loop locals (the map literal), so it
            // can select only among variables the compiler bound itself.
            // The whole ternary is parenthesised so a following `??` binds to the
            // lookup RESULT, not into the else-branch.
            return '((\array_key_exists($__c_tmp = ' . $nameExpr . ', ' . $mapLiteral . ')'
                . ' ? ${$__c_tmp}'
                . ' : (array_key_exists($__c_tmp, $__c_va) ? $__c_va[$__c_tmp] : (' . $miss . '))))';
        }

        return '((array_key_exists($__c_tmp = ' . $nameExpr . ', $__c_va) ? $__c_va[$__c_tmp] : (' . $miss . ')))';
    }
}
