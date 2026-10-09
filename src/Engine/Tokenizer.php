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
use Clarity\Engine\Tokenizer\PhpConstructTrait;
use Clarity\Engine\Tokenizer\CastTrait;

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
 * TEXT     – raw HTML/text passed through verbatim
 * OUTPUT   – {{ expression }}, rendered (escaped by default)
 * BLOCK    – {% directive %}, control structures and directives
 * COMMENT  – {# comment #}, dropped from the output
 *
 * Expression processing
 * ---------------------
 * The tokenizer converts Clarity expression syntax to valid PHP so the
 * Compiler can embed it directly.  PHP itself validates the resulting
 * syntax when the compiled class file is first loaded, so we intentionally
 * do not perform a full grammar check here.
 *
 * Conversions performed
 * • var-chains (foo.bar[x].baz) → $__c_va['foo']['bar'][$__c_va['x']]['baz']
 * • logical operators:  and → &&,  or → ||,  not → !
 * • bitwise operators:  bor → |,  band → &,  bxor → ^,  bnot → ~,  blsh → <<,  brsh → >>
 * • concat operator:    ~   → .
 * • all other tokens pass through unchanged (PHP validates them)
 *
 * Pipeline (| or |>)
 * • Both | and |> act as the filter pipe operator (| is normalized to |> before processing)
 * • Each step after the pipe is a filter: name  or  name(arg1, arg2)
 * • Arguments are themselves processed as expressions
 * • Result: nested $__c_fn['name']($__c_fn['name']($expr, arg), …)
 *
 * Named arguments
 * • Clarity uses `:` syntax: filter(precision: 2) or fn(from: "system")
 * • These are emitted directly as PHP named arguments: `precision: 2`, `from: 'system'`
 * • PHP validates parameter names and arity at runtime
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
    use PhpConstructTrait;
    use CastTrait;

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
     * A bare root dereference, `$__c_va['name']`. `isset()` on it reports an
     * absent root as false without a warning, which is the tolerance the `?`
     * operator grants.
     *
     * Excludes anything with `->` or a second key: `isset()` would also suppress
     * a missing property or an intermediate missing key, turning a strict
     * segment into a silent null.
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
     * Compile-time local variable context: templateVarName → PHP variable string.
     * Set by the Compiler when entering/leaving loop scopes so that expressions
     * inside loops resolve loop variables to direct PHP local variables instead
     * of $__c_va['name'] lookups.
     *
     * @var array<string, string>
     */
    private array $localVars = [];

    /**
     * Compile-time names bound to a PHP local that is NOT an entry of
     * `$__c_va`: a `{% for %}` variable, or a macro parameter.
     *
     * These are not the keys of {@see $localVars}. A `{% set %}` root is in that
     * map too, but it writes through `$__c_va` (or the local `$a` in open mode,
     * since the render scope is seeded by `extract()`). Its value is therefore
     * already in a scope snapshot. Loop variables and macro parameters are not
     * stored in `$__c_va`, so `vars()` must read them from their locals.
     *
     * @var array<string, true>
     */
    private array $dynamicBindings = [];

    /**
     * PHP's variable-name grammar, byte-wise:
     *
     *     ^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\z
     *
     * This matches the byte set the PHP lexer accepts for variable names, so an
     * accepted name always compiles to a real PHP variable. The `\z` anchor
     * matters: `$` would also match before a trailing newline. The high range
     * allows `$öä` and `$täyte` in UTF-8, because every byte of a multi-byte
     * sequence falls in that range.
     *
     * The range excludes DEL (0x7F), which PHP's documented range also excludes.
     *
     * Byte comparison, not `/u`: PHP compares variable names as bytes and does not
     * validate their encoding. A `\p{L}`-style class would wrongly reject a legal
     * name that is not valid UTF-8.
     */
    private const IDENT_RE = '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\z/';

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
     * Names whose FILTER form is a pass-through debug probe, mapped to the
     * service key that implements it. `{{ x |> dump }}` dumps x and yields x.
     *
     * @var array<string, string>
     */
    private array $filterProbes = [];

    /**
     * What this template is allowed to reach.  Every decision it makes is made
     * while compiling; nothing about it is consulted at render time.
     *
     * Set by the Compiler from the engine's policy.
     */
    private Policy $policy;

    /**
     * Open mode only: the render scope is seeded into PHP LOCALS (the Compiler
     * emits `extract($__c_va, EXTR_SKIP)` at the top of render()), so a chain root
     * is emitted as a plain local variable instead of a `$__c_va` lookup.
     *
     * This is what lets one name work in both worlds — `{{ title }}` and
     * `{% php echo $title; %}` are then the same variable, not two.
     *
     * No guard expression is emitted with the read: an unknown name raises PHP's
     * own "Undefined variable" warning, which the engine's error handler maps to
     * a ClarityException with the template line.  That keeps strict access
     * identical to sandbox mode.
     */
    private bool $localRoots = false;

    /**
     * Stack of lambda parameter frames, innermost last.
     *
     * A lambda is emitted as an arrow function (`fn(…) => …`), so the render
     * scope's locals are not in scope inside it, and a root keeps reading
     * `$__c_va`. A lambda parameter is a real PHP local. Lambdas can nest
     * (`map(rows, r => map(r.vals, v => v ~ r.name))`), so a parameter of an
     * enclosing lambda remains visible to the body being compiled.
     *
     * A root name that matches a parameter of any enclosing frame is emitted as
     * the bare `$name`. PHP binds it lexically through the arrow function that
     * declares it. An empty stack means the expression is not inside a lambda.
     *
     * @var list<array<string, true>>
     */
    private array $lambdaFrames = [];

    /**
     * Function names that stay blocked in PHP mode.  Empty by default (PHP
     * mode is full PHP access); an application may add its own guardrails via
     * the policy's denyFunctions().  Keys are lowercase names.
     *
     * @var array<string, true>
     */
    private array $deniedFunctions = [];

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
     * Declare the names whose FILTER form (`{{ x |> name }}`) is a pass-through
     * debug probe: the piped value is dumped to the debug renderer and then
     * returned unchanged, so a trailing step still sees the original value.
     *
     * The compiler emits `$__c_sv['<service>'](...)` for these instead of
     * dispatching `$__c_fn['<name>']`, which is why the probe survives a
     * user-registered template function of the same name.
     *
     * @param array<string, string> $names name => service key
     */
    public function setFilterProbes(array $names): void
    {
        $this->filterProbes = $names;
    }

    /**
     * PHP's own superglobals, as chain roots.  A read of one of these names emits
     * the PHP variable directly when the `superglobals` rule is granted, so
     * `$_SERVER` means PHP's `$_SERVER` rather than a scope entry that happens to
     * be named that.
     *
     * @var array<string, true>
     */
    private const SUPERGLOBALS = [
        'GLOBALS'  => true,
        '_SERVER'  => true,
        '_GET'     => true,
        '_POST'    => true,
        '_FILES'   => true,
        '_COOKIE'  => true,
        '_SESSION' => true,
        '_REQUEST' => true,
        '_ENV'     => true,
    ];

    /**
     * Starts with {@see Policy::restricted()}. The engine applies its own policy
     * through {@see setPolicy()}.
     */
    public function __construct()
    {
        $this->policy = Policy::restricted();
    }

    /**
     * Split a directive argument list into compiled positional and named arguments.
     *
     * This is the shared parser behind custom-directive handlers that receive a
     * list — see {@see \Clarity\Engine\Compiler\PairedDirectiveTrait::directiveProcessExpr()},
     * where `$processExpr($rest, true)` resolves to this method.  The grammar is
     * the one the filter syntax already uses:
     *
     *     [name: ] expr [, [name: ] expr ...]
     *
     * An argument whose text starts with `name:` is NAMED; anything else is
     * POSITIONAL and keys by its numeric index.  A positional argument may NOT
     * follow a named one, matching filter calls (whose named arguments become PHP
     * named arguments and are therefore order-bound).
     *
     * Both lists hold PHP expressions, compiled through {@see processCondition()},
     * so a caller never re-implements the split or the named-argument rule.
     *
     * @return array{0: list<string>, 1: array<string, string>}
     *         [positional PHP expressions, named PHP expressions]
     * @throws ClarityException On an empty argument, a duplicate or empty-handed
     *                          named argument, or a positional after a named one.
     */
    public function processArgumentList(string $rest): array
    {
        $rest = \trim($rest);
        if ($rest === '') {
            return [[], []];
        }

        $argList = $this->splitRespectingStrings($rest, ',');

        foreach ($argList as $arg) {
            if (\trim($arg) === '') {
                throw new ClarityException('Empty argument in argument list.');
            }
        }

        return $this->compileFilterArguments($argList);
    }

    /**
     * Set the policy that compile-time rule checks consult.
     *
     * The policy's deny-list is copied into a flat map at call time, so the
     * function-call hot path can use a plain array lookup. Later changes to
     * the policy are not seen until setPolicy() is called again.
     */
    public function setPolicy(Policy $policy): void
    {
        $this->policy = $policy;

        $denied = [];
        foreach ($policy->deniedFunctions() as $name) {
            $denied[$name] = true;
        }
        $this->deniedFunctions = $denied;
    }

    public function getPolicy(): Policy
    {
        return $this->policy;
    }

    /**
     * Whether a policy rule is granted. Compile-time checks call this with the
     * rule they guard, rather than inferring it from a single flag.
     */
    private function allows(string $rule): bool
    {
        return $this->policy->allows($rule);
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

    /**
     * Whether a PHP function may be called under the effective policy.
     *
     * Both the policy's function check and the deny-list must allow the call.
     * PHP function names are case-insensitive and may start with a namespace
     * separator, so both are normalised before the deny-list lookup.
     */
    private function isFunctionCallAllowed(string $name): bool
    {
        return $this->policy->allowsFunction($name)
            && !isset($this->deniedFunctions[\strtolower(\ltrim($name, '\\'))]);
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
     * @param array<string, string> $localVars  templateVarName → PHP variable string
     */
    public function setLocalVars(array $localVars): void
    {
        $this->localVars = $localVars;
        // Invalidate the cache: cached chain strings may reference identifiers
        // whose resolution changes when the local-var context changes.
        $this->varChainCache = [];
    }

    /**
     * Update the set of names bound to a PHP local that is not a `$__c_va`
     * entry — loop variables and macro parameters.  See {@see $dynamicBindings}.
     *
     * @param array<string, true> $names
     */
    public function setDynamicBindings(array $names): void
    {
        $this->dynamicBindings = $names;
    }
}
