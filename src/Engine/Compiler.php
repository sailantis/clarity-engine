<?php

namespace Clarity\Engine;

use Clarity\Engine\Compiler\BodyCompilerTrait;
use Clarity\Engine\Compiler\CodeBuilderTrait;
use Clarity\Engine\Compiler\CompilerCoreTrait;
use Clarity\Engine\Compiler\ControlFlowTrait;
use Clarity\Engine\Compiler\DirectiveSupportTrait;
use Clarity\Engine\Compiler\InheritanceTrait;
use Clarity\Engine\Compiler\PairedDirectiveTrait;
use Clarity\Template\TemplateLoader;
use Clarity\Engine\Registry;

/**
 * Compiles a single Clarity template source file into a PHP class.
 *
 * Architecture
 * ------------
 * This class holds the public API, the constants and the per-compilation state;
 * the behaviour is composed from the traits in `Clarity\Engine\Compiler\`
 * (inheritance, control flow, macros, raw-PHP tags, source map, code builder,
 * …).  See CONTRIBUTING.md for the trait map.
 *
 * The compilation pipeline
 * ------------------------
 * 1. Dependency resolution ({% extends %}, {% include %})
 *    - extends/block is resolved statically: the parent layout is merged with
 *      child block overrides before any code is generated.
 *    - include embeds the included file's compiled render body inline.
 * 2. Segmentation via Tokenizer
 * 3. Code generation: each segment is turned into PHP
 * 4. Class wrapping + source-map and dependency metadata
 *
 * Output format
 * -------------
 * Each compiled template becomes exactly one PHP class:
 *
 *   class __Clarity_<slug>_<hash> {
 *       public static array $dependencies = ['name' => revision, ...];
 *       public static string $sourceMap   = 'lineDelta,fileIdx,tplDelta;...';
 *       public function __construct(private array $__c_fn, private array $__c_sv) {}
 *       public function render(array $__c_va): string { ... }
 *   }
 *
 * Every PHP variable the engine binds into the render frame carries the `__c_`
 * prefix ("c" for Clarity).  The prefix IS the reservation rule: an engine
 * internal is covered by choosing to spell it `__c_…`, so a newly added
 * internal cannot silently collide with a template variable whose author never
 * heard of it.  See Compiler::INTERNAL_PREFIX.
 *
 * $dependencies and $sourceMap are read via reflection for cache invalidation
 * and error mapping — no file I/O needed on warm paths (OPcache serves them).
 *
 * The source map is stored in the compact packed form of {@see SourceMap}:
 * as nested var_export() arrays it cost ~2.3x the render body it annotates,
 * while the packed string is ~9% of that.
 *
 * Nothing in the emitted code is a doc comment.  Annotations are written as
 * `//` line comments instead, because OPcache keeps doc comments
 * (opcache.save_comments) but discards line comments: a docblock is retained
 * in shared memory for every cached template, while a line comment costs
 * nothing once the file is cached.  The metadata is reflected, not documented,
 * so the annotation form is free to choose.
 *
 * Buffer safety
 * -------------
 * render() opens one output buffer and must hand back the buffer LEVEL it
 * received.  A bare `ob_end_clean()` in the catch block unwinds only the
 * innermost buffer, so a template that opened one of its own (e.g. a custom
 * directive doing `ob_start()`) and then threw would strand that buffer -- and
 * the partial output inside it -- above the caller's.  The catch therefore
 * drains in a loop down to the level captured immediately AFTER `ob_start()`,
 * which releases clarity's buffer and everything the template stacked on top of
 * it, while never reaching the caller's own buffers.
 *
 * There is deliberately NO finally block.  On the happy path the terminal
 * `return ob_get_clean()` has already closed clarity's buffer, so a finally
 * clause would only ever observe its own start level and unwind nothing; the
 * only finally that could do work is an unconditional unwind, which would
 * discard the caller's buffer when a template illegally closed clarity's.
 */
class Compiler
{
    use CompilerCoreTrait;
    use DirectiveSupportTrait;
    use InheritanceTrait;
    use BodyCompilerTrait;
    use ControlFlowTrait;
    use CodeBuilderTrait;
    use PairedDirectiveTrait;

    /**
     * Bump this whenever a change alters the PHP that a template compiles to.
     *
     * A POLICY change needs no bump: the compiled class carries a digest of the
     * effective policy and the loader recompiles on a mismatch. A change that
     * alters emitted code for EVERY template — removing a cast from a built-in
     * filter, say — is what the version is for, because it is not a policy
     * difference and no digest can express it.
     */
    public const COMPILER_VERSION = 26;

    /**
     * Prefix owned by the engine for every PHP variable it binds into the render
     * frame: `__c_va`, `__c_fn`, `__c_sv`, `__c_tmp`, `__c_val`,
     * `__c_ob_level`, `__c_e`, `__c_m_<macro param>`.
     *
     * This is a PREFIX rule rather than a name list so it stays correct as the
     * engine grows: a new internal is protected by being spelled with this
     * prefix, with no second place to update.  Everything else starting with
     * underscores — `__foo`, `_c_foo`, `___foo` — is an ordinary template
     * variable in both modes.
     */
    public const INTERNAL_PREFIX = '__c_';

    private const SOURCE_MARKER_RE = '/^@source\s+([A-Za-z0-9+\/=]+)\s+(\d+)$/';

    /**
     * Placeholder property emitted by buildClass().  compile() rewrites it with
     * the resolved first line of the compiled render body, expressed in
     * cache-file coordinates (i.e. accounting for the "<?php" line that
     * Cache::writeAndLoad() prepends).  Baking the offset into the class means
     * runtime error mapping needs neither reflection nor file I/O.
     */
    private const BODY_LINE_PROPERTY = 'public static int $renderBodyLine = 0;';

    /**
     * Unique placeholder emitted by buildClass() on the line directly above the
     * first compiled template statement inside render().  It is stripped by
     * compile() once the body offset is known.  The line itself emits no
     * output.
     */
    private const BODY_LINE_TOKEN = '/* @@CLARITY_BODY_LINE@@ */';

    /**
     * `{% parent %}` inlines the parent block's content in a child override.
     *
     * The `@`-prefixed spelling this engine once accepted is gone, and `@` now
     * marks nothing: a stray `{% @parent %}` fails as an unknown directive.
     */
    private const PARENT_PLACEHOLDER_RE = '/\{%-?\s*parent\s*-?%\}/s';

    private Tokenizer $tokenizer;

    /** @var array<string, int|string>  templateName → revision collected during this compilation */
    private array $dependencies = [];

    /** @var list<array{int,int,int}>  phpOutputLine → templateLine source map */
    private array $sourceMap = [];

    /** @var string[]  de-duplicated list of logical template names, in order of first appearance */
    private array $sourceFiles = [];

    /** @var string[]  physical path per $sourceFiles entry ('' when the loader named none), parallel to it */
    private array $sourcePaths = [];

    /** @var array<string,string>  logicalName → physical path, as reported by the loader that served it */
    private array $resolvedPaths = [];

    /** @var array<string,int>  logicalName → index in $sourceFiles */
    private array $sourceFileIndex = [];

    /** Current PHP output line counter (tracks lines emitted to the render body) */
    private int $phpLine = 0;

    /** Active loader for this compilation (set at start of compile()) */
    private ?TemplateLoader $loader = null;

    /**
     * Stack tracking loop types, the if-depth a loop opened at, the generated
     * line holding its header (patched on `{% else %}`), and the compiler-scope
     * variable bindings to restore on endfor.
     * @var list<array{type:string, restore:array<string,string|null>, ifDepth:int, headerLine:int, hasElse:bool}>
     */
    private array $forStack = [];

    /**
     * Nesting depth of the `{% if %}` blocks currently open, so a branch tag can
     * tell whether it belongs to the innermost if or to an open `{% for %}`.
     * @see innermostLoopAtCurrentDepth()
     */
    private int $ifDepth = 0;

    /** Monotonic counter naming the "did the loop iterate" flags of for-else loops. */
    private int $forElseSeq = 0;

    /**
     * Set by compileFor() when a loop header line was just emitted, so the
     * caller can record its index for a possible later `{% else %}` patch.
     */
    private bool $forHeaderPending = false;

    /** Whether to emit debug-only assertions (range checks) in generated code */
    private bool $debugMode = false;

    /**
     * What a compiled template is allowed to reach.  Every rule question
     * the compiler and tokenizer ask is answered from here.
     */
    private Policy $policy;

    /**
     * @var array<string, string>  templateVarName → PHP variable string for locally-bound loop vars.
     * Checked first during expression resolution; falls back to $__c_va[name] when absent.
     * Simple mapping: 'item' → '$item', 'key' → '$key', etc.
     */
    private array $localVars = [];

    /**
     * Names bound to a PHP local that is NOT a `$__c_va` entry — the loop
     * variables and macro parameters that a `vars()` snapshot has to gather
     * explicitly. See {@see Tokenizer::setDynamicBindings()}.
     *
     * @var array<string, true>
     */
    private array $dynamicBindings = [];

    /**
     * Macros defined during the current compile pass (after pre-scan).
     * Static includes can add more macros before the rest of the template is compiled.
     * @var array<string, array{params: list<string>, body: string}>
     */
    private array $macros = [];

    /**
     * Stack of macro names currently being expanded (for cycle detection).
     * @var list<string>
     */
    private array $macroExpansionStack = [];

    /**
     * Current output-escaping context tracked during compilation.
     * Updated automatically by scanning TEXT tokens for <script>/<style> boundaries
     * and by explicit {# @context js #} / {# @context html #} / {# @context css #} hints.
     */
    private string $context = 'html';

    /** @var string[] */
    private array $extendsStack = [];

    /** @var string[] */
    private array $compileStack = [];

    private ?string $mappedSourcePath = null;

    private int $mappedSourceLineBase = 1;

    private int $mappedMergedLineBase = 1;

    /**
     * Raw-PHP tag bodies extracted during the pre-scan of the current template,
     * keyed by the sentinel token that replaced them in the source.
     *
     * `offset` is the number of template lines between the sentinel's own line
     * and the tag's first PHP line (the breaks the tag's opening portion spans).
     * It is what turns a tag into a per-line source map instead of a single range.
     *
     * @var array<string, array{body: string, offset: int}>
     */
    private array $phpBlockBodies = [];

    /**
     * Whether the render body must seed the scope into PHP locals.
     *
     * Decided from the `phpVariables` rule, not per template: includes are
     * inlined into the SAME render body, so a partial containing raw PHP would
     * otherwise be emitted into a body that never seeded the locals it reads.
     * Tying it to the policy removes that failure entirely, and a policy that
     * grants raw PHP normally grants this too, so the seeding is part of the
     * same bargain.
     */
    private bool $seedsScope = false;

    /** Monotonic counter for raw-PHP tag sentinels. */
    private int $phpBlockSeq = 0;

    private ?Registry $registry = null;

    public function __construct()
    {
        $this->tokenizer = new Tokenizer();
    }

    public static function default(): static
    {
        $c = new static();
        $c->setRegistry(new Registry());
        $c->setPolicy(Policy::default());
        return $c;
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function setRegistry(Registry $registry): static
    {
        $this->registry = $registry;
        $this->tokenizer->setRegistry($registry);
        return $this;
    }

    public function setDebugMode(bool $debug): static
    {
        $this->debugMode = $debug;

        // All debug COMPILATION is one setting, because there is one debug mode:
        // dump() is kept and receives the compile-time escape context (with dd()),
        // and `{{ x |> dump }}` becomes a pass-through probe.  With debug off,
        // dump() is pruned to '' and the probe collapses to the identity.
        //
        // Before unification the probe was wired unconditionally, so `|> dump`
        // was emitted even in production; it was harmless only because the prune
        // list happened to cover it.  Both forms now read the same flag.
        $this->tokenizer->setContextInjectedFunctions(['dump' => true, 'dd' => true]);
        $this->tokenizer->setPrunedFunctions($debug ? [] : ['dump' => true]);
        $this->tokenizer->setFilterProbes(['dump' => '__debug_probe']);

        return $this;
    }

    /**
     * Set what compiled templates are allowed to reach.
     *
     * The tokenizer is given the same object rather than a copy of the flag it
     * used to receive, so a rule can never be granted in one half of the
     * compiler and denied in the other.
     */
    public function setPolicy(Policy $policy): static
    {
        $this->policy = $policy;
        $this->tokenizer->setPolicy($policy);
        return $this;
    }

}
