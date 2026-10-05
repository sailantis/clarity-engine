<?php

namespace Clarity\Engine\Tokenizer;

use Clarity\ClarityException;

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
     * Anything else (bare variable names, function calls, …) is rejected.
     *
     * Emitted closures are NON-static so that `$this` stays bound: that is what
     * a directive or inline-filter snippet can then read services through
     * (`$this->services['key']`), wherever the compiler has to wrap it in a
     * closure — a lambda body or a quoted filter reference.
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
                //
                // `dump` has no callable form that can serve as a filter: `map`
                // calls the reference as a unary FUNCTION and uses its return
                // value, whereas the filter form `{{ x |> dump }}` must EMIT the
                // dump and still yield the value (a callable cannot do both).  It
                // is therefore wrapped in a unary closure over the pass-through
                // probe service, and — like every other dump form — that closure
                // is the identity in production, so a debug reference cannot
                // print into production output.
                $isRegisteredFilter = $this->registry !== null
                    && ($this->registry->hasFilter($refName) || $this->registry->hasInlineFilter($refName));

                if ($isRegisteredFilter) {
                    if (isset($this->filterProbes[$refName])) {
                        if (isset($this->prunedFunctions[$refName])) {
                            return 'fn(mixed $__c_value): mixed => $__c_value';
                        }
                        return "fn(mixed \$__c_value): mixed => "
                            . "\$__c_sv['" . \addslashes($this->filterProbes[$refName]) . "']"
                            . "('" . \addslashes($this->escapeContext) . "', \$__c_value)";
                    }
                    return "\$__c_fn['" . \addslashes($refName) . "']";
                }

                // A quoted name that is not a registered filter may name a PHP
                // function, but only where the `phpFunctions` rule is on.
                if ($this->policy->allows('phpFunctions') && $this->registry !== null) {
                    if (!$this->isFunctionCallAllowed($refName)) {
                        throw new ClarityException(
                            "Function '{$refName}' is not allowed by this policy: it is not in the "
                                . 'function allowlist, or it is denied. Add it with allowFunctions().'
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

        return "fn(mixed \$__c_val): mixed => {$inlineCall}";
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
     * Compile a Clarity lambda expression to an arrow function.
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
     *   while all other identifiers are resolved from `$__c_va`, which the
     *   emitted arrow function captures by value.
     * - The arrow function binds implicitly, so no `use` clause is needed: it
     *   sees `$__c_va`, `$__c_fn`, `$__c_sv` and `$this` — and an enclosing
     *   lambda's parameter — exactly when the body references them.
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

        // Emitted as a non-static arrow function, so the closure INHERITS `$this`
        // from the render frame: a directive or inline-filter snippet the body
        // reaches then still resolves `$this->services['key']`.  `fn()` binds its
        // captures implicitly — `$__c_va` because the body reads outer variables
        // through it, `$__c_fn` / `$__c_sv` when the body reaches a registry, an
        // enclosing lambda's parameter because the body names it, and `$this`
        // always at call time — so no `use` clause has to be computed here.
        return "fn({$signature}): mixed => {$phpBody}";
    }
}
