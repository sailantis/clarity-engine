<?php

namespace Clarity\Engine\Compiler;

use Clarity\ClarityException;
use Clarity\Template\TemplateLocation;

/**
 * Compile-time validation of PAIRED custom directives.
 *
 * A custom directive is unpaired by default and compiles exactly as before.  An
 * opener that declares member tags ({@see Registry::addDirective()}) turns them
 * into a construct whose structure the compiler can check:
 *
 *   {% cache %}      opener   — pushes the construct
 *     {% cacheelse %} branch   — optional, at most once between open and close
 *   {% endcache %}   close     — pops the construct
 *
 * The checks are the ones a template author actually trips over, and each one
 * would otherwise emit corrupted PHP that is often still syntactically valid —
 * a missing close leaks an output buffer into the next render, a stray close
 * swallows the engine's own buffer:
 *
 *   - a close/branch tag with no construct open
 *   - a close/branch tag whose construct is not the innermost open one
 *   - a construct left open at the end of the render body
 *   - an inner `{% if %}` / `{% for %}` left open across the close (crossed pair)
 *   - a close that crosses an include/macro boundary
 *   - a branch tag appearing more than its declared maximum
 *
 * Crossing detection is depth-based: the opener snapshots the built-in nesting
 * counters ({@see Compiler::$ifDepth}, {@see Compiler::$forStack}, and the
 * compile-unit stack) and the close re-checks them, which distinguishes a legal
 * close from one that leaps over a still-open built-in block WITHOUT touching
 * the built-in for-else machinery.
 *
 * A construct may not span a template unit boundary.  An include is inlined into
 * the same render body, so an opener in the host and its close in the include
 * would otherwise pair silently; the unit depth is snapshotted for that reason.
 */
trait PairedDirectiveTrait
{
    /**
     * Constructs currently open, innermost last.
     *
     * Reset per compilation.  `used`/`max` enforce branch cardinality; the three
     * depth snapshots detect a close that crosses a built-in block or a template
     * unit.
     *
     * @var list<array{
     *   open: string,
     *   close: string,
     *   used: int,
     *   max: int,
     *   tplLine: int,
     *   sourcePath: string,
     *   ifDepth: int,
     *   forDepth: int,
     *   compileDepth: int
     * }>
     */
    private array $directiveStack = [];

    private function resetDirectiveStack(): void
    {
        $this->directiveStack = [];
    }

    /**
     * The expression converter handed to a directive handler.
     *
     * With no second argument it compiles ONE Clarity expression, exactly as it
     * always has.  With `$asList = true` it compiles a whole directive argument
     * list instead — `[name: ] expr [, [name: ] expr ...]` — and returns
     * `[positional, named]`, both lists of PHP expressions keyed by numeric index
     * and by name respectively:
     *
     * ```php
     * $engine->addDirective('cache', function(string $rest, TemplateLocation $at, callable $processExpr): string {
     *     [$positional, $named] = $processExpr($rest, true);
     *     $key  = $positional[0]      ?? null;
     *     $ttl  = $named['ttl']       ?? '300';
     *     $tags = $named['tags']      ?? '[]';
     * });
     * ```
     *
     * The overload is additive: an existing handler that calls `$processExpr($rest)`
     * is unaffected (an extra argument to a closure is ignored anyway).  Both forms
     * run inside {@see withLocation()}, so a failure anywhere in the tokenizer is
     * reported against the template and line that produced the tag.
     */
    private function directiveProcessExpr(string $sourcePath, int $tplLine): callable
    {
        return fn(string $e, bool $asList = false) => $this->withLocation(
            fn() => $asList
                ? $this->tokenizer->processArgumentList($e)
                : $this->tokenizer->processCondition($e),
            $sourcePath,
            $tplLine
        );
    }

    /**
     * Dispatch a registered directive, applying paired-construct validation when
     * the keyword takes part in a construct.
     *
     * The whole dispatch runs inside {@see withLocation()}.  A compiler step has
     * always had that safety net ({@see directiveProcessExpr()}, the loop and `set`
     * headers, …), but the registry dispatch did not: an author's
     * `throw new ClarityException('…')` with no location escaped naming the
     * closure's own file and line — `Handler.php:37` — instead of the template.
     * Wrapping here fixes that for every directive, paired or not, and for the
     * placement errors this trait raises itself.  `withLocation()` fills only the
     * missing fields, so a handler that DOES pass `$path`/`$line` keeps them, and a
     * non-{@see ClarityException} throwable still passes straight through.
     *
     * @param array $lines Accumulator, reserved for parity with compileBlock()'s
     *                     signature (constructs need no line patching).
     */
    private function compileRegistryDirective(
        string $keyword,
        string $rest,
        string $sourcePath,
        int $tplLine,
        array &$lines
    ): string {
        return $this->withLocation(
            fn() => $this->dispatchRegisteredDirective($keyword, $rest, $sourcePath, $tplLine),
            $sourcePath,
            $tplLine
        );
    }

    private function dispatchRegisteredDirective(
        string $keyword,
        string $rest,
        string $sourcePath,
        int $tplLine
    ): string {
        $registry = $this->registry;
        $owner    = $registry->getDirectiveOwner($keyword);

        if ($owner === null) {
            // An ordinary directive, or an opener.  An opener is pushed BEFORE its
            // handler runs, so the handler's own body (rare, but possible) already
            // sees the construct as open.
            if (!$registry->isDirectiveOpener($keyword)) {
                return $this->dispatchDirectiveHandler($keyword, $rest, $sourcePath, $tplLine);
            }

            $this->directiveStack[] = [
                'open'         => $keyword,
                'close'        => $registry->getDirectiveCloseKeyword($keyword) ?? '',
                'used'         => 0,
                'max'          => 1,
                'tplLine'      => $tplLine,
                'sourcePath'   => $sourcePath,
                'ifDepth'      => $this->ifDepth,
                'forDepth'     => \count($this->forStack),
                'compileDepth' => \count($this->compileStack),
            ];

            try {
                return $this->dispatchDirectiveHandler($keyword, $rest, $sourcePath, $tplLine);
            } catch (\Throwable $e) {
                // The construct never opened: leave the stack as the abort found it.
                \array_pop($this->directiveStack);
                throw $e;
            }
        }

        $this->assertDirectiveMemberPlacement($keyword, $owner);

        $index = \array_key_last($this->directiveStack);

        if ($registry->isDirectiveBranch($keyword)) {
            if (++$this->directiveStack[$index]['used'] > $this->directiveStack[$index]['max']) {
                throw new ClarityException(
                    $this->directiveTag($keyword) . ' may only appear once inside '
                        . $this->directiveTag($owner) . $this->openedAt($this->directiveStack[$index])
                        . '; a branch tag takes at most one body.'
                );
            }
        } else {
            \array_pop($this->directiveStack);
        }

        return $this->dispatchDirectiveHandler($keyword, $rest, $sourcePath, $tplLine);
    }

    /**
     * Invoke one registered handler.  Located by the caller
     * ({@see compileRegistryDirective()}), which is what makes an exception raised
     * inside a handler blame the template rather than the closure.
     */
    private function dispatchDirectiveHandler(
        string $keyword,
        string $rest,
        string $sourcePath,
        int $tplLine
    ): string {
        return $this->registry->compileDirective(
            $keyword,
            $rest,
            // The compiler is the only layer that holds the physical path, so it
            // resolves it here instead of leaving the handler to guess it. A
            // handler that throws with this location is complete, and nothing
            // has to re-wrap it afterwards.
            new TemplateLocation($sourcePath, $tplLine, $this->templatePath($sourcePath)),
            $this->directiveProcessExpr($sourcePath, $tplLine)
        );
    }

    /**
     * Fail when a close/branch tag does not belong to the innermost open construct,
     * when an inner built-in block is still open, or when it crosses a unit
     * boundary.
     *
     * Every error here is raised WITHOUT an explicit location, and the dispatch
     * wraps this call in {@see withLocation()}; that is what gives the message the
     * template NAME, the physical PATH (an IDE-openable line) and the line, from
     * one place instead of at each throw.
     */
    private function assertDirectiveMemberPlacement(
        string $keyword,
        string $owner
    ): void {
        $index = \array_key_last($this->directiveStack);
        $top   = $index === null ? null : $this->directiveStack[$index];

        if ($top === null || $top['open'] !== $owner) {
            if ($top === null) {
                throw new ClarityException(
                    'Unexpected ' . $this->directiveTag($keyword) . ': no '
                        . $this->directiveTag($owner) . ' is open.'
                );
            }

            throw new ClarityException(
                $this->directiveTag($keyword)
                    . ($this->registry->isDirectiveClose($keyword) ? ' closes ' : ' belongs to ')
                    . $this->directiveTag($owner) . ', but ' . $this->directiveTag($top['open'])
                    . $this->openedAt($top) . ' is still open. Add '
                    . $this->directiveTag($top['close']) . ' first.'
            );
        }

        if ($top['ifDepth'] !== $this->ifDepth) {
            throw new ClarityException(
                $this->directiveTag($keyword) . ' cannot close ' . $this->directiveTag($owner)
                    . ' while an ' . $this->directiveTag('if') . ' opened inside it is still open; add '
                    . $this->directiveTag('endif') . ' first.'
            );
        }

        if ($top['forDepth'] !== \count($this->forStack)) {
            throw new ClarityException(
                $this->directiveTag($keyword) . ' cannot close ' . $this->directiveTag($owner)
                    . ' while a ' . $this->directiveTag('for') . ' opened inside it is still open; add '
                    . $this->directiveTag('endfor') . ' first.'
            );
        }

        if ($top['compileDepth'] !== \count($this->compileStack)) {
            throw new ClarityException(
                $this->directiveTag($keyword) . ' belongs to a different template than the '
                    . $this->directiveTag($owner) . $this->openedAt($top)
                    . ' it closes; a construct may not span an include or a macro.'
            );
        }
    }

    /**
     * Fail when a built-in branch tag (`{% else %}`, `{% elseif %}`, or a stray
     * `{% endif %}`) is reached with a custom construct open at that depth and
     * nothing for the built-in tag to bind to.
     *
     * Without this guard the built-in tag emits `else:` / `endif;` into a body
     * where no matching `if` was opened, and the author sees a PHP parse error
     * instead of the tag they actually mistyped.  Only fires when the built-in tag
     * would otherwise be unbound, so a legitimate inner `if` or a for-else is
     * untouched.
     */
    private function assertNoCustomConstructOpen(string $keyword, string $sourcePath, int $tplLine): void
    {
        if ($this->directiveStack === []) {
            return;
        }

        $top = $this->directiveStack[\array_key_last($this->directiveStack)];

        if ($top['ifDepth'] !== $this->ifDepth || $top['forDepth'] !== \count($this->forStack)) {
            // The built-in tag binds to a block the construct is nested in.
            return;
        }

        throw new ClarityException(
            $this->directiveTag($keyword) . ' is not valid inside ' . $this->directiveTag($top['open'])
                . $this->openedAt($top) . ': no ' . $this->directiveTag('if') . ' is open. '
                . 'To branch that construct, declare and use one of its branch tags.',
            $sourcePath,
            $tplLine
        );
    }

    /**
     * Fail when the render body ends with a construct still open.
     *
     * Called after the merged body has been compiled, so an opener whose close
     * lives in another template (or nowhere) is reported at the opener's own line.
     */
    private function assertDirectiveStackClosed(): void
    {
        if ($this->directiveStack === []) {
            return;
        }

        $top = $this->directiveStack[\array_key_last($this->directiveStack)];

        throw new ClarityException(
            'Unclosed ' . $this->directiveTag($top['open']) . ' tag'
                . $this->openedAt($top) . ': add ' . $this->directiveTag($top['close']) . '.',
            $top['sourcePath'],
            $top['tplLine'],
            $this->templatePath($top['sourcePath'])
        );
    }

    private function directiveTag(string $keyword): string
    {
        return "'{% " . $keyword . " %}'";
    }

    /**
     * The ` (opened on line N)` suffix, naming the file when it differs from the
     * tag being reported.  Empty for an entry with no recorded line.
     *
     * @param array{tplLine: int, sourcePath: string} $entry
     */
    private function openedAt(array $entry): string
    {
        if ($entry['tplLine'] <= 0) {
            return '';
        }

        return ' (opened on line ' . $entry['tplLine'] . ')';
    }
}
