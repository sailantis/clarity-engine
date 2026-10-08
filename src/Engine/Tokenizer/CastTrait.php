<?php

namespace Clarity\Engine\Tokenizer;

use Clarity\ClarityException;

/**
 * Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.
 *
 * The PHP cast prefix: `(int) x`, `(string) (a + b)`, `(float) user.price`.
 *
 * A cast is GRAMMAR, not a capability. It needs no policy rule and adds
 * nothing to `Policy::RULES`, exactly like `instanceof` — whose class-name
 * operand is likewise grammar rather than a reach. Consequently a cast changes
 * no policy digest and invalidates no compiled cache.
 *
 * The hard part is telling a cast from a parenthesised sub-expression, because
 * `(int)` and `(a)` are lexically the same shape. The rule is what follows the
 * closing paren: whitespace is OPTIONAL, and the cast is decided by whether the
 * character after the `)` can OPEN an operand.
 *
 *   `(int) x`   — a cast: whitespace, then a character that can OPEN an operand.
 *   `(int)x`    — a cast: the glued form is accepted too. A call, an index or a
 *                 subtraction is what the text would otherwise have been, and
 *                 naming a variable `int` to reach one is a collision rather than
 *                 a reading, so the cast wins.
 *   `(a) + b`   — a parenthesised expression: `+` cannot open an operand, so
 *                 this stays arithmetic. Likewise `(a) ? b : c` and `(a) foo`.
 *   `(a)`       — a parenthesised expression: nothing follows at all.
 *
 * `(int) (x)` and `(int)(x)` are the same reading — a cast of the parenthesised
 * `x`. Neither is a call, because a call needs a CALLABLE name before the `(`
 * and a cast name is not one.
 *
 * Whitespace is not what makes a cast; the cast NAME is. Gluing is therefore
 * accepted, at the cost of three spellings that change meaning for a template
 * that holds a variable named after a cast type:
 *
 *   `(int)(x)`   a call on the variable `int`  → a cast of the grouped `x`
 *   `(int)[0]`   an index into the variable `int` → a cast of `[0]`
 *   `(int)-5`    the variable `int` minus 5    → a cast of `-5`
 *
 * The matcher still returns null on EVERY other doubt and never throws, so the
 * ordinary parenthesised path in {@see ExpressionCoreTrait::convertVarsAndOps()}
 * stays the default for a name that is not a cast type.
 */
trait CastTrait
{
    /**
     * The cast types, as `lowercase spelling => the PHP form to emit`.
     *
     * Every key is the CANONICAL spelling, so there is exactly one way to write
     * each cast. That is deliberate: a second spelling of the same cast is a
     * second name for an author to learn and for a reader to puzzle over, with no
     * behaviour to justify it.
     *
     * `(real)` and `(unset)` are absent because PHP removed both in 8.0 — emitting
     * either would inject a fatal parse error into the compiled template. PHP's own
     * diagnostic names the replacement: "The (real) cast has been removed, use
     * (float) instead".
     *
     * `(binary)` is absent because it does nothing. It is a legacy alias for
     * `(string)` and produces byte-identical output for every input — an integer,
     * a bool, `null`, an array, a string. It is NOT a base-2 conversion.
     *
     * `(integer)`, `(boolean)` and `(double)` are absent as a set: `int`, `bool` and
     * `float` spell the same cast more briefly and do not collide with names a
     * template plausibly holds as data. Refusing them keeps a cast from ever
     * shadowing a scope name.
     *
     * A name that is not a cast falls through to the parenthesised-expression path,
     * so `(integer) x` is an ordinary syntax error rather than a silently different
     * construct.
     *
     * @var array<string, string>
     */
    private const CAST_TYPES = [
        'int'    => 'int',
        'float'  => 'float',
        'string' => 'string',
        'bool'   => 'bool',
        'array'  => 'array',
        'object' => 'object',
    ];

    /**
     * The bare keyword literals, as the expression loop spells them.
     *
     * A cast operand is compiled through the var-chain parser, which would read
     * `true` as a variable named `true`. These names are literals in the
     * expression grammar (the same list `convertVarsAndOps()` applies, including
     * the shadowing of a passed variable of the same name), so `(int) true` and
     * `(string) null` mean what they read as.
     *
     * @var array<string, string>
     */
    private const CAST_LITERALS = [
        'true'  => 'true',
        'false' => 'false',
        'null'  => 'null',
        'nil'   => 'null',
    ];

    /**
     * Match a cast prefix at $start, which must be the index of a `(`.
     *
     * @return array{0: string, 1: int}|null  The canonical PHP type, and the
     *                                        index at which the operand begins.
     *                                        Null when this is not a cast, in
     *                                        which case the caller uses the
     *                                        parenthesised-expression path.
     */
    private function tryCompileCastPrefix(string $expr, int $start, int $len): ?array
    {
        $p = $start + 1;
        while ($p < $len && \ctype_space($expr[$p])) {
            $p++;
        }

        $nameStart = $p;
        while ($p < $len && \ctype_alpha($expr[$p])) {
            $p++;
        }
        if ($p === $nameStart) {
            return null;
        }

        $raw = \strtolower(\substr($expr, $nameStart, $p - $nameStart));
        if (!isset(self::CAST_TYPES[$raw])) {
            return null;
        }

        // `(int)` closes directly; allow whitespace INSIDE the parens (`( int ) x`)
        // because it costs nothing and reads as deliberate.
        while ($p < $len && \ctype_space($expr[$p])) {
            $p++;
        }
        if (($expr[$p] ?? '') !== ')') {
            return null;
        }
        $p++;

        // The discriminating character. Whitespace here means the type name was
        // followed by an operand — `(a) + b` can never look like this. Whitespace
        // is OPTIONAL, though: the cast is decided by the operand opener, not by
        // the space, so the glued form `(int)x` reads as a cast too. Gluing costs
        // the three shapes noted in the trait doc-block, all of which need a
        // variable named after a cast type to have meant anything before.
        //
        //   `(a) + b`   the `+` is a binary operator, so this is NOT a cast.
        //   `(a) b`     two values in a row is not valid PHP either, unless the
        //               whitespace is a chain continuation (`a b` reads `a`).
        //
        // So a cast needs an operand opener, optionally after whitespace.
        // `(int) -5` is the one spellable case with a leading operator, and it is
        // accepted explicitly.
        $q = $p;
        while ($q < $len && \ctype_space($expr[$q])) {
            $q++;
        }
        if ($q >= $len) {
            return null;
        }

        $next = $expr[$q];
        if (
            !self::isIdentifierStart($next)
                && $next !== '(' && $next !== '[' && $next !== '{'
                && $next !== '$' && $next !== "'" && $next !== '"'
                && !\ctype_digit($next)
                && !($next === '-' && $this->castOperandStartsAfterSign($expr, $q, $len))
        ) {
            return null;
        }

        return [self::CAST_TYPES[$raw], $q];
    }

    /**
     * Whether a `-` at $p opens a NUMERIC operand rather than being a binary
     * minus — the difference between `(int) -5` and `(a) - 5`.
     *
     * A sign is unary when a digit or a `.` follows it directly, since `(a) - 5`
     * and `(a) -5` would otherwise be indistinguishable and the whitespace-glued
     * form is overwhelmingly the subtraction.
     */
    private function castOperandStartsAfterSign(string $expr, int $p, int $len): bool
    {
        $next = $expr[$p + 1] ?? '';
        return $next !== '' && (\ctype_digit($next) || $next === '.');
    }

    /**
     * Compile exactly ONE operand after a cast prefix, starting at $p.
     *
     * The forms, and why each is the right reading:
     *
     *   `(`  one balanced group, recursed so `(int) (float) x` nests and
     *        `(string) (a + b)` compiles the sum as a value.
     *   `[' / '{'`  a collection literal, via the same emitter the bare form uses.
     *   `$name`  a PHP-sigil read, through the same chain parser the bare form
     *        uses. A cast cannot be the left-hand side of an assignment, so this
     *        is only ever a read.
     *   `'` / `"` / digit  a scalar literal, passed through verbatim.
     *   identifier  a var-chain read, through the SAME parser the bare form uses,
     *        so `(int) user:age`, `(int) items[0]` and `(int) loop.index` all work.
     *        A call that follows the whole operand is dispatched by
     *        {@see compileCastOperandCall()}, which applies the same policy the bare
     *        form does.
     *   `-` sign  a numeric literal, glued: `(int) -5`. A sign is only read this
     *        way when a digit or `.` follows it directly, which is what separates
     *        it from the subtraction `(a) - 5`.
     *
     * The emitted operand is parenthesised, so `(int) -5` is `(int) (-5)` and a
     * following operator cannot bind inside it.
     *
     * @param string $expr The full expression string.
     * @param int    $p    Index of the operand's first character.
     * @param int    $len  Length of $expr.
     * @param int|null $end Set to the index after the operand on success.
     * @return string The compiled PHP operand, already parenthesised where needed.
     */
    private function compileCastOperand(string $expr, int $p, int $len, ?int &$end): string
    {
        // Unary sign, glued to its number.
        if ($expr[$p] === '-' && $this->castOperandStartsAfterSign($expr, $p, $len)) {
            $q = $p + 1;
            while ($q < $len && (\ctype_digit($expr[$q]) || $expr[$q] === '.')) {
                $q++;
            }
            $end = $q;
            return '(' . \substr($expr, $p, $q - $p) . ')';
        }

        $ch = $expr[$p];

        if ($ch === '(' || $ch === '[' || $ch === '{') {
            // A nested cast: `(int) (float) x`. Re-try the cast matcher on the
            // group before treating it as a parenthesised expression, so the
            // two spellings compose to any depth.
            if ($ch === '(') {
                $nested = $this->tryCompileCastPrefix($expr, $p, $len);
                if ($nested !== null) {
                    $end     = null;
                    $operand = $this->compileCastOperand($expr, $nested[1], $len, $end);
                    return '(' . $nested[0] . ') ' . $operand;
                }
            }

            [$inner, $after] = $this->extractBalancedSegment($expr, $p);
            $end = $after;

            if ($ch === '[' || $ch === '{') {
                return '(' . $this->processCondition(\substr($expr, $p, $after - $p)) . ')';
            }

            return '(' . $this->processCondition($inner) . ')';
        }

        if ($ch === "'" || $ch === '"') {
            $q = $p + 1;
            while ($q < $len) {
                if ($expr[$q] === '\\' && ($q + 1) < $len) {
                    $q += 2;
                    continue;
                }
                if ($expr[$q] === $ch) {
                    $q++;
                    break;
                }
                $q++;
            }
            $end = $q;
            return \substr($expr, $p, $q - $p);
        }

        if (\ctype_digit($ch)) {
            $q = $p;
            while ($q < $len && (\ctype_digit($expr[$q]) || $expr[$q] === '.')) {
                $q++;
            }
            $end = $q;
            return \substr($expr, $p, $q - $p);
        }

        if ($ch === '$') {
            $sigilStart = $p + 1;
            if (!self::isIdentifierStart($expr[$sigilStart] ?? '')) {
                throw new ClarityException(
                    "Expected a variable name after '\$' in the cast operand of '{$expr}'."
                );
            }

            $parsed = $this->parseVarChainAt($expr, $sigilStart, true, false, $this->allows('methodCalls'));
            if ($parsed === null) {
                throw new ClarityException(
                    "Could not read the '\$' operand of a cast in '{$expr}'."
                );
            }

            $end      = $parsed['end'];
            $segments = $parsed['segments'];
            $token    = \substr($expr, $sigilStart, $end - $sigilStart);

            return '(' . (isset($this->localVars[$segments[0]['value']])
                    ? $this->buildVarChainPhpWithLocalRoot($segments)
                    : $this->varChainToPhpWithSegments('$' . $token, $segments)) . ')';
        }

        if (self::isIdentifierStart($ch)) {
            // A bare literal, not a scope read: `(int) true`, `(string) nil`.
            $idEnd = $p + 1;
            while ($idEnd < $len && self::isIdentifierChar($expr[$idEnd])) {
                $idEnd++;
            }
            $word = \strtolower(\substr($expr, $p, $idEnd - $p));
            if (isset(self::CAST_LITERALS[$word])) {
                $end = $idEnd;
                return self::CAST_LITERALS[$word];
            }

            $parsed = $this->parseVarChainAt($expr, $p, false, false, $this->allows('methodCalls'));
            if ($parsed === null) {
                $end = $p + 1;
                return '(' . $ch . ')';
            }

            $end   = $parsed['end'];
            $token = \substr($expr, $p, $end - $p);

            // A `(` that survives chain parsing is a call on the whole operand:
            // `(array) foo()` and `(int) obj.m()` — the second because the chain
            // parser CONSUMES `m()` into its property segment when the
            // `methodCalls` rule is on. Both are forwarded to the same emitters the
            // bare form uses, so the policy answers (and the error messages teach)
            // exactly as they do outside a cast.
            $j = $end;
            while ($j < $len && \ctype_space($expr[$j])) {
                $j++;
            }
            if ($j < $len && $expr[$j] === '(') {
                [$call, $end] = $this->compileCastOperandCall($token, $expr, $j, $len);
                return '(' . $call . ')';
            }

            $segments = $parsed['segments'];

            if (isset($this->localVars[$segments[0]['value']])) {
                return '(' . $this->buildVarChainPhpWithLocalRoot($segments) . ')';
            }

            if (\count($segments) === 1) {
                $value = $segments[0]['value'];
                if (isset($this->localVars[$value])) {
                    return '(' . $this->localVars[$value] . ')';
                }
                if (isset($this->varChainCache[$value])) {
                    return '(' . $this->varChainCache[$value] . ')';
                }
                $php = $this->varChainToPhpWithSegments($value, $segments);
                $this->varChainCache[$value] = $php;
                return '(' . $php . ')';
            }

            return '(' . $this->varChainToPhpWithSegments($token, $segments) . ')';
        }

        throw new ClarityException(
            "Expected a value after a cast in '{$expr}'."
        );
    }

    /**
     * Compile `name(...)` where `name` is the whole operand of a cast.
     *
     * The branch is chosen by the SAME precedence the bare expression loop uses,
     * so a name behaves identically inside and outside a cast:
     *
     *   - a registered function (inline or callable) is dispatched through the
     *     engine's own call emitter;
     *   - an unregistered name needs the `phpFunctions` rule, and is refused with
     *     that rule's message when the policy does not grant it.
     *
     * That second point is the reason this helper exists rather than a pass-through:
     * `{{ (int) foo(bar) }}` must report the same compile-time policy error as
     * `{{ foo(bar) }}`. Without the check it compiled to a direct `\foo(...)` call
     * and the failure surfaced later as an uncaught `Error: Call to undefined
     * function` escaping the runtime error handler.
     *
     * @return array{0: string, 1: int}  The PHP call, and the index after its `)`.
     */
    private function compileCastOperandCall(string $token, string $expr, int $openParen, int $len): array
    {
        if ($this->registry !== null && $this->registry->hasFunction($token)) {
            return $this->buildFunctionCallInExpr($token, $expr, $openParen, $len);
        }

        if (!$this->policy->allows('phpFunctions')) {
            $context = \substr($expr, 0, \min(70, $len));
            throw new ClarityException(
                "Call to unregistered function '{$token}()' in context '{$context}'. "
                    . "Grant the 'phpFunctions' rule to allow PHP function calls, then add the name "
                    . 'with allowFunctions().'
            );
        }

        if (!$this->isFunctionCallAllowed($token)) {
            throw new ClarityException(
                "Function '{$token}()' is not allowed by this policy: it is not in the "
                    . 'function allowlist, or it is denied. Add it with allowFunctions().'
            );
        }

        return $this->buildFunctionCallInExpr($token, $expr, $openParen, $len);
    }
}
