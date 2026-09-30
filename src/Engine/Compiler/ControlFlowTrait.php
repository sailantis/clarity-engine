<?php

namespace Clarity\Engine\Compiler;

use Clarity\ClarityException;

/**
 * Extracted from Clarity\Engine\Compiler to keep each file small. See that class for docs.
 */
trait ControlFlowTrait
{

    /**
     * `{% for %} … ` header: `name in expr`, `key, value in expr`, or
     * `var in start..end [step n]`.
     *
     * The bound names use PHP's variable-name grammar (high bytes included), so
     * a non-ASCII loop variable parses here and is then validated by
     * registerVar()/Tokenizer::isIdentifier().
     */
    private const RE_FOR_IN = '/^([a-zA-Z_\x80-\xff][a-zA-Z0-9_.\x80-\xff]*)(?:\s*,\s*([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*))?\s+in\s+(.+?)(?:(\.\.\.?)(.+?)(?:\s+step\s+(.+))?)?$/s';

    /**
     * Compile {% for item in list %} → PHP foreach.
     * Compile {% for key, item in list %} → PHP foreach.
     * Compile {% for i in start..end %} / {% for i in start...end [step N] %} → native PHP for.
     *
     * Two-variable form: the FIRST name is the KEY and the SECOND is the VALUE,
     * matching Twig's `{% for key, user in users %}`.
     *
     * Range syntax:
     *   ..   inclusive upper bound  (start ≤ i ≤ end)
     *   ...  exclusive upper bound  (start ≤ i < end)
     * An optional `step N` suffix controls the increment (default: 1).
     */
    private function compileFor(string $rest, string $sourcePath, int $tplLine): string
    {
        $rest = trim($rest);

        if (!\preg_match(self::RE_FOR_IN, $rest, $m)) {
            throw new ClarityException("Malformed for directive: 'for {$rest}'", $sourcePath, $tplLine);
        }

        // Range syntax: varName in startExpr(..|...)endExpr [step stepExpr]
        if (isset($m[4]) && $m[4] !== '') {
            // Evaluate bounds in the current (outer) scope before registering the loop var
            $start     = $this->tokenizer->processCondition(trim($m[3]));
            $inclusive = ($m[4] === '..');
            $end       = $this->tokenizer->processCondition(trim($m[5]));
            $step      = isset($m[6]) && $m[6] !== '' ? $this->tokenizer->processCondition(trim($m[6])) : '1';
            $cmp       = $inclusive ? '<=' : '<';

            // Allocate a local PHP variable for the iteration variable (same name as template var)
            $itemTplName = trim($m[1]);
            $itemPhpVar  = '$' . $itemTplName;
            $restore     = [$itemTplName => $this->localVars[$itemTplName] ?? null];
            $this->registerVar($itemTplName, $tplLine);

            $this->forStack[] = [
                'type'       => 'for',
                'restore'    => $restore,
                'ifDepth'    => $this->ifDepth,
                'headerLine' => -1,
                'hasElse'    => false,
            ];
            $this->forHeaderPending = true;

            $rangeLines = [];

            if ($this->debugMode) {
                $srcLabel = addslashes($sourcePath . ':' . $tplLine);
                $rangeLines[] = "if ({$step} === 0) { throw new \\RuntimeException('Clarity: range step cannot be zero ({$srcLabel})'); }";
                $rangeLines[] = "if (({$end} - {$start}) * {$step} < 0) { throw new \\RuntimeException('Clarity: range step moves away from end, would produce an infinite loop ({$srcLabel})'); }";
            }

            $rangeLines[] = "for ({$itemPhpVar} = {$start}; {$itemPhpVar} {$cmp} {$end}; {$itemPhpVar} += {$step}):";

            return implode("\n", $rangeLines);
        }

        // Standard foreach — evaluate list in the current (outer) scope first
        $listExpr = $this->tokenizer->processCondition(trim($m[3]));

        $firstName = trim($m[1]);
        $hasSecond = isset($m[2]) && $m[2] !== '';

        // The two-variable form is (key, value) — Twig order — so the FIRST name
        // binds the key and the SECOND binds the value. With a single name it is
        // the value, matching {% for item in items %}.
        $keyTplName  = $hasSecond ? $firstName : null;
        $itemTplName = $hasSecond ? trim($m[2]) : $firstName;

        /** @var array<string, string|null> $restore */
        $restore = [];
        foreach ([$keyTplName, $itemTplName] as $tplName) {
            if ($tplName === null || isset($restore[$tplName])) {
                continue;
            }
            $restore[$tplName] = $this->localVars[$tplName] ?? null;
            $this->registerVar($tplName);
        }

        $this->forStack[] = [
            'type'       => 'foreach',
            'restore'    => $restore,
            'ifDepth'    => $this->ifDepth,
            'headerLine' => -1,
            'hasElse'    => false,
        ];

        // A later `{% else %}` appends the flag assignment to this loop's header
        // line; see compileElse().  Until then the header is exactly what it
        // would have been without for-else support.
        $this->forHeaderPending = true;

        if ($keyTplName !== null) {
            // PHP's foreach binding is (key => value), so the key variable goes on the left. The template wrote (key, value), matching that order.
            return "foreach ({$listExpr} as \${$keyTplName} => \${$itemTplName}):";
        }

        return "foreach ({$listExpr} as \${$itemTplName}):";
    }

    /**
     * The innermost open `{% for %}` that a branch tag at the CURRENT if-depth
     * belongs to, or null when the branch belongs to an `{% if %}`.
     *
     * Depth is what disambiguates the two meanings of `{% else %}`: a loop
     * opened inside an if has a HIGHER if-depth than that if, so an `{% else %}`
     * at the if's own depth still closes the if and leaves a `{% for %} …
     * {% else %}` pair intact.  Returns the stack index, not the entry, so the
     * caller can patch the entry in place.
     */
    private function innermostLoopAtCurrentDepth(): ?int
    {
        $index = \count($this->forStack) - 1;
        if ($index < 0) {
            return null;
        }

        return $this->forStack[$index]['ifDepth'] === $this->ifDepth ? $index : null;
    }

    /**
     * Compile `{% if expr %}` and remember the new nesting depth.
     */
    private function compileIf(string $rest, string $sourcePath, int $tplLine): string
    {
        $this->ifDepth++;
        return 'if (' . $this->tokenizer->processCondition($rest) . '):';
    }

    /**
     * Compile `{% elseif expr %}`.
     *
     * An `{% elseif %}` inside a loop is always the author's if-chain; a loop
     * cannot have a second branch.  The guard turns the resulting PHP parse
     * error into a compile-time message that names the template line.
     */
    private function compileElseIf(string $rest, string $sourcePath, int $tplLine): string
    {
        if ($this->innermostLoopAtCurrentDepth() !== null) {
            throw new ClarityException(
                "'{% elseif %}' is not valid in a '{% for %}' loop; use '{% else %}' followed by '{% if %}'.",
                $sourcePath,
                $tplLine
            );
        }

        return 'elseif (' . $this->tokenizer->processCondition($rest) . '):';
    }

    /**
     * Compile `{% else %}`.
     *
     * Twig gives `{% else %}` two meanings inside a loop: a branch tag whose
     * innermost open construct is the loop means "the sequence was empty",
     * while a branch tag belonging to an `{% if %}` inside the loop means the
     * ordinary conditional fallback.  {@see innermostLoopAtCurrentDepth()}
     * separates the two.
     *
     * A for-else is compiled by making the loop header record whether it
     * iterated, closing the loop, and opening an `if` on the negation.  PHP has
     * no `for … else`, so this is the only way to express it; and because the
     * `endforeach`/`endfor` keyword depends on the loop type, that choice is
     * deferred to `{% endfor %}` via the entry's `hasElse` marker.
     *
     * @param array $lines Accumulator, needed to patch the loop's header line.
     */
    private function compileElse(string $sourcePath, int $tplLine, array &$lines): string
    {
        $index = $this->innermostLoopAtCurrentDepth();
        if ($index === null) {
            return 'else:';
        }

        $entry = $this->forStack[$index];
        if ($entry['hasElse']) {
            throw new ClarityException(
                "'{% for %}' may only take one '{% else %}' branch.",
                $sourcePath,
                $tplLine
            );
        }

        // Decide the flag name now and rewrite the loop header, which is the only
        // emitted line this touches.  This is the whole point of the lazy
        // strategy: a loop WITHOUT an else is left byte-for-byte as it was, and
        // no line is inserted, so the source map needs no renumbering.
        //
        // The flag is initialised immediately BEFORE the loop, not just set
        // inside it, so that a loop which runs more than once -- a nested loop
        // re-entered by an outer iteration -- starts each pass with a clean flag
        // instead of inheriting `true` from the previous pass.
        $flag = self::INTERNAL_PREFIX . 'e' . $this->forElseSeq++;
        $lines[$entry['headerLine']] =
            '$' . $flag . ' = false; '
                . $lines[$entry['headerLine']]
                . ' $' . $flag . ' = true;';

        $this->forStack[$index]['hasElse'] = true;

        // Twig hides the loop variable in the else branch, so restore the
        // bindings the loop introduced before it, not at `{% endfor %}`.
        $this->restoreLoopVars($entry['restore']);

        // The loop is closed HERE rather than at `{% endfor %}`: the else body
        // follows immediately, so the `if` on the flag has to open now.  The
        // matching `endif;` is emitted by `{% endfor %}`.
        $close = $entry['type'] === 'for' ? 'endfor;' : 'endforeach;';

        return $close . ' if (!$' . $flag . '):';
    }

    /**
     * Compile `{% endif %}` and forget the matching if.
     */
    private function compileEndIf(): string
    {
        if ($this->ifDepth > 0) {
            $this->ifDepth--;
        }

        return 'endif;';
    }

    /**
     * Compile `{% endfor %}` into the closing keyword(s) of the matching loop.
     */
    private function compileEndFor(string $sourcePath, int $tplLine): string
    {
        $entry = array_pop($this->forStack);
        if ($entry === null) {
            throw new ClarityException("Unexpected 'endfor' without matching 'for'", $sourcePath, $tplLine);
        }

        // A for-else already restored the bindings when it opened its else branch.
        if (!$entry['hasElse']) {
            $this->restoreLoopVars($entry['restore']);
        }

        $close = $entry['type'] === 'for' ? 'endfor;' : 'endforeach;';

        // A for-else closed its loop at `{% else %}` and has an `if (!$flag):`
        // open around the else body, so only the `endif;` is left.  A plain loop
        // closes here and needs no `endif;` -- which also means a loop nested in
        // an if cannot leak an extra `endif;` into that if.
        return $entry['hasElse'] ? 'endif;' : $close;
    }

    /**
     * Undo the compile-scope bindings a loop introduced, so code after the loop
     * resolves those names through the render scope again.
     *
     * @param array<string, string|null> $restore name → previous PHP variable string, or null if unbound
     */
    private function restoreLoopVars(array $restore): void
    {
        foreach ($restore as $name => $oldValue) {
            if ($oldValue === null) {
                unset($this->localVars[$name]);
            } else {
                $this->localVars[$name] = $oldValue;
            }
        }

        $this->tokenizer->setLocalVars($this->localVars);
    }

    /**
     * Index in $lines of the most recently appended line.
     *
     * The accumulator holds every statement emitted so far, so an earlier line
     * is patched in place by index; a branch token has always seen at least the
     * line that produced it.
     */
    private function currentLineIndex(array &$lines): int
    {
        return \count($lines) > 0 ? \array_key_last($lines) : 0;
    }

    private const RE_SET = '/^(.+?)\s*=\s*(.+)$/s';

    /**
     * Compile {% set var = expr %} → PHP assignment.
     */
    private function compileSet(string $rest, string $sourcePath, int $tplLine): string
    {
        // Expect:  lvalue  =  expression
        if (!\preg_match(self::RE_SET, trim($rest), $m)) {
            throw new ClarityException("Malformed set directive: 'set {$rest}'", $sourcePath, $tplLine);
        }

        // Validate the ROOT of the lvalue, not its segments: `items[0].name` is a
        // legitimate target, but its root still has to be bindable.  Without this
        // `{% set this = … %}` reached PHP and died with an uncatchable
        // "Cannot re-assign $this", and `{% set __c_fn = … %}` silently swapped
        // the callable registry for the rest of the render.
        if (!\preg_match('/^\$?([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/', \trim($m[1]), $rootMatch)) {
            throw new ClarityException("Invalid assignment target: '{$m[1]}'", $sourcePath, $tplLine);
        }
        $this->assertBindableName($rootMatch[1], $rootMatch[1], $tplLine);

        $lvalue = $this->tokenizer->processLvalue($m[1]);
        $rvalue = $this->tokenizer->processCondition(trim($m[2]));

        return "{$lvalue} = {$rvalue};";
    }

    private const RE_INCLUDE = '/^["\']([^"\']+)["\']\s*$/';

    /**
     * Compile {% include "name" %} by recursively compiling the included template
     * and writing its output directly into $outLines, preserving source-map
     * accuracy (no double-counting of PHP lines).
     *
     * @param string $rest        Everything after the 'include' keyword.
     * @param string $currentName Logical name of the including template.
     * @param int    $tplLine     Template line of the include directive.
     * @param array  $outLines    Accumulator to write the compiled lines into (mutated).
     */
    private function compileInclude(string $rest, string $currentName, int $tplLine, array &$outLines): string
    {
        if (!\preg_match(self::RE_INCLUDE, trim($rest), $m)) {
            throw new ClarityException("Malformed include directive: 'include {$rest}'", $currentName, $tplLine);
        }

        $includeName = $this->resolveLogicalName($m[1], $currentName);

        if (\in_array($includeName, $this->compileStack, true)) {
            $chain = [...$this->compileStack, $includeName];
            throw new ClarityException(
                'Recursive static include detected: ' . \implode(' -> ', $chain),
                $currentName,
                $tplLine
            );
        }

        $includeSource = $this->readWithDep($includeName);
        $includeSource = $this->resolveExtends($includeSource, $includeName);
        $this->extractMacros($includeSource);

        // Inline directly into the caller's accumulator so PHP line counts remain
        // contiguous and each line is attributed to the correct source file.
        $this->compileSourceInto($includeSource, $includeName, $outLines);
        return '';
    }
}
