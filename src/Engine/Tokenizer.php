<?php
namespace Clarity\Engine;

use Clarity\Engine\Tokenizer\SegmentScannerTrait;
use Clarity\Engine\Tokenizer\ExpressionCoreTrait;
use Clarity\Engine\Tokenizer\ExpressionSupportTrait;
use Clarity\Engine\Tokenizer\FilterCompilerTrait;
use Clarity\Engine\Tokenizer\CallableTrait;
use Clarity\Engine\Tokenizer\OperatorTestTrait;
use Clarity\Engine\Tokenizer\VarChainTrait;
use Clarity\Engine\Tokenizer\CollectionLiteralTrait;

use Clarity\ClarityException;

/**
 * Splits a Clarity template source into typed segments and processes
 * DSL expressions into PHP-ready strings.
 *
 * Architecture
 * ------------
 * This class holds the public API, the constants and the per-compilation state;
 * the behaviour is composed from the traits in `Clarity\Engine\Tokenizer\`
 * (scanner, expression loop, var-chain parser/emitter, filter & callable
 * compiler, operator tests, …).  See CONTRIBUTING.md for the trait map.
 *
 * Segment types (constants on this class)
 * ----------------------------------------
 * TEXT        â€“ raw HTML/text passed through verbatim
 * OUTPUT_TAG  â€“ {{ expression }} â€“ rendered (auto-escaped by default)
 * BLOCK_TAG   â€“ {% directive %}  â€“ control structures / directives
 *
 * Expression processing
 * ---------------------
 * The tokenizer converts Clarity expression syntax to valid PHP so the
 * Compiler can embed it directly.  PHP itself validates the resulting
 * syntax when the compiled class file is first loaded, so we intentionally
 * do not perform a full grammar check here.
 *
 * Conversions performed
 * â€¢ var-chains (foo.bar[x].baz) â†’ $__c_va['foo']['bar'][$__c_va['x']]['baz']
 * â€¢ logical operators:  and â†’ &&,  or â†’ ||,  not â†’ !
 * â€¢ bitwise operators:  bor â†’ |,  band â†’ &,  bxor â†’ ^,  bnot â†’ ~,  blsh â†’ <<,  brsh â†’ >>
 * â€¢ concat operator:    ~   â†’ .
 * â€¢ all other tokens pass through unchanged (PHP validates them)
 *
 * Pipeline (| or |>)
 * â€¢ Both | and |> act as the filter pipe operator (| is normalized to |> before processing)
 * â€¢ Each step after the pipe is a filter: name  or  name(arg1, arg2)
 * â€¢ Arguments are themselves processed as expressions
 * â€¢ Result: nested $__c_fn['name']($__c_fn['name']($expr, arg), â€¦)
 *
 * Named arguments
 * â€¢ Clarity uses `=` syntax: filter(precision=2) or fn(from="system")
 * â€¢ These are emitted directly as PHP named arguments: `precision: 2`, `from: 'system'`
 * â€¢ PHP itself validates parameter names and arity at runtime â€” no reflection needed
 */
class Tokenizer
{
    use SegmentScannerTrait;
    use ExpressionCoreTrait;
    use ExpressionSupportTrait;
    use FilterCompilerTrait;
    use CallableTrait;
    use OperatorTestTrait;
    use VarChainTrait;
    use CollectionLiteralTrait;

    public const TEXT = 1;
    public const OUTPUT = 2;
    public const BLOCK = 3;
    public const COMMENT = 4;

    public const KEY_TYPE = 0;
    public const KEY_CONTENT = 1;
    public const KEY_LINE = 2;

    private bool $autoEscape = true;

    /** Output-escaping context: 'html' | 'js' | 'css' */
    private string $escapeContext = 'html';

    private ?Registry $registry = null;

    /**
     * A BARE root dereference: `$__c_va['name']`. These are special because
     * isset() on them reports an ABSENT ROOT as false with no warning, which is
     * exactly the tolerance `?` promises.
     *
     * Deliberately excludes anything with `->` or a second key: isset() would
     * suppress a missing PROPERTY or an intermediate missing KEY too, turning a
     * mistyped strict segment into a silent null.
     */
    private const BARE_ROOT_RE = '/^\$__c_va\[\'[A-Za-z_][A-Za-z0-9_]*\'\]$/';

    /**
     * A lambda PARAMETER as it appears in a compiled body: `$name`.
     *
     * Kept separate from {@see BARE_ROOT_RE} because the two mean opposite
     * things — a bare root is a scope read that may be absent, whereas a
     * parameter is a real local that is always bound.
     */
    private const BARE_PARAM_RE = '/^\$[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * Monotonic counter for temporaries emitted by optional array guards, so two
     * guards in one expression never collide. Per-instance, compile-time only.
     */
    private int $guardCounter = 0;
    private array $varChainCache = [];

    /**
     * Compile-time local variable context: templateVarName â†’ PHP variable string.
     * Set by the Compiler when entering/leaving loop scopes so that expressions
     * inside loops resolve loop variables to direct PHP local variables instead
     * of $__c_va['name'] lookups.
     *
     * @var array<string, string>
     */
    private array $localVars = [];
    /**
     * PHP's variable-name grammar, byte-wise:
     *
     *     ^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$
     *
     * This is the grammar from the PHP manual ("the bytes from 128 through
     * 255"), which is exactly what the PHP lexer accepts, so a name this
     * class accepts always compiles to a real PHP variable.  The high range is
     * what makes `$Ã¶Ã¤` / `$tÃ¤yte` work in UTF-8: every byte of a multi-byte
     * sequence falls inside it.
     *
     * Deliberate divergence from Twig: Twig's lexer uses `\x7f-\xff`, i.e. it
     * also accepts DEL (0x7F), which PHP's own documented range excludes.  A
     * DEL in a template identifier is never intentional, so this follows PHP.
     *
     * Byte comparison, not `/u`: PHP compares variable names as BYTES and never
     * validates their encoding, so an invalid-UTF-8 name is a legal variable
     * that a `\p{L}`-style class would wrongly reject.
     */
    private const IDENT_RE = '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/';

    /**
     * Filters whose first argument must be a lambda expression or a filter
     * reference (quoted string). Plain variable references are rejected to
     * prevent callable injection from template variables.
     */
    private const CALLABLE_ARG_FILTERS = ['map' => true, 'filter' => true, 'reduce' => true];

    /**
     * Function names that are compiled to the literal '' (eliminated at compile
     * time).  Used to prune dump() in production mode with zero runtime cost.
     *
     * @var array<string, true>
     */
    private array $prunedFunctions = [];

    /**
     * Function names that receive the current escape context ('html'|'js'|'css')
     * as an extra first string argument in the emitted PHP call.
     *
     * @var array<string, true>
     */
    private array $contextInjectedFunctions = [];

    /**
     * When true (default) templates are sandboxed: PHP function calls and
     * method calls are rejected unless the callee is registered.  When false
     * ("open mode") templates may call arbitrary PHP functions and methods,
     * for parity with Blade / Stempler / Plates.
     *
     * Set by the Compiler from the engine's `sandbox` configuration.
     */
    private bool $sandboxMode = true;

    /**
     * Open mode only: the render scope is seeded into PHP LOCALS (the Compiler
     * emits `extract($__c_va, EXTR_SKIP)` at the top of render()), so a chain root
     * is emitted as a plain local variable instead of a `$__c_va` lookup.
     *
     * This is what lets one name work in both worlds â€” `{{ title }}` and
     * `{% php echo $title; %}` are then the same variable, not two.
     *
     * No guard expression is emitted with the read: an unknown name raises PHP's
     * own "Undefined variable" warning, which the engine's error handler maps to
     * a ClarityException with the template line.  That keeps strict access
     * identical to sandbox mode.
     */
    private bool $localRoots = false;

    /**
     * Stack of lambda PARAMETER frames, innermost LAST.
     *
     * A lambda is emitted as a `static function (...) use (...) { … }`, so the
     * render scope's locals are not in scope inside it: a root must keep reading
     * `$__c_va`. A lambda PARAMETER, however, IS a real local — and because
     * lambdas NEST (`map(rows, r => map(r.vals, v => v ~ r.name))`), a parameter
     * of an enclosing lambda stays visible to the body being compiled.
     *
     * So a root name matching a parameter of ANY enclosing frame is emitted as
     * the bare `$name`: the closure that declares it is the enclosing one, and
     * PHP binds it lexically. An empty stack means "not inside a lambda", which
     * is also what the former `inLambda` boolean expressed.
     *
     * @var list<array<string, true>>
     */
    private array $lambdaFrames = [];

    /**
     * Function names that stay blocked in PHP mode.  Empty by default (PHP
     * mode is full PHP access); an application may add its own guardrails via
     * the engine's setDeniedFunctions().  Keys are lowercase names.
     *
     * @var array<string, true>
     */
    private array $deniedFunctions = Registry::DEFAULT_DENIED_FUNCTIONS;

    /**
     * True while compiling the argument list of a filter routed to a PHP
     * function in open mode, so `_` resolves to the piped value instead of the
     * template variable named `_`.  Scoped with try/finally around the compile
     * of one filter segment.
     */
    private bool $inOpenFilterArgs = false;

    /**
     * The piped value that `_` resolves to while $inOpenFilterArgs is true.
     * Saved and restored around nested open-filter argument compilation so the
     * nearest enclosing filter wins.
     */
    private string $openFilterValue = '';

    /** @param array<string, true> $names */
    public function setPrunedFunctions(array $names): void
    {
        $this->prunedFunctions = $names;
    }

    /** @param array<string, true> $names */
    public function setContextInjectedFunctions(array $names): void
    {
        $this->contextInjectedFunctions = $names;
    }

    /**
     * Enable or disable sandbox mode.  `true` (default) rejects arbitrary PHP
     * function and method calls; `false` allows them (see class docs).
     */
    public function setSandboxMode(bool $sandboxed): void
    {
        $this->sandboxMode = $sandboxed;
    }

    /**
     * Open mode only: emit chain roots as PHP locals (see {@see $localRoots}).
     *
     * The Compiler enables this together with the `extract()` seeding, so a
     * compiler that seeds no locals never emits a local read.
     */
    public function setLocalRoots(bool $enabled): void
    {
        $this->localRoots    = $enabled;
        $this->varChainCache = [];
    }

    public function isSandboxed(): bool
    {
        return $this->sandboxMode;
    }

    /**
     * Replace the open-mode function guardrails.  Keys are lowercase function
     * names; empty (the default) allows every PHP function.
     *
     * @param array<string, true> $names
     */
    public function setDeniedFunctions(array $names): void
    {
        $this->deniedFunctions = $names;
    }

    /**
     * Whether a PHP function may be called in open mode.
     *
     * PHP function names are case-insensitive and may be written with a leading
     * namespace separator, so both are normalised before the deny-list lookup.
     */
    private function isFunctionCallAllowed(string $name): bool
    {
        return !isset($this->deniedFunctions[\strtolower(\ltrim($name, '\\'))]);
    }

    public function setRegistry(Registry $registry): void
    {
        $this->registry = $registry;
    }

    /**
     * Update the compile-time local variable context.
     *
     * Called by the Compiler when entering or exiting a loop scope so that
     * variable resolution inside the loop uses direct PHP local variables
     * (e.g. `$item`) rather than $__c_va['item'] array lookups.
     *
     * @param array<string, string> $localVars  templateVarName â†’ PHP variable string
     */
    public function setLocalVars(array $localVars): void
    {
        $this->localVars = $localVars;
        // Invalidate the cache: cached chain strings may reference identifiers
        // whose resolution changes when the local-var context changes.
        $this->varChainCache = [];
    }
}
