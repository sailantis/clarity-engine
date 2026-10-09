<?php

namespace Clarity\Engine\Tokenizer;

use Clarity\ClarityException;

/**
 * Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.
 *
 * The PHP cast prefix: `(int) x`, `(string) (a + b)`, `(float) user.price`.
 *
 * Casts are grammar, not a policy rule: they add nothing to `Policy::RULES`.
 *
 * The hard part is telling a cast from a parenthesised sub-expression, because
 * `(int)` and `(a)` have the same shape. A cast is recognised by a fixed cast
 * name (see CAST_TYPES) followed by an operand, so the character after the `)`
 * must be able to open one.
 *
 *   `(int) x`   a cast: whitespace, then an operand opener.
 *   `(int)x`    a cast: whitespace is optional.
 *   `(a) + b`   not a cast: `a` is not a cast name, and `+` cannot open an operand.
 *   `(a)`       not a cast: nothing follows.
 *
 * `(int) (x)` and `(int)(x)` are both a cast of the parenthesised `x`. Neither is
 * a call, because a call needs a callable name before the `(`.
 *
 * Gluing is accepted, which has a cost. For a template that holds a variable named
 * after a cast type, these spellings change meaning:
 *
 *   `(int)(x)`   a call on the variable `int`     → a cast of the grouped `x`
 *   `(int)[0]`   an index into the variable `int` → a cast of `[0]`
 *   `(int)-5`    the variable `int` minus 5       → a cast of `-5`
 *
 * The matcher returns null on any doubt and never throws, so the ordinary
 * parenthesised path in {@see ExpressionCoreTrait::convertVarsAndOps()} still
 * handles any name that is not a cast type.
 */
trait CastTrait
{
    /**
     * The cast types, as `lowercase spelling => the PHP form to emit`.
     *
     * Each cast has one spelling.
     * `(integer)`, `(boolean)` and `(double)` are absent because `int`, `bool` and `float` are the same casts under their short names.
     *
     * A name that is not in this map is not a cast. It falls through to the
     * parenthesised-expression path, so `(integer) x` is a syntax error rather than a silently different construct.
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

        // `(int)` closes directly; whitespace INSIDE the parens (`( int ) x`) is also allowed.
        while ($p < $len && \ctype_space($expr[$p])) {
            $p++;
        }
        if (($expr[$p] ?? '') !== ')') {
            return null;
        }
        $p++;

        // The discriminating character is the operand opener. Whitespace is optional,
        // so `(int)x` and `(int) x` are both casts. A binary operator such as `+`
        // cannot open an operand, so `(int) + b` is not a cast.
        // `(int) -5` is the one case with a leading operator, and it is accepted explicitly.
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
     * minus: `(int) -5` is a cast, while `(a) - 5` is a subtraction.
     *
     * The sign is unary only when a digit or `.` follows it directly. Without that
     * test `(int) -5` and `(a) -5` would look the same, and the glued form is
     * usually a subtraction.
     */
    private function castOperandStartsAfterSign(string $expr, int $p, int $len): bool
    {
        $next = $expr[$p + 1] ?? '';
        return $next !== '' && (\ctype_digit($next) || $next === '.');
    }

    /**
     * Compile a single operand after a cast prefix, starting at $p.
     *
     * The forms, and why each is the right reading:
     *
     *   `(`  one balanced group, recursed so `(int) (float) x` nests and
     *        `(string) (a + b)` compiles the sum as a value.
     *   `[` / `{`  a collection literal, via the same emitter the bare form uses.
     *   `$name`  a PHP-sigil read, through the same chain parser the bare form
     *        uses. A cast cannot be the left-hand side of an assignment, so this
     *        is only ever a read.
     *   `'` / `"` / digit  a scalar literal, passed through verbatim.
     *   identifier  a var-chain read, through the same parser the bare form uses,
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
            // `(array) foo()` and `(int) obj.m()`. The second case arises because the
            // chain parser consumes `m()` into its property segment when the
            // `methodCalls` rule is on. Both are forwarded to the same emitters the
            // bare form uses, so policy checks and error messages match those outside
            // a cast.
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
                $cacheKey = $this->varChainCacheKey($value);
                if (isset($this->varChainCache[$cacheKey])) {
                    return '(' . $this->varChainCache[$cacheKey] . ')';
                }
                $php = $this->varChainToPhpWithSegments($value, $segments);
                $this->varChainCache[$cacheKey] = $php;
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
     * The branch is chosen by the same precedence the bare expression loop uses,
     * so a name behaves identically inside and outside a cast:
     *
     *   - a registered function (inline or callable) is dispatched through the
     *     engine's own call emitter;
     *   - an unregistered name needs the `phpFunctions` rule, and is refused with
     *     that rule's message when the policy does not grant it.
     *
     * The second check is needed because `(int) foo(bar)` would otherwise compile to
     * a direct `\foo(...)` call. An unregistered function would then fail at run time
     * with an uncaught `Error` instead of the compile-time policy error.
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
