<?php

namespace Clarity\Engine\Tokenizer;

use Clarity\ClarityException;
use Clarity\Engine\Registry;
use Clarity\Engine\Tokenizer;

/**
 * Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.
 */
trait CallableTrait
{


    /**
     * Compile the callable argument accepted by map / filter / reduce.
     *
     * Accepted forms:
     *   param => expression                  single-parameter lambda
     *   acc, item => expression             explicit two-parameter reduce lambda
     *   'filterName' / "filterName"         reference to a registered filter or
     *                                        to an inline built-in filter, which
     *                                        is compiled into a closure (see
     *                                        {@see shouldInlineCallableFilterReference()})
     *
     * Anything else (bare variable names, function calls, â€¦) is rejected.
     */
    private function compileCallableArg(string $arg, string $filterName): string
    {
        // â”€â”€ Filter reference: 'name' or "name" â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $trimmed = \trim($arg);
        if (\strlen($trimmed) >= 2) {
            $first = $trimmed[0];
            $last  = $trimmed[-1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $refName = \substr($trimmed, 1, -1);
                if (!\preg_match(self::IDENT_RE, $refName)) {
                    throw new ClarityException(
                        "Filter reference must be a plain identifier, got: '{$refName}'"
                    );
                }

                if ($this->shouldInlineCallableFilterReference($refName)) {
                    // `reduce` needs a BINARY callable — carry, item. An inline
                    // filter is a unary template with one `{1}` slot, so there is
                    // no way to express "combine the carry with the element".
                    // PHP would bind the ACCUMULATOR to that slot, the closure
                    // would ignore the element, and reduce would silently return
                    // the initial value. Reject it instead of compiling a
                    // no-op.
                    if ($filterName === 'reduce') {
                        throw new ClarityException(
                            "The 'reduce' filter needs a two-parameter lambda (e.g. 'carry, item => carry + item');"
                                . " the inline filter '{$refName}' is unary and cannot combine elements."
                        );
                    }

                    return $this->buildInlineCallableFilterReference($refName);
                }

                // A registered filter (inline template or runtime callable) is
                // dispatched through the ONE runtime table, `$__c_fn`. Whether a
                // name may be used as a filter at all is decided HERE, at compile
                // time, so the runtime table stays a plain callable map.
                $isRegisteredFilter = $this->registry !== null
                    && ($this->registry->hasFilter($refName) || $this->registry->hasInlineFilter($refName));

                if ($isRegisteredFilter) {
                    return "\$__c_fn['" . \addslashes($refName) . "']";
                }

                // Open mode: a quoted name that is not a registered filter may
                // name a PHP function (Blade parity).
                if (!$this->sandboxMode && $this->registry !== null) {
                    if (!$this->isFunctionCallAllowed($refName)) {
                        throw new ClarityException(
                            "Function '{$refName}' is blocked in PHP mode. Allow it by removing it from the deny-list."
                        );
                    }
                    if (!\function_exists(\ltrim($refName, '\\'))) {
                        throw new ClarityException(
                            "Unknown callable '{$refName}': not a registered filter and no PHP function exists."
                        );
                    }
                    return "'" . \addslashes(\ltrim($refName, '\\')) . "'";
                }

                // Sandbox: an unknown reference is a compile-time error — the
                // callable-injection guard rejects anything not a filter.
                throw new ClarityException(
                    "Filter reference '{$refName}' is not a filter"
                        . ($this->registry !== null && $this->registry->hasCallable($refName)
                            ? "; it is a function, not a filter."
                            : " and no filter is registered under that name.")
                );
            }
        }

        // â”€â”€ Lambda: param => expression / acc, item => expression â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $arrowPos = $this->findLambdaArrow($arg);
        if ($arrowPos !== false) {
            return $this->compileLambda($arg, $arrowPos, $filterName);
        }

        throw new ClarityException(
            "The '{$filterName}' filter requires a lambda (e.g. 'item => item.name') "
                . "or a filter reference (e.g. '\"upper\"'), got: '{$arg}'"
        );
    }

    /**
     * Decide how a quoted filter reference (`map(items, "upper")`) compiles.
     *
     * An INLINE-only filter has no runtime entry — it is codegen, not a
     * callable — so it must be compiled into a closure right here, exactly as
     * the compiler does for any other inline filter use. Emitting a registry
     * lookup for it would produce code that can never resolve. Callable
     * filters (`slug`) are the opposite: they live in the runtime table and are
     * dispatched as `$__c_fn['slug']`, which keeps the registry as the one
     * source of callables and avoids re-emitting an equivalent closure.
     */
    private function shouldInlineCallableFilterReference(string $referenceName): bool
    {
        return $this->registry !== null && $this->registry->hasInlineFilter($referenceName);
    }

    private function buildInlineCallableFilterReference(string $referenceName): string
    {
        $inlineCall = $this->buildInlineFilterCall($referenceName, '$__c_val', []);
        if ($inlineCall === null) {
            throw new ClarityException("Unknown inline filter reference: '{$referenceName}'");
        }

        return "static fn(mixed \$__c_val): mixed => {$inlineCall}";
    }

    /**
     * Find the position of the first '=>' operator that is not inside a quoted
     * string. Returns false if none is found.
     */
    private function findLambdaArrow(string $s): int|bool
    {
        $len      = \strlen($s);
        $inSingle = false;
        $inDouble = false;

        for ($i = 0; $i < $len - 1; $i++) {
            $ch = $s[$i];

            if (($inSingle || $inDouble) && $ch === '\\' && ($i + 1) < $len) {
                $i++;
                continue;
            }

            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }

            if (!$inSingle && !$inDouble && $ch === '=' && $s[$i + 1] === '>') {
                return $i;
            }
        }

        return false;
    }

    /**
     * Compile a Clarity lambda expression to a PHP static closure.
     *
     * Syntax:
     *   param => body_expression
     *   acc, item => body_expression    (reduce only)
     *
     * - Each lambda parameter becomes a PHP closure parameter with the same name.
     * - 'map' and 'filter' require exactly one parameter.
     * - 'reduce' requires exactly two parameters so you can write:
     *       carry, item => carry + item
     * - The body is compiled as a full Clarity expression (including filter
     *   pipelines) with the parameter name(s) treated as local variables,
     *   while all other identifiers are resolved from the captured $__c_va.
     * - $__c_va is always captured; $__c_fn and $__c_sv are added to the `use`
     *   clause only when the compiled body actually references them (they are
     *   render-frame locals, so an uncaptured reference would fatal at render
     *   time — see {@see $captures} below).
     *
     * @param string $arg      The full lambda string (e.g. 'item => item.name').
     * @param int    $arrow    Position of '=>' in $arg.
     * @param string $filterName The callable filter currently being compiled.
     */
    private function compileLambda(string $arg, int $arrow, string $filterName): string
    {
        $paramList = \trim(\substr($arg, 0, $arrow));
        $body      = \trim(\substr($arg, $arrow + 2));

        $first = \strstr($paramList, ',', true);
        if ($first === false) {
            $first = $paramList;
        } else {
            $second = \ltrim(\substr($paramList, \strpos($paramList, ',') + 1));
            if (!\preg_match(self::IDENT_RE, $second)) {
                throw new ClarityException("Invalid lambda parameter: '{$second}'");
            }
        }
        if (!\preg_match(self::IDENT_RE, $first)) {
            throw new ClarityException("Invalid lambda parameter: '{$first}'");
        }

        // Compile the body as a full Clarity expression (handles |> pipelines).
        //
        // The parameter frame is pushed FIRST, so during compilation a root that
        // names a parameter of THIS lambda — or of any enclosing one — is emitted
        // as the bare `$name` by rootPhp(). No post-hoc string substitution is
        // needed, and a NESTED lambda's inner body can reference the outer
        // lambda's parameter directly, because that name is still on the stack.
        //
        // The stack (not a boolean) is what makes nesting work: `inLambda` alone
        // only said "not the render scope", which turned an outer parameter into
        // an absent `$__c_va[...]` read.
        $params = [$first => true];
        if (isset($second)) {
            $params[$second] = true;
        }

        $this->lambdaFrames[] = $params;
        try {
            $phpBody = $this->processCondition($body);
        } finally {
            \array_pop($this->lambdaFrames);
        }

        $signature = "mixed \${$first}";

        if ($filterName === 'reduce') {
            if (!isset($second)) {
                throw new ClarityException(
                    "The 'reduce' filter lambda must declare two parameters separated by a comma "
                        . "(e.g. 'acc, item => acc + item'), got: '{$paramList}'"
                );
            }
            $signature .= ", mixed \${$second}";
        } elseif (isset($second)) {
            throw new ClarityException(
                "The '{$filterName}' filter lambda must declare only one parameter, got: '{$paramList}'"
            );
        }

        // The closure captures $__c_va unconditionally (outer template variables
        // are always read through it). Two further groups of names are captured
        // only when the compiled body actually references them:
        //
        //   • $__c_fn / $__c_sv — render-frame LOCALS, not parameters. Without
        //     the capture a body reaching the callable registry or a service
        //     fatals with "Variable $__c_fn is not defined".
        //   • an ENCLOSING lambda's parameter — a local of the closure that
        //     declared it. A nested lambda referencing it compiles to a bare
        //     `$name` (see rootPhp()), and PHP only binds that from an explicit
        //     `use (...)`; without it the reference is undefined at render time.
        //
        // $this->lambdaFrames now holds only the ENCLOSING frames (this lambda's
        // own frame was popped above), so its parameters are exactly the ones
        // that need capturing.
        $captures = ['$__c_va'];
        foreach (['$__c_fn', '$__c_sv'] as $internal) {
            if (\str_contains($phpBody, $internal)) {
                $captures[] = $internal;
            }
        }
        foreach ($this->lambdaFrames as $frame) {
            foreach ($frame as $enclosing => $_) {
                if (isset($params[$enclosing]) || \in_array('$' . $enclosing, $captures, true)) {
                    continue;
                }
                // Word-boundary match so `$c` does not match `$count`, and `$x`
                // does not match `$__c_va['x']` (the quote is not a word char).
                if (\preg_match('/\$' . \preg_quote($enclosing, '/') . '\b/', $phpBody)) {
                    $captures[] = '$' . $enclosing;
                }
            }
        }
        $useClause = 'use (' . \implode(', ', $captures) . ')';

        return "static function({$signature}) {$useClause}: mixed { return {$phpBody}; }";
    }
}
