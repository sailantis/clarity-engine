<?php

namespace Clarity\Engine\Tokenizer;

use Clarity\ClarityException;

/**
 * Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.
 */
trait VarChainTrait
{

    /**
     * Parse a var-chain from $subject starting at $start.
     *
     * Returns null if no valid identifier starts at $start.
     *
     * Segment types
     * -------------
     *  key    array static key    a:b        a?:b
     *  index  array dynamic index a[i]       a?[i]
     *  prop   object property     a.b  a->b  a?.b  a?->b
     *  dyn    object dyn property a{k}       a?{k}
     *
     * The root segment is always `key` and resolves against the render scope;
     * only its NAME is used, so the `$` sigil form ($a.b) and the bare form
     * (a.b) produce identical segments.  A scope-seeded root (open mode) is
     * emitted as a PHP local, otherwise as a `$__c_va['name']` lookup.
     *
     * Every continuation carries an `optional` flag. Optional access is the
     * author's opt-out from the strict "missing access throws" contract.
     *
     * Whitespace
     * ---------
     * A chain continuation may be separated from the value it continues by ANY
     * amount of whitespace, including newlines, so a long chain can wrap
     * Go-style (`user.\naddress.\ncity`, `config:\nversion`). This is safe
     * precisely because `.` is NOT the concatenation operator â€” it always means
     * property access, so `a . b` has one reading and no ambiguity to preserve.
     *
     * Two operators must stay GLUED to the value on their left, because a
     * spaced spelling would collide with the ternary operator:
     *   â€¢ `?`  â€” a spaced `?` is a ternary; `? .` / `?[` / `?:` / `?->` written
     *            with a gap are therefore NOT optional access.
     *   â€¢ a `.` or `->` with no member after it is an authoring ERROR, not a
     *     value: there is nothing else it could mean.
     *
     * `:` â€” the ternary problem
     * -------------------------
     * A key colon is `:key`. Whitespace on either side is allowed
     * (`config : version` is the same read as `config:version`), EXCEPT while a
     * ternary is waiting for its branch separator: then a colon only counts as a
     * key read when it is glued to BOTH sides. That single rule keeps all of
     * these working, which no whitespace rule alone can do:
     *   cond ? x : y     -> ternary      (spaced both sides)
     *   cond ? x: y      -> ternary      (not glued right)
     *   cond ? x :y      -> ternary      (not glued left)
     *   cond ? a:b : c   -> key in then-branch, then the separator
     *
     * Optional access guards the RECEIVER only; the member read stays strict.
     * (`?->` on the object side, `=== null` guard on the array side.)
     *
     * @param bool $allowArrow      Whether `->` / `?->` count as property access.
     *                              True only for `$`-sigil roots; a bare `a->b` is
     *                              rejected by the caller.
     * @param bool $ternaryOpen  Whether a `?` is waiting for its branch
     *                              separator in the enclosing expression.
     * @param bool $allowCall    Whether a property read may be followed by a
     *                              method-call argument list (`m(...)`).  Driven
     *                              purely by the `methodCalls` rule, so it
     *                              holds on the bare path as well as the sigil
     *                              one — the rule, not the sigil, decides
     *                              whether a method may be called.
     * @return array{end:int, segments:array<int,array{type:string,value:string,optional:bool,call?:string}>}|null
     */
    private function parseVarChainAt(
        string $subject,
        int $start,
        bool $allowArrow = false,
        bool $ternaryOpen = false,
        bool $allowCall = false
    ): ?array {
        $len = \strlen($subject);
        if ($start >= $len) {
            return null;
        }

        $first = $subject[$start];
        if (!self::isIdentifierStart($first)) {
            return null;
        }

        $i = $start + 1;
        while ($i < $len && self::isIdentifierChar($subject[$i])) {
            $i++;
        }

        $root = [
            'type'     => 'key',
            'value'    => \substr($subject, $start, $i - $start),
            'optional' => false
        ];
        $segments = [$root];

        while ($i < $len) {
            $ch       = $subject[$i];
            $optional = false;

            // Whitespace before a chain continuation is not significant, so a
            // chain may wrap Go-style (`user.\naddress.\ncity`,
            // `config:\nversion`). Look past it, but ONLY when a continuation
            // really follows: if the next non-space character is an operator
            // (`+`, `?`, `and`, â€¦) the whitespace separates operands and the
            // chain ends here.
            if (\ctype_space($ch)) {
                $k = $i;
                while ($k < $len && \ctype_space($subject[$k])) {
                    $k++;
                }

                $nc      = $subject[$k] ?? '';
                $ncArrow = $nc === '-' && ($subject[$k + 1] ?? '') === '>';

                if (
                    $nc === '.' || $nc === '[' || $nc === '{' || $ncArrow
                        || ($nc === ':' && $this->isChainColon($subject, $k, $ternaryOpen))
                ) {
                    $i  = $k;
                    $ch = $nc;
                } else {
                    break;
                }
            }

            // ---- optional prefix: `?` glued to its continuation ------------
            if ($ch === '?') {
                $next    = $subject[$i + 1] ?? '';
                $isArrow = $next === '-' && ($subject[$i + 2] ?? '') === '>';

                if ($isArrow && !$allowArrow) {
                    // `a?->b` without the `$` sigil would otherwise fall through
                    // and emit raw PHP `?->`. Reject it here, where the `?` is
                    // still visible, rather than at the emission point. Note
                    // `?->` is THREE characters, so testing only the character
                    // after `?` never matches it.
                    throw new ClarityException(
                        "PHP-style property access ('->') requires the \$ sigil: "
                            . "write \${$root['value']}?->â€¦ or {$root['value']}?.â€¦ instead of {$root['value']}?->â€¦"
                    );
                }

                if (
                    $next === '.' || $next === '[' || $next === '{'
                        || ($isArrow && $allowArrow)
                        || ($next === ':' && $this->isGlued($subject, $i + 1, $ternaryOpen)
                            && $this->startsIdentifier($subject[$i + 2] ?? ''))
                ) {
                    $optional = true;
                    $i++;
                    $ch = $subject[$i];
                } else {
                    break; // `??` operator, a ternary `?`, or a spaced `?`
                }
            }

            // ---- property access: `.` or (`$`-sigil only) `->` --------------
            if ($ch === '.' || ($ch === '-' && ($subject[$i + 1] ?? '') === '>')) {
                $opLen = $ch === '-' ? 2 : 1;

                // A SPACED `.` or `->` after a ternary `?` is not a chain
                // continuation â€” `cond ? a : b` and `x ? .5 : 1` rely on this.
                if (!$this->isGlued($subject, $i + $opLen, $ternaryOpen)) {
                    break;
                }

                if ($ch === '-') {
                    // `->` is raw PHP property syntax. It is reachable two ways:
                    // as a bare `a->b` (checked below), and as `a?->b`, where the
                    // optional prefix has already consumed the `?` and would
                    // otherwise fall through to the same emission. Both must be
                    // rejected when the chain has no `$` sigil, or raw PHP
                    // `?->` would be emitted from a plain expression.
                    if (!$allowArrow) {
                        throw new ClarityException(
                            "PHP-style property access ('->') requires the \$ sigil: "
                                . "write \${$root['value']}->â€¦ (or {$root['value']}?.â€¦) instead of {$root['value']}->â€¦"
                        );
                    }
                }

                // Whitespace after the operator is allowed: a chain may wrap.
                $j = $i + $opLen;
                while ($j < $len && \ctype_space($subject[$j])) {
                    $j++;
                }

                // Dynamic property/method name after `->`: `$obj->{$m}` and
                // `$obj->{$m}(...)`.  Emitted as a `dyn` segment, exactly like
                // the standalone `a{k}` form.
                if ($j < $len && $subject[$j] === '{') {
                    [$inner, $end] = $this->extractBalancedSegment($subject, $j);

                    $segIndex = \count($segments);
                    $segments[] = [
                        'type'     => 'dyn',
                        'value'    => $inner,
                        'optional' => $optional,
                    ];
                    $i = $end;

                    if ($allowCall) {
                        $k2 = $i;
                        while ($k2 < $len && \ctype_space($subject[$k2])) {
                            $k2++;
                        }
                        if ($k2 < $len && $subject[$k2] === '(') {
                            [$callArgs, $callEnd] = $this->extractBalancedSegment($subject, $k2);
                            $segments[$segIndex]['call'] = $callArgs;
                            $i = $callEnd;
                        }
                    }
                    continue;
                }

                if ($j >= $len || !self::isIdentifierStart($subject[$j])) {
                    // A dangling `.` is never concatenation: `.` always means
                    // property access, so an operator with no member after it is
                    // an authoring mistake. Reporting it here is what stops a
                    // typo from becoming a silent string concat.
                    throw new ClarityException(
                        "Property access operator '"
                            . ($ch === '-' ? '->' : '.')
                            . "' must be followed by a property name in '"
                            . \substr($subject, $start) . "'."
                    );
                }

                $idStart = $j;
                $i       = $idStart + 1;
                while ($i < $len && self::isIdentifierChar($subject[$i])) {
                    $i++;
                }

                $segIndex = \count($segments);
                $segments[] = [
                    'type'     => 'prop',
                    'value'    => \substr($subject, $idStart, $i - $idStart),
                    'optional' => $optional,
                ];

                // Method call: attach the argument list to the property segment
                // so a nullsafe receiver stays one `?->m(args)` expression (PHP
                // short-circuits the whole call) instead of a guarded read that
                // a trailing `(...)` could never legally follow.
                if ($allowCall) {
                    $k2 = $i;
                    while ($k2 < $len && \ctype_space($subject[$k2])) {
                        $k2++;
                    }
                    if ($k2 < $len && $subject[$k2] === '(') {
                        [$callArgs, $callEnd] = $this->extractBalancedSegment($subject, $k2);
                        $segments[$segIndex]['call'] = $callArgs;
                        $i = $callEnd;
                    }
                }
                continue;
            }

            // ---- dynamic access: `[expr]` (array) or `{expr}` (property) ----
            if ($ch === '[' || $ch === '{') {
                $close = $ch === '[' ? ']' : '}';
                $i++;
                $innerStart = $i;
                $depth      = 1;

                while ($i < $len) {
                    $cc = $subject[$i];

                    if ($cc === "'" || $cc === '"') {
                        $quote = $cc;
                        $i++;
                        while ($i < $len) {
                            if ($subject[$i] === '\\' && ($i + 1) < $len) {
                                $i += 2;
                                continue;
                            }
                            if ($subject[$i] === $quote) {
                                $i++;
                                break;
                            }
                            $i++;
                        }
                        continue;
                    }

                    if ($cc === '[' || $cc === '{') {
                        $depth++;
                        $i++;
                        continue;
                    }

                    if ($cc === ']' || $cc === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $segIndex = \count($segments);
                            $segments[] = [
                                'type'     => $ch === '[' ? 'index' : 'dyn',
                                'value'    => \substr($subject, $innerStart, $i - $innerStart),
                                'optional' => $optional,
                            ];
                            $i++;
                            // Dynamic method call: `$obj->{$m}(...)`.
                            if ($allowCall && $ch === '{') {
                                $k2 = $i;
                                while ($k2 < $len && \ctype_space($subject[$k2])) {
                                    $k2++;
                                }
                                if ($k2 < $len && $subject[$k2] === '(') {
                                    [$callArgs, $callEnd] = $this->extractBalancedSegment($subject, $k2);
                                    $segments[$segIndex]['call'] = $callArgs;
                                    $i = $callEnd;
                                }
                            }
                            break;
                        }
                        $i++;
                        continue;
                    }

                    $i++;
                }

                if ($depth > 0) {
                    // Unterminated access expression: consume to end as one segment.
                    $segments[] = [
                        'type'     => $ch === '[' ? 'index' : 'dyn',
                        'value'    => \substr($subject, $innerStart),
                        'optional' => $optional,
                    ];
                    $i = $len;
                }

                continue;
            }

            // ---- static array key: `:key` ----------------------------------
            // Whitespace may sit on either side of the colon, so the key may be
            // on the next line. While a ternary is pending, the colon must be
            // GLUED on both sides to count as a key read â€” that is what leaves
            // `cond ? x : y` as a ternary.
            if ($ch === ':' && $this->isChainColon($subject, $i, $ternaryOpen)) {
                $idStart = $i + 1;
                while ($idStart < $len && \ctype_space($subject[$idStart])) {
                    $idStart++;
                }

                $j = $idStart + 1;
                while ($j < $len && self::isIdentifierChar($subject[$j])) {
                    $j++;
                }

                $segments[] = [
                    'type'     => 'key',
                    'value'    => \substr($subject, $idStart, $j - $idStart),
                    'optional' => $optional,
                ];
                $i = $j;
                continue;
            }

            break;
        }

        return ['end' => $i, 'segments' => $segments];
    }

    /**
     * Convert parsed var-chain segments to PHP.
     *
     * Emission rules
     * --------------
     *  key   a:b        -> ['b']        (array key, strict)
     *  index a[i]       -> [$i]         (array index, strict)
     *  prop  a.b / a->b -> ->b          (object property, strict)
     *  dyn   a{k}       -> ->{$k}       (object dynamic property, strict)
     *
     * A segment flagged `optional` is emitted through the matching Access::*
     * guard, so an absent key/property yields null instead of raising. The
     * guard is only ever used for the OPTIONAL forms â€” a strict read is plain
     * PHP indexing/property access, which is what makes the strict contract
     * cost nothing at runtime.
     *
     * @param array<int,array{type:string,value:string,optional?:bool}> $segments
     */
    private function buildVarChainPhp(array $segments): string
    {
        if (empty($segments)) {
            return '';
        }

        $first = $segments[0]['value'];
        if (!\preg_match(self::IDENT_RE, $first)) {
            throw new ClarityException("Invalid identifier in var chain: {$first}");
        }

        $php = $this->rootPhp($first);
        $n   = \count($segments);

        for ($k = 1; $k < $n; $k++) {
            $php = $this->appendChainSegmentPhp($php, $segments[$k]);
        }

        return $php;
    }

    /**
     * Emit one chain continuation onto an existing PHP expression.
     *
     * A `prop`/`dyn` segment may carry an optional `call` (its raw argument
     * list) and `dynName` (the compiled PHP for a computed method name), which
     * together emit `->method(args)` / `->{$expr}(args)`.
     *
     * @param array{type:string,value:string,optional?:bool,call?:string,dynName?:string} $seg
     */
    private function appendChainSegmentPhp(string $php, array $seg): string
    {
        $optional = (bool) ($seg['optional'] ?? false);
        $value    = $seg['value'];

        if ($seg['type'] === 'key' || $seg['type'] === 'index') {
            $key = $seg['type'] === 'key'
                ? $this->staticKeyLiteral($value)
                : $this->compileIndexExpression($value);

            if (!$optional) {
                return $php . '[' . $key . ']';
            }

            // `?` guards the RECEIVER only â€” the read itself stays STRICT. An
            // absent receiver yields null, but a missing KEY still raises
            // "Undefined array key". `$receiver[$k] ?? null` would swallow both
            // and silently hide a mistyped key, which is the exact failure mode
            // the strict-access design exists to prevent.
            //
            // Two forms:
            //   bare root    -> (isset($a)           ? $a['k']           : null)
            //   anything else-> (($t = RECV) === null ? null : $t['k'])
            // The first is preferred where it is CORRECT: isset() reports an
            // absent ROOT as false without a warning, which is precisely the
            // tolerance asked for. It is wrong anywhere else, because isset()
            // would also swallow a missing property or an intermediate missing
            // key. The second form binds the receiver ONCE, so a nested optional
            // chain stays linear instead of duplicating its receiver (the
            // duplication is what made an earlier revision grow as 2^N â€” six
            // optional segments emitted 2 245 characters for one read). Both
            // forms keep the expression nestable.
            //
            // The guarded subject is the SHORT root (no `?? â€¦` tail): wrapping an
            // already-coalesced root in isset() is invalid PHP.
            $subject = $this->toLocalSubject($php);
            if (\preg_match(self::BARE_ROOT_RE, $subject)) {
                return '(isset(' . $subject . ') ? ' . $subject . '[' . $key . '] : null)';
            }

            $tmp = '$__c_g' . (++$this->guardCounter);
            return '((' . $tmp . ' = ' . $php . ') === null ? null : ' . $tmp . '[' . $key . '])';
        }

        // Property access (static or dynamic).
        $prop = $seg['type'] === 'dyn'
            ? '{' . $this->compileIndexExpression($value) . '}'
            : $value;

        if ($seg['type'] === 'prop' && !\preg_match(self::IDENT_RE, $value)) {
            throw new ClarityException("Invalid identifier in var chain: {$value}");
        }

        // Method call attached to the property read (when `methodCalls` is granted).
        $call = isset($seg['call'])
            ? '(' . $this->compileMethodArgs((string) $seg['call']) . ')'
            : '';

        if (!$optional) {
            return $php . '->' . $prop . $call;
        }

        // `?->` tolerates a NULL receiver while leaving the PROPERTY READ strict,
        // so a present object lacking the property still raises "Undefined
        // property" â€” the feedback we want. It short-circuits the rest of the
        // chain and nests without any guard expression.
        //
        // An ABSENT root is a separate case: `$__c_va['a']?->b` still raises
        // "Undefined array key 'a'", so the receiver is guarded with isset()
        // there â€” the same tolerance the array side gets, which keeps `?.` and
        // `?:` consistent about an absent root. (Emitting `?? null` instead would
        // additionally swallow a missing property.)
        $subject = $this->toLocalSubject($php);
        if (\preg_match(self::BARE_ROOT_RE, $subject)) {
            return '(isset(' . $subject . ') ? ' . $subject . '->' . $prop . $call . ' : null)';
        }

        return $php . '?->' . $prop . $call;
    }

    /**
     * Compile a method-call argument list to PHP.
     *
     * Only reachable when the `methodCalls` rule is granted.  Arguments are
     * full Clarity expressions and named arguments become PHP named arguments.
     */
    private function compileMethodArgs(string $argsRaw): string
    {
        if (\trim($argsRaw) === '') {
            return '';
        }
        return \implode(', ', $this->compileArgList($this->splitRespectingStrings($argsRaw, ',')));
    }

    /**
     * A static array key emitted as a PHP string-index literal.
     */
    private function staticKeyLiteral(string $key): string
    {
        if (!\preg_match(self::IDENT_RE, $key)) {
            throw new ClarityException("Invalid identifier in var chain: {$key}");
        }
        return "'" . \addslashes($key) . "'";
    }

    /**
     * Compile an index/property-key expression: a digit run stays literal,
     * anything else is compiled as a full Clarity expression.
     */
    private function compileIndexExpression(string $inner): string
    {
        if ($inner !== '' && \ctype_digit($inner)) {
            return $inner;
        }
        return $this->convertVarsAndOps($inner);
    }

    /**
     * Convert a parsed var-chain and memoize by raw chain string.
     *
     * @param array<int,array{type:string,value:string,optional?:bool}> $segments
     */
    private function varChainToPhpWithSegments(string $chain, array $segments): string
    {
        if (isset($this->varChainCache[$chain])) {
            return $this->varChainCache[$chain];
        }

        $php = $this->buildVarChainPhp($segments);
        $this->varChainCache[$chain] = $php;
        return $php;
    }

    /**
     * Like buildVarChainPhp() but uses the local-var PHP variable for the root segment.
     * Called when the chain root is a locally-bound loop variable (e.g. $item.foo).
     *
     * @param array<int,array{type:string,value:string,optional?:bool}> $segments
     */
    private function buildVarChainPhpWithLocalRoot(array $segments): string
    {
        $first = $segments[0]['value'];
        $php   = $this->localVars[$first]; // e.g. '$item'
        $n     = \count($segments);

        for ($k = 1; $k < $n; $k++) {
            $php = $this->appendChainSegmentPhp($php, $segments[$k]);
        }

        return $php;
    }

    /**
     * Emit a chain root: a PHP local in open mode, else a `$__c_va` lookup.
     *
     * Open mode seeds the render scope into locals, so the root IS the local.
     * No guard expression is emitted: an unknown name then raises PHP's own
     * "Undefined variable" warning, which {@see ClarityEngineTrait::buildErrorHandler()}
     * already maps to a ClarityException carrying the template line. That keeps
     * the strict-access contract identical to sandbox mode â€” and a `?? $__c_va[â€¦]`
     * fallback would silently suppress it, which is the failure strict access
     * exists to prevent.
     *
     * Read and write are therefore the SAME text (`$name`), so no lvalue flag is
     * needed in either mode.
     *
     * A name matching an enclosing lambda's PARAMETER is emitted bare as well:
     * that parameter is a real local of the enclosing closure, so reading it
     * through `$__c_va` would report it absent. See {@see $lambdaFrames}.
     */
    private function rootPhp(string $name): string
    {
        if ($this->isLambdaParam($name)) {
            return '$' . $name;
        }

        // A superglobal name is a special case in BOTH directions, and the two
        // must be stated together or the rule is a lie:
        //
        //   granted     -> PHP's own `$_SERVER`, whatever the scope holds
        //   not granted -> an ordinary scope read, which is absent and therefore
        //                  throws
        //
        // The second half is the load-bearing one.  Without it a template could
        // reach every superglobal through the seeded-local form the moment
        // `phpVariables` was granted, and the two rules would be one.
        if (self::isSuperglobalName($name)) {
            return $this->allows('superglobals')
                ? '$' . $name
                : '$__c_va[\'' . $name . '\']';
        }

        if (!$this->localRoots || $this->lambdaFrames !== []) {
            return '$__c_va[\'' . $name . '\']';
        }

        return '$' . $name;
    }

    /**
     * Whether a chain root names one of PHP's superglobals.  Exact match on
     * purpose: `_SERVERX` is an ordinary name, and `GLOBALS` is a superglobal
     * only as the whole name.
     */
    private static function isSuperglobalName(string $name): bool
    {
        return isset(self::SUPERGLOBALS[$name]);
    }

    /**
     * Is $name a parameter declared by an enclosing lambda (or the one being
     * compiled)?
     */
    private function isLambdaParam(string $name): bool
    {
        for ($i = \count($this->lambdaFrames) - 1; $i >= 0; $i--) {
            if (isset($this->lambdaFrames[$i][$name])) {
                return true;
            }
        }
        return false;
    }

    /**
     * `$__c_va['a']` â†’ `$a` in open mode.  The guard helpers only care about the
     * subject, so the emitted form must match what rootPhp() produces for a
     * bare root.  A root that is not a bare `$__c_va[...]` (an already-guarded
     * expression, or a nested chain) is returned unchanged.
     */
    private function toLocalSubject(string $php): string
    {
        // A lambda parameter is already a real local, so it is a valid
        // isset()/`?->` subject as emitted.
        if (\preg_match(self::BARE_PARAM_RE, $php) && $this->isLambdaParam(\substr($php, 1))) {
            return $php;
        }

        // A granted superglobal is emitted as a bare local by rootPhp(), so it is
        // already the subject every guard helper wants to see.
        if (
            $this->allows('superglobals') && \preg_match(self::BARE_PARAM_RE, $php)
                && self::isSuperglobalName(\substr($php, 1))
        ) {
            return $php;
        }

        if (!$this->localRoots || $this->lambdaFrames !== []) {
            return $php;
        }

        return (string) \preg_replace(
            '/^\$__c_va\[\'([A-Za-z_][A-Za-z0-9_]*)\'\]$/',
            '$$1',
            $php
        );
    }

    /**
     * Convert a Clarity var-chain string to a PHP $__c_va[...] expression.
     *
     * Supports:
     *   foo           â†’ $__c_va['foo']
     *   foo.bar       â†’ $__c_va['foo']['bar']
     *   items[0]      â†’ $__c_va['items'][0]
     *   items[index]  â†’ $__c_va['items'][$__c_va['index']]
     *   a.b[c.d].e    â†’ $__c_va['a']['b'][$__c_va['c']['d']]['e']
     */
    public function varChainToPhp(string $chain): string
    {
        if ($chain === '') {
            return '';
        }

        // Whitespace between a value and its chain operator is not significant,
        // so `user . name` and `user.name` must not occupy separate cache
        // entries (and must not produce different PHP).
        $key = \preg_replace('/\s+/', '', $chain);

        // Memoization
        if (isset($this->varChainCache[$key])) {
            return $this->varChainCache[$key];
        }

        $parsed = $this->parseVarChainAt($chain, 0);
        if ($parsed === null) {
            return $chain;
        }

        // Keep legacy behavior for malformed tails by returning original chain
        // when parsing does not consume the full input.
        if ($parsed['end'] !== \strlen($chain)) {
            return $chain;
        }

        return $this->varChainToPhpWithSegments($key, $parsed['segments']);
    }
}
