<?php

namespace Clarity\Engine\Tokenizer;

use Clarity\ClarityException;

/**
 * Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.
 */
trait FilterCompilerTrait
{

    /**
     * If $arg is a named argument of the form  identifier:expression  (where
     * : is not part of ::), return ['name'=>â€¦, 'expr'=>â€¦].
     * Returns null for ordinary positional arguments.
     */
    private function parseNamedArg(string $arg): ?array
    {
        // identifier followed by = that is not == ; also must not be !=, <=, >=
        if (\preg_match('/^\s*([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*:(?!:)(.+)$/s', $arg, $m)) {
            return ['name' => $m[1], 'expr' => \trim($m[2])];
        }
        return null;
    }

    /**
     * Compile a list of raw argument strings (already split on `,`) to PHP expressions.
     *
     * Named arguments (`identifier=expression`) are emitted as PHP named arguments
     * (`identifier: phpExpr`), letting PHP validate parameter names and arity at
     * runtime. This means function and filter signatures can change without requiring
     * template recompilation.
     *
     * Positional arguments are compiled as full Clarity expressions (pipelines and
     * nested function calls are supported).
     *
     * A positional argument after a named argument is rejected at compile time to
     * prevent generating syntactically invalid PHP.
     *
     * @param  string[] $argList Raw argument strings (already split on ',').
     * @return string[] Compiled PHP argument strings, ready to join with ', '.
     */
    private function compileArgList(array $argList): array
    {
        $result    = [];
        $seenNamed = false;
        foreach ($argList as $arg) {
            $arg = \trim($arg);
            if (\str_starts_with($arg, '...')) {
                throw new ClarityException('Spread operator is only allowed inside array and object literals.');
            }
            $named = $this->parseNamedArg($arg);
            if ($named !== null) {
                $seenNamed = true;
                $result[] = $named['name'] . ': ' . $this->processCondition($named['expr']);
            } else {
                if ($seenNamed) {
                    throw new ClarityException(
                        "Positional argument after named argument in argument list: '{$arg}'"
                    );
                }
                $result[] = $this->processCondition($arg);
            }
        }
        return $result;
    }

    /**
     * @param string[] $argList
     * @return array{0: string[], 1: array<string, string>}
     */
    private function compileFilterArguments(array $argList): array
    {
        $positional = [];
        $named      = [];
        $seenNamed  = false;

        foreach ($argList as $arg) {
            $arg = \trim($arg);
            if (\str_starts_with($arg, '...')) {
                throw new ClarityException('Spread operator is only allowed inside array and object literals.');
            }

            $parsedNamed = $this->parseNamedArg($arg);
            if ($parsedNamed !== null) {
                $seenNamed = true;
                $named[$parsedNamed['name']] = $this->processCondition($parsedNamed['expr']);
                continue;
            }

            if ($seenNamed) {
                throw new ClarityException(
                    "Positional argument after named argument in argument list: '{$arg}'"
                );
            }

            $positional[] = $this->processCondition($arg);
        }

        return [$positional, $named];
    }

    /**
     * map/filter/reduce accept a callable as their first argument. For reduce,
     * that callable may declare two comma-separated parameters on the left side
     * of the lambda arrow, so we merge split segments back into one callable arg.
     *
     * @return string[]
     */
    private function splitCallableFilterArgs(string $args): array
    {
        return $this->splitCallableArgs($args, false);
    }

    /**
     * Split an argument list in which the CALLABLE argument (a lambda or a
     * quoted filter reference) may span several comma-separated segments,
     * because `reduce` lambdas declare two parameters before the arrow
     * (`carry, item => …`).
     *
     * @param bool $valueFirst When true, the value leads the argument list and
     *                         the callable is the SECOND argument (call form
     *                         `map(items, x => …)`); when false the callable is
     *                         the first argument (filter form `items |> map(…)`).
     * @return string[]
     */
    private function splitCallableArgs(string $args, bool $valueFirst): array
    {
        // Split top-level commas (your existing helper)
        $parts = $this->splitRespectingStrings($args, ',');

        if ($parts === []) {
            return [];
        }

        // Call form: the value leads, so leave it untouched and merge the
        // lambda across the segments that follow it.
        $offset = $valueFirst ? 1 : 0;

        if ($valueFirst && $this->findLambdaArrow($parts[0]) !== false) {
            // `map(x => …, …)` — the value is itself a lambda (nonsensical but
            // must not be mistaken for the callable). Treat segment 0 as value.
            $offset = 1;
        }

        if ($offset >= \count($parts)) {
            return $parts;
        }

        // Case 1: the callable segment already contains =>
        if ($this->findLambdaArrow($parts[$offset]) !== false) {
            return $parts;
        }

        // Case 2: find the first later segment that contains =>
        $lambdaEnd = null;
        $count     = \count($parts);

        for ($i = $offset + 1; $i < $count; $i++) {
            if ($this->findLambdaArrow($parts[$i]) !== false) {
                $lambdaEnd = $i;
                break;
            }
        }

        // No lambda found → nothing to merge
        if ($lambdaEnd === null) {
            return $parts;
        }

        // Merge everything from the callable's start up to the lambda arrow
        // into one argument.
        $lambdaArg = '';
        for ($i = $offset; $i <= $lambdaEnd; $i++) {
            if ($i > $offset) {
                $lambdaArg .= ', ';
            }
            $lambdaArg .= \trim($parts[$i]);
        }

        // Build final argument list: everything before the callable (the value,
        // in the call form), then the merged callable, then the rest.
        $result = [];
        for ($i = 0; $i < $offset; $i++) {
            $result[] = $parts[$i];
        }
        $result[] = $lambdaArg;

        for ($i = $lambdaEnd + 1; $i < $count; $i++) {
            $result[] = $parts[$i];
        }

        return $result;
    }

    /**
     * Build a PHP filter call:  $__c_fn['name']($value, arg1, name2: arg2)
     *
     * For map / filter / reduce the first argument must be either:
     *   - a lambda expression:  param => expression
     *   - a filter reference:   'filterName' or "filterName"
     * Bare variable names are rejected at compile time.
     *
     * Named arguments (`identifier=expression`) are emitted directly as PHP named
     * arguments (`identifier: phpExpr`). PHP validates names and arity at runtime.
     *
     * @param string $filterSegment Clarity filter segment e.g. 'number(2)' or 'upper'
     * @param string $phpValue      Already-converted PHP expression for the input value.
     * @return string PHP call expression.
     */
    public function buildFilterCall(string $filterSegment, string $phpValue): string
    {
        $parsed = $this->tryParseFilterWithTrailing($filterSegment);
        if ($parsed === null) {
            throw new ClarityException("Invalid filter segment: '{$filterSegment}'");
        }
        $name     = $parsed['name'];
        $args     = $parsed['args'];
        $trailing = $parsed['trailing'];

        if ($name === 'raw') {
            $this->autoEscape = false;
            return $phpValue;
        }

        if ($args === '') {
            $argList = [];
        } elseif (isset(self::CALLABLE_ARG_FILTERS[$name])) {
            $argList = $this->splitCallableFilterArgs($args);
        } else {
            $argList = $this->splitRespectingStrings($args, ',');
        }

        $isCallableFilter = isset(self::CALLABLE_ARG_FILTERS[$name]);
        $isRegistered     = $isCallableFilter
            || ($this->registry !== null && ($this->registry->hasInlineFilter($name) || $this->registry->hasFilter($name)));

        // A name that is callable but NOT filterable (`context`, `include`,
        // `dump`, `dd`) was registered for call syntax only; its first parameter
        // is not a piped value. Rejecting it here turns `{{ x |> context }}`
        // into a clear compile-time error in BOTH modes — the runtime table no
        // longer carries these names, so without this guard the failure would
        // surface as an opaque "call to undefined array key" mid-render.
        if (
            $this->registry !== null
                && !$isRegistered
                && $this->registry->hasCallable($name)
                && !$this->registry->hasFilter($name)
        ) {
            throw new ClarityException(
                "'{$name}' is a function, not a filter; call it as {$name}(…)."
            );
        }

        // An unregistered filter name in SANDBOX mode is rejected HERE, not at
        // runtime. It used to compile to a `$__c_fn[...]` lookup and fail on the
        // first render, which reported `Variable "strtoupper" is not defined in
        // this context` — naming a variable the template never wrote, and
        // surfacing on a request rather than at the deploy that introduced it.
        //
        // The check can be made here because sandbox mode leaves NOTHING for an
        // unregistered name to fall back to: the open-mode branch below is the
        // only other resolution path, and it is not reachable while the sandbox
        // is on. So "not registered" and "cannot ever resolve" are the same
        // statement, and the message can say what to do about it instead of
        // describing the runtime table it would have consulted.
        if (!$isRegistered && $this->sandboxMode) {
            throw new ClarityException(
                "Filter '{$name}' is not registered, and the sandbox is enabled, so there is "
                    . 'nothing for it to resolve to. Register it with addFilter(), or call '
                    . "setSandboxMode(false) to let a PHP function of the same name be used."
            );
        }

        // Registered filters win over PHP functions of the same name.
        if ($isRegistered) {
            $inlineCall = $this->buildInlineFilterCall($name, $phpValue, $argList);
            if ($inlineCall !== null) {
                return $trailing !== '' ? $inlineCall . $this->convertVarsAndOps($trailing) : $inlineCall;
            }

            $compiledArgs = [];

            if ($argList !== []) {
                if ($isCallableFilter) {
                    // map/filter/reduce: first arg is a lambda/filter-ref, rest are positional only.
                    foreach ($argList as $i => $arg) {
                        $arg = \trim($arg);
                        $compiledArgs[] = $i === 0
                            ? $this->compileCallableArg($arg, $name)
                            : $this->processCondition($arg);
                    }
                } else {
                    // Standard filter: emit args directly; named args become PHP named args.
                    $compiledArgs = $this->compileArgList($argList);
                }
            }

            // The filter form binds the piped value first (or, when the filter
            // declares a `valueParam`, in that parameter's slot — Phase 2).
            \array_unshift($compiledArgs, $phpValue);
            $call = $this->buildCall($name, $compiledArgs);

            if ($trailing !== '') {
                $call .= $this->convertVarsAndOps($trailing);
            }
            return $call;
        }

        // Open mode: a filter name that is not registered resolves to a PHP
        // function of the same name (Blade / Stempler / Plates parity).
        return $this->buildOpenFilterCall($name, $phpValue, $argList, $trailing);
    }

    /**
     * Emit a registry-dispatched call: `$__c_fn['name'](args)`.
     *
     * This is the ONE place a registered name becomes PHP. Both the pipe form
     * ({@see buildFilterCall()}) and the call-syntax form
     * ({@see buildFunctionCallInExpr()}) funnel through it after compiling
     * their own argument lists; the only difference is whether the piped value
     * leads the arguments (and where the value slot lands — see
     * {@see resolveInlineFilterSlots()}). There is one runtime table, not two:
     * a registered name is callable, and whether it may be piped is a separate,
     * compile-time question ({@see buildFilterCall()}).
     *
     * @param list<string> $compiledArgs Already-compiled PHP argument expressions.
     */
    private function buildCall(string $name, array $compiledArgs): string
    {
        $safeName = "'" . \addslashes($name) . "'";
        return '$__c_fn' . '[' . $safeName . '](' . \implode(', ', $compiledArgs) . ')';
    }

    /**
     * Compile a filter segment that resolves to a PHP function (open mode only).
     *
     * By default the piped value becomes the first argument:
     *   {{ 'ab' |> strtoupper }}               â†’ \strtoupper($value)
     *   {{ 'a' |> str_replace('a', 'b') }}     â†’ \str_replace($value, 'a', 'b')
     *
     * A single `_` placeholder in the argument list positions the value
     * explicitly, for functions whose value argument is not first:
     *   {{ 'k' |> array_key_exists(_, $arr) }} â†’ \array_key_exists($value, $arr)
     *
     * @param list<string> $argList Raw argument strings (already comma-split).
     * @param string       $phpValue Already-compiled PHP for the piped value.
     * @param string       $trailing Trailing comparison operator, if any.
     */
    private function buildOpenFilterCall(string $name, string $phpValue, array $argList, string $trailing): string
    {
        if (!$this->isFunctionCallAllowed($name)) {
            throw new ClarityException(
                "Function '{$name}' is blocked in PHP mode. Allow it by removing it from the deny-list."
            );
        }

        $fn = \ltrim($name, '\\');
        if (!\function_exists($fn)) {
            throw new ClarityException(
                "Unknown filter '{$name}': no filter is registered under that name and no PHP function '{$fn}()' exists."
            );
        }

        $callee = '\\' . $fn;

        if ($argList === []) {
            $call = $callee . '(' . $phpValue . ')';
            return $trailing !== '' ? $call . $this->convertVarsAndOps($trailing) : $call;
        }

        $prevInArgs = $this->inOpenFilterArgs;
        $prevValue  = $this->openFilterValue;
        $this->inOpenFilterArgs = true;
        $this->openFilterValue  = $phpValue;

        try {
            $outArgs   = [];
            $seenNamed = false;
            $valuePos  = null;

            foreach ($argList as $idx => $arg) {
                $arg = \trim($arg);
                if (\str_starts_with($arg, '...')) {
                    throw new ClarityException('Spread operator is only allowed inside array and object literals.');
                }

                if ($arg === '_') {
                    if ($valuePos !== null) {
                        throw new ClarityException("Filter '{$name}' may use the '_' placeholder only once.");
                    }
                    $valuePos = $idx;
                    $outArgs[$idx] = $phpValue;
                    continue;
                }

                $named = $this->parseNamedArg($arg);
                if ($named !== null) {
                    $seenNamed = true;
                    $outArgs[$idx] = $named['name'] . ': ' . $this->processCondition($named['expr']);
                    continue;
                }

                if ($seenNamed) {
                    throw new ClarityException(
                        "Positional argument after named argument in argument list: '{$arg}'"
                    );
                }

                $outArgs[$idx] = $this->processCondition($arg);
            }
        } finally {
            $this->inOpenFilterArgs = $prevInArgs;
            $this->openFilterValue  = $prevValue;
        }

        if ($valuePos === null) {
            \array_unshift($outArgs, $phpValue);
        }

        $call = $callee . '(' . \implode(', ', $outArgs) . ')';
        return $trailing !== '' ? $call . $this->convertVarsAndOps($trailing) : $call;
    }

    /**
     * When a filter segment contains a trailing comparison operator
     * (e.g. `length > 1`, `count == 0`, `upper != 'FOO'`) this method
     * extracts the filter name, optional balanced argument list, and the
     * trailing operator+operand as a raw Clarity expression.
     *
     * Returns null when the segment cannot be parsed this way.
     *
     * @return array{name:string,args:string,trailing:string}|null
     */
    private function tryParseFilterWithTrailing(string $filterSegment): ?array
    {
        $segment = \ltrim($filterSegment);

        // Must start with a valid identifier
        if (!\preg_match('/^([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/', $segment, $m)) {
            return null;
        }
        $name = $m[1];
        $rest = \ltrim(\substr($segment, \strlen($name)));

        // Optional balanced argument list in parentheses
        $args = '';
        if (($rest[0] ?? '') === '(') {
            [$args, $endPos] = $this->extractBalancedSegment($rest, 0);
            $rest = \ltrim(\substr($rest, $endPos));
        }

        // Plain filter â€” no trailing comparison operator
        if (\trim($rest) === '') {
            return ['name' => $name, 'args' => $args, 'trailing' => ''];
        }

        // Rest must be an operator followed by a non-empty operand.
        // `??` (null-coalescing) is included so a filter may be followed by a
        // fallback: `{{ items |> length ?? 0 }}`, `{{ x ?? 'fb' }}`.
        // Note: `??` catches a NULL RETURN only â€” it cannot catch an exception.
        if (!\preg_match('/^(===|!==|==|!=|>=|<=|<>|\?\?|>|<)(.+)$/s', $rest, $cm)) {
            return null;
        }

        return [
            'name'     => $name,
            'args'     => $args,
            'trailing' => ' ' . $cm[1] . ' ' . \ltrim($cm[2]),
        ];
    }

    /**
     * @param string[] $argList
     */
    private function buildInlineFilterCall(string $name, string $phpValue, array $argList): ?string
    {
        $definition = $this->registry->getInlineFilter($name);
        if ($definition === null) {
            return null;
        }

        [$positionalArgs, $namedArgs] = $this->compileFilterArguments($argList);

        if (($definition['variadic'] ?? false) === true) {
            return $this->buildInlineVariadicFilterCall($name, $definition['php'], $phpValue, $positionalArgs, $namedArgs);
        }

        $slots = $this->resolveInlineFilterSlots($name, $definition, $phpValue, $positionalArgs, $namedArgs);
        return $this->substituteInlineFilterTemplate($definition['php'], $slots);
    }

    /**
     * Compile an inline filter's CALL form: `name(a1, a2, …)`.
     *
     * The call form is derived from the same `php` template as the filter form;
     * only the SLOT ASSIGNMENT differs:
     *   - valueParam UNSET: `arg[0]` is the value (`{1}`), the rest fill `params`.
     *     This is byte-identical to the filter form, so `round(x, 2)` compiles
     *     exactly like `x |> round(2)`.
     *   - valueParam SET: every argument fills its `params` slot in order; the
     *     value param has no default (e.g. `join`), so calling it without
     *     supplying it is a loud compile error.
     *
     * Returns null when the name has no inline template (callable-only or
     * unregistered); the caller then falls back to `$__c_fn` dispatch or a
     * direct PHP call.
     *
     * @param string[] $argList Raw, comma-split argument strings.
     */
    private function buildInlineCallForm(string $name, array $argList): ?string
    {
        $definition = $this->registry->getInlineFilter($name);
        if ($definition === null) {
            return null;
        }

        [$positionalArgs, $namedArgs] = $this->compileFilterArguments($argList);

        if (($definition['variadic'] ?? false) === true) {
            // Value-led variadic: the first argument is the value (the format
            // string), the rest are the variadic arguments.
            if ($positionalArgs === [] || $namedArgs !== []) {
                throw new ClarityException(
                    "Function '{$name}' requires a positional value argument."
                );
            }
            $value = $positionalArgs[0];
            $rest  = \array_slice($positionalArgs, 1);

            return $this->buildInlineVariadicFilterCall($name, $definition['php'], $value, $rest, []);
        }

        $params     = $definition['params'] ?? [];
        $defaults   = $definition['defaults'] ?? [];
        $valueParam = $definition['valueParam'] ?? null;

        if ($valueParam === null) {
            // Value-led: the first argument is the value; the rest are params.
            if ($positionalArgs === [] && $namedArgs === []) {
                throw new ClarityException(
                    "Function '{$name}' requires at least the value argument."
                );
            }

            $value = $positionalArgs[0] ?? null;
            if ($value === null) {
                throw new ClarityException(
                    "Function '{$name}' cannot take the value as a named argument."
                );
            }
            $restPositional = \array_slice($positionalArgs, 1);

            $slots    = [1 => $value];
            $assigned = [];
            foreach ($restPositional as $index => $phpArg) {
                if (!isset($params[$index])) {
                    throw new ClarityException(
                        "Function '{$name}' received too many positional arguments."
                    );
                }
                $slots[$index + 2] = $phpArg;
                $assigned[$params[$index]] = true;
            }
            $this->fillDefaultSlots($name, 'Function', $params, $defaults, 2, $slots, $assigned, $namedArgs);

            return $this->substituteInlineFilterTemplate($definition['php'], $slots);
        }

        // Params-led: every argument is a declared param (value included).
        $slots    = [];
        $assigned = [];
        foreach ($positionalArgs as $index => $phpArg) {
            if (!isset($params[$index])) {
                throw new ClarityException(
                    "Function '{$name}' received too many positional arguments."
                );
            }
            $slots[$index + 1] = $phpArg;
            $assigned[$params[$index]] = true;
        }

        if (\array_key_exists($valueParam, $namedArgs)) {
            $valueIndex = \array_search($valueParam, $params, true);
            if ($valueIndex === false) {
                throw new ClarityException(
                    "Function '{$name}' declares valueParam '{$valueParam}', which is not one of its params."
                );
            }
            $slots[$valueIndex + 1] = $namedArgs[$valueParam];
            $assigned[$valueParam] = true;
            unset($namedArgs[$valueParam]);
        }

        $this->fillDefaultSlots($name, 'Function', $params, $defaults, 1, $slots, $assigned, $namedArgs);

        return $this->substituteInlineFilterTemplate($definition['php'], $slots);
    }

    /**
     * Fill unfilled `params` slots from `$namedArgs` or `$defaults`, throwing
     * on an unknown name, a duplicate, or a missing required argument.
     *
     * @param list<string>          $params
     * @param array<string, string> $defaults
     * @param array<int, string>    $slots    Modified in place.
     * @param array<string, true>   $assigned Modified in place.
     * @param array<string, string> $namedArgs
     */
    private function fillDefaultSlots(
        string $name,
        string $form,
        array $params,
        array $defaults,
        int $base,
        array &$slots,
        array &$assigned,
        array $namedArgs,
    ): void {
        foreach ($namedArgs as $paramName => $phpArg) {
            $paramIndex = \array_search($paramName, $params, true);
            if ($paramIndex === false) {
                throw new ClarityException(
                    "Unknown named argument '{$paramName}' for {$form} '{$name}'."
                );
            }
            if (isset($assigned[$paramName])) {
                throw new ClarityException(
                    "{$form} '{$name}' received '{$paramName}' more than once."
                );
            }
            $slots[$paramIndex + $base] = $phpArg;
            $assigned[$paramName] = true;
        }

        foreach ($params as $index => $paramName) {
            $slotIndex = $index + $base;
            if (isset($slots[$slotIndex])) {
                continue;
            }
            if (isset($defaults[$paramName])) {
                $slots[$slotIndex] = $defaults[$paramName];
                continue;
            }
            throw new ClarityException(
                "Missing required argument '{$paramName}' for {$form} '{$name}'."
            );
        }
    }

    /**
     * Resolve a filter template's numeric slots for the FILTER form.
     *
     * Slot model
     * ----------
     *   valueParam UNSET : `{1}` = the piped value, `{2}`, `{3}`, … = `params`.
     *   valueParam SET   : `{1}`, `{2}`, … = `params` in declared order, and the
     *                      piped value lands on the slot of the named param
     *                      (so `join`'s `{2}` is its `array`, `date`'s `{2}` is
     *                      its `date`). This lets a template be written in the
     *                      same order as the PHP call it compiles to.
     *
     * @param array{php?: string, params?: string[], defaults?: array<string, string>, variadic?: bool, valueParam?: string} $definition
     * @param string[] $positionalArgs
     * @param array<string, string> $namedArgs
     * @return array<int, string>
     */
    private function resolveInlineFilterSlots(string $filterName, array $definition, string $phpValue, array $positionalArgs, array $namedArgs): array
    {
        $params     = $definition['params'] ?? [];
        $defaults   = $definition['defaults'] ?? [];
        $valueParam = $definition['valueParam'] ?? null;

        if ($valueParam === null) {
            $slotBase  = 2;
            $valueSlot = 1;
        } else {
            $valueIndex = \array_search($valueParam, $params, true);
            if ($valueIndex === false) {
                throw new ClarityException(
                    "Filter '{$filterName}' declares valueParam '{$valueParam}', which is not one of its params."
                );
            }
            $slotBase  = 1;
            $valueSlot = $valueIndex + 1;
        }

        $slots    = [$valueSlot => $phpValue];
        $assigned = [];

        foreach ($positionalArgs as $index => $phpArg) {
            if (!isset($params[$index])) {
                throw new ClarityException(
                    "Filter '{$filterName}' received too many positional arguments."
                );
            }

            $paramName = $params[$index];
            $slotIndex = $index + $slotBase;
            if ($slotIndex === $valueSlot) {
                throw new ClarityException(
                    "Filter '{$filterName}' received a positional argument for '{$paramName}', which receives the piped value."
                );
            }

            $slots[$slotIndex] = $phpArg;
            $assigned[$paramName] = true;
        }

        foreach ($namedArgs as $paramName => $phpArg) {
            $paramIndex = \array_search($paramName, $params, true);
            if ($paramIndex === false) {
                throw new ClarityException(
                    "Unknown named argument '{$paramName}' for filter '{$filterName}'."
                );
            }
            if ($paramName === $valueParam) {
                throw new ClarityException(
                    "Filter '{$filterName}' cannot bind '{$paramName}': it receives the piped value."
                );
            }
            if (isset($assigned[$paramName])) {
                throw new ClarityException(
                    "Filter '{$filterName}' received '{$paramName}' more than once."
                );
            }

            $slots[$paramIndex + $slotBase] = $phpArg;
            $assigned[$paramName] = true;
        }

        foreach ($params as $index => $paramName) {
            $slotIndex = $index + $slotBase;
            if (isset($slots[$slotIndex])) {
                continue;
            }
            if (isset($defaults[$paramName])) {
                $slots[$slotIndex] = $defaults[$paramName];
                continue;
            }
            throw new ClarityException(
                "Missing required argument '{$paramName}' for filter '{$filterName}'."
            );
        }

        return $slots;
    }

    /**
     * @param string[] $positionalArgs
     * @param array<string, string> $namedArgs
     */
    private function buildInlineVariadicFilterCall(string $filterName, string $name, string $phpValue, array $positionalArgs, array $namedArgs): ?string
    {
        if ($namedArgs !== []) {
            $firstNamedArg = \array_key_first($namedArgs);
            throw new ClarityException(
                "Unknown named argument '{$firstNamedArg}' for filter '{$filterName}'."
            );
        }

        $pieces = ["(string) ({$phpValue})"];
        foreach ($positionalArgs as $phpArg) {
            $pieces[] = '(' . $phpArg . ')';
        }

        return $name . '(' . \implode(', ', $pieces) . ')';
    }

    /**
     * @param array<int, string> $slots
     */
    private function substituteInlineFilterTemplate(string $template, array $slots): string
    {
        return (string) \preg_replace_callback(
            '/\{(\d+)\}/',
            static function (array $matches) use ($slots): string {
                $slotIndex = (int) $matches[1];
                if (!isset($slots[$slotIndex])) {
                    throw new ClarityException("Missing filter argument {{$slotIndex}}.");
                }

                return '(' . $slots[$slotIndex] . ')';
            },
            $template
        );
    }
}
