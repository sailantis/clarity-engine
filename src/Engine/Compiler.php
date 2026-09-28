<?php

namespace Clarity\Engine;

use Clarity\ClarityException;
use Clarity\Template\FileLoader;
use Clarity\Template\TemplateLoader;

/**
 * Compiles a single Clarity template source file into a PHP class.
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
    /**
     * Bump this whenever a change alters the PHP that a template compiles to.
     *
     * Version 8: sandbox mode.  Open-mode templates may emit raw PHP calls that
     * sandboxed templates never do, so previously cached classes must be rebuilt.
     * Version 9: raw PHP blocks.  The standalone `{% php <code> %}` spelling and
     * per-line source mapping for every php block both change the emitted body
     * and its map, so version-8 classes must be rebuilt.
     * Version 10: open mode seeds the render scope into PHP locals.  Chain roots
     * are emitted as locals and the body gains an `extract()`, so every
     * open-mode class must be rebuilt.
     * Version 11: engine internals renamed to the `__c_` prefix, and the open-mode
     * `expand` filter reads the local scope instead of the variable array.  Both
     * change the emitted body, so version-10 classes must be rebuilt.
     * Version 12: `${expr}` / `$$name` replace the `expand` filter as a scope
     * read, and filters gained a call form (`round(x, 2)`) derived from the
     * same template as the pipe.  Affects every class.
     * Version 13: `truncate`'s default ellipsis is emitted as the real ellipsis
     * character instead of the literal `\u{2026}` sequence.  Changes the body of
     * any template using `truncate` without an explicit ellipsis.
     * Version 14: `len` aliases `length`, and piping a call-only name
     * (`context`, `include`, `dump`, `dd`) is now a compile error.  Templates
     * that used `|> len` only compiled by accident before; version-13 classes
     * must be rebuilt so the new guard and alias take effect.
     * Version 15: the two runtime registries `$__c_fl` and `$__c_fn` collapse
     * into ONE `$__c_fn` callable table.  Every emitted registry call and the
     * generated class constructor change, so version-14 classes must be rebuilt.
     * Version 16: an inline filter reference (`filter(items, "upper")`) now
     * compiles to a closure for `filter` as well as `map`, and a unary inline
     * reference for `reduce` is rejected.  Changes the emitted body of any
     * template using a callable reference.
     * Version 17: `format` is an alias of `sprintf` again (Twig parity), so
     * `|> format` compiles inline to `\sprintf(...)` instead of a runtime
     * registry lookup.  Version-16 classes must be rebuilt.
     * Version 18: a `{% for %}` may take an `{% else %}` branch (rendered when
     * the sequence is empty).  The loop header of such a loop is patched to
     * record iteration, so the body of any for-else template differs from
     * before -- and version 17 rejected the `{% else %}` outright anyway.
     */
    public const COMPILER_VERSION = 18;

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

    private const PARENT_PLACEHOLDER_RE = '/\{%-?\s*@parent\s*-?%\}/s';

    private Tokenizer $tokenizer;

    /** @var array<string, int|string>  templateName → revision collected during this compilation */
    private array $dependencies = [];

    /** @var list<array{int,int,int}>  phpOutputLine → templateLine source map */
    private array $sourceMap = [];

    /** @var string[]  de-duplicated list of logical template names, in order of first appearance */
    private array $sourceFiles = [];

    /** @var array<string,int>  logicalName → index in $sourceFiles */
    private array $sourceFileIndex = [];

    /** Current PHP output line counter (tracks lines emitted to the render body) */
    private int $phpLine = 0;

    /** View extension (e.g. '.clarity.html') — used only to strip extension from template refs */
    private string $extension = FileLoader::DEFAULT_EXTENSION;

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
     * When true (default) templates are sandboxed; when false ("open mode") the
     * tokenizer permits arbitrary PHP function and method calls.
     */
    private bool $sandboxMode = true;

    /**
     * @var array<string, string>  templateVarName → PHP variable string for locally-bound loop vars.
     * Checked first during expression resolution; falls back to $__c_va[name] when absent.
     * Simple mapping: 'item' → '$item', 'key' → '$key', etc.
     */
    private array $localVars = [];

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
     * Raw-PHP block bodies extracted during the pre-scan of the current
     * template, keyed by the sentinel token that replaced them in the source.
     *
     * `offset` is the number of template lines between the sentinel's own line
     * and the block's first PHP line (the breaks the opening tag spans, plus one
     * when the body starts on the following line).  It is what turns a block into
     * a per-line source map instead of a single range.
     *
     * @var array<string, array{body: string, offset: int}>
     */
    private array $phpBlockBodies = [];

    /**
     * Whether the render body must seed the scope into PHP locals.
     *
     * Always ON in open mode and always OFF while sandboxed, decided from the
     * sandbox flag rather than per template: includes are inlined into the SAME
     * render body, so a partial containing raw PHP would otherwise be emitted
     * into a body that never seeded the locals it reads.  Tying it to the mode
     * removes that failure entirely, and open mode already means "full PHP", so
     * the seeding is part of the same bargain.
     */
    private bool $seedsScope = false;

    /** Monotonic counter for raw-PHP block sentinels. */
    private int $phpBlockSeq = 0;

    private ?Registry $registry = null;

    public function __construct()
    {
        $this->tokenizer = new Tokenizer();
    }

    public function setRegistry(Registry $registry): static
    {
        $this->registry = $registry;
        $this->tokenizer->setRegistry($registry);
        return $this;
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function setExtension(string $extension): static
    {
        $this->extension = $extension[0] === '.' ? $extension : '.' . $extension;
        return $this;
    }

    public function setDebugMode(bool $debug): static
    {
        $this->debugMode = $debug;

        // Production: prune dump() to '' (zero runtime overhead).
        // Debug: inject compile-time context string as first arg to dump() and dd().
        // dd() always gets the context arg regardless of debug mode (always active).
        $this->tokenizer->setPrunedFunctions(
            $debug ? [] : ['dump' => true]
        );
        $this->tokenizer->setContextInjectedFunctions(
            $debug ? ['dump' => true, 'dd' => true] : ['dd' => true]
        );

        return $this;
    }

    /**
     * Enable or disable sandbox mode.  When disabled ("open mode") templates may
     * call arbitrary PHP functions and methods and may embed raw PHP, subject
     * only to the application's own guardrails (empty by default).
     */
    public function setSandboxMode(bool $sandboxed): static
    {
        $this->sandboxMode = $sandboxed;
        $this->tokenizer->setSandboxMode($sandboxed);
        return $this;
    }
    public function isSandboxed(): bool
    {
        return $this->sandboxMode;
    }

    /**
     * Replace the open-mode function guardrails.  Empty by default, because open
     * mode is full PHP access; set names only for application-chosen limits.
     *
     * @param array<string, true> $names Lowercase function names.
     */
    public function setDeniedFunctions(array $names): static
    {
        $this->tokenizer->setDeniedFunctions($names);
        return $this;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Compile a template and return a CompiledTemplate value object.
     *
     * @param string         $templateName Logical template name (e.g. 'home', 'admin::dashboard').
     * @param TemplateLoader $loader       Loader used to fetch source for this template and its
     *                                    dependencies (extends parents, includes).
     * @throws ClarityException On compilation errors.
     */
    public function compile(string $templateName, TemplateLoader $loader): CompiledTemplate
    {
        $this->loader          = $loader;
        $this->dependencies    = [];
        $this->sourceMap       = [];
        $this->sourceFiles     = [];
        $this->sourceFileIndex = [];
        $this->phpLine         = 0;
        $this->forStack        = [];
        $this->ifDepth         = 0;
        $this->forElseSeq      = 0;
        $this->forHeaderPending = false;
        $this->localVars       = [];
        $this->tokenizer->setLocalVars([]);
        $this->macros              = [];
        $this->macroExpansionStack = [];
        $this->context             = 'html';
        $this->tokenizer->setEscapeContext('html');
        $this->extendsStack         = [];
        $this->compileStack         = [];
        $this->mappedSourcePath     = null;
        $this->mappedSourceLineBase = 1;
        $this->mappedMergedLineBase = 1;
        // Raw-PHP sentinels are per-compilation: a body from an earlier template
        // must never be resolvable out of a later one's source.
        $this->phpBlockBodies = [];
        $this->phpBlockSeq    = 0;

        // Open mode seeds the render scope into PHP locals (see $seedsScope).
        // Decided from the MODE, not per template, so an inlined include can
        // never read locals its host body did not seed.  Applied before any
        // expression is compiled, because it changes every chain root.
        $this->seedsScope = !$this->sandboxMode;
        $this->tokenizer->setLocalRoots($this->seedsScope);

        try {
            $source = $this->readWithDep($templateName);
        } catch (\RuntimeException $e) {
            throw new ClarityException($e->getMessage(), $templateName);
        }

        // Resolve extends before anything else
        $source = $this->resolveExtends($source, $templateName);

        // Pre-scan: extract macro definitions and strip them from source.
        $this->extractMacros($source);

        // Unique class name prevents redeclaration collisions in long-running
        // processes (Swoole, RoadRunner, etc.) when a template is recompiled
        // mid-flight. The md5 prefix keeps it identifiable per logical name.
        $className = '__Clarity_' . \md5($templateName) . '_' . \substr(\str_replace('.', '', \uniqid('', true)), -12);

        // Compile the render body
        $body = $this->compileSource($source, $templateName);

        // Build the complete class code (no leading <?php – Cache adds it)
        $code = $this->buildClass($className, $body);

        // Resolve the line at which the compiled render body starts and bake it
        // into the class.  Knowing this offset up-front lets the engine map a
        // runtime error line to a template line with zero file I/O: it only has
        // to read `$className::$renderBodyLine` (a plain static read).
        //
        // The offsets are expressed in *cache-file* coordinates: Cache::writeAndLoad()
        // prepends "<?php\n" to $code, shifting every line by one, and the marker
        // sits directly above the first compiled statement.
        $bodyMarkerLine = $this->findBodyMarkerLine($code);
        $renderBodyLine = $bodyMarkerLine === 0 ? 0 : $bodyMarkerLine + 2;

        $code = \str_replace(
            [
                self::BODY_LINE_TOKEN,
                self::BODY_LINE_PROPERTY,
            ],
            [
                '',
                'public static int $renderBodyLine = ' . $renderBodyLine . ';',
            ],
            $code
        );

        return new CompiledTemplate(
            className: $className,
            code: $code,
            sourceMap: $this->sourceMap,
            dependencies: $this->dependencies,
            sourceFiles: $this->sourceFiles,
            renderBodyLine: $renderBodyLine,
        );
    }

    /**
     * Names a template may never BIND, because PHP itself cannot accept them as
     * an assignment target: `$this` and `$GLOBALS` both raise an UNCATCHABLE
     * fatal ("Cannot re-assign $this" / "Cannot re-assign $GLOBALS"), so they
     * have to be caught while compiling rather than while rendering.
     *
     * This is a syntax-validity list, not a security one.  Every other name —
     * superglobals included — is an ordinary template variable: in open mode
     * `_SERVER` resolves to the real superglobal exactly as raw PHP would, and
     * in sandbox mode it resolves inside `$__c_va` like any other name.
     */
    private const RESERVED_NAMES = ['this', 'GLOBALS'];

    /**
     * Validate a name a template wants to BIND (a `{% for %}` variable, or the
     * root of a `{% set %}` lvalue).
     *
     * Two rules, for two different reasons:
     *  - `__c_…` is the engine's own namespace in the render frame.  Binding one
     *    would clobber an internal for the rest of the render (a filter registry
     *    swapped out at line 3 breaks every filter after it), so it is refused in
     *    BOTH modes.  This is collision avoidance, not a boundary — open mode
     *    reaches the same internals through raw PHP anyway.
     *  - `$this` / `$GLOBALS` are PHP grammar, and fail uncatchably at runtime.
     *
     * Every other name is allowed, including `_SERVER` and the other
     * superglobals, and including ordinary `__foo` / `_c_foo` / `___foo` names.
     *
     * @param string   $name    Identifier, sigil already stripped.
     * @param string   $raw     Original spelling, for the error message.
     * @param int|null $tplLine Directive line, when known.
     */
    private function assertBindableName(string $name, string $raw, ?int $tplLine): void
    {
        // PHP's variable-name grammar, bytes 128-255 included — see
        // Tokenizer::isIdentifier().  Shared with the tokenizer so this method's
        // accepted set can never drift from what the scanner produces, which is
        // the set it will later resolve back out of the local-variable map.
        if (!Tokenizer::isIdentifier($name)) {
            $this->rejectVar("Invalid variable name: '{$raw}'", $tplLine);
        }

        if (\str_starts_with($name, self::INTERNAL_PREFIX)) {
            $this->rejectVar(
                "Variable names starting with '" . self::INTERNAL_PREFIX . "' are reserved for internal use.",
                $tplLine
            );
        }

        if (\in_array($name, self::RESERVED_NAMES, true)) {
            $this->rejectVar("Variable '{$name}' cannot be assigned to in a template.", $tplLine);
        }
    }

    /**
     * Register a local variable in the compile-time context.
     *
     * @param string   $name    The name of the variable to register.
     * @param int|null $tplLine Line of the directive that requested the
     *                          registration, when known.  It is only used to
     *                          point the error at the offending directive
     *                          rather than at a bare variable name.
     */
    public function registerVar(string $name, ?int $tplLine = null)
    {
        // Accept `name` (what the tokenizer passes) as well as `$name`, the
        // spelling a directive author is more likely to use.  Exactly ONE sigil
        // is stripped: `$$x` is not a variable name, and stripping both would
        // silently register `x` — a name the caller never asked for.
        if ($name !== '' && $name[0] === '$') {
            if (($name[1] ?? '') === '$') {
                $this->rejectVar("Invalid variable name: '{$name}'", $tplLine);
            }
            $raw  = $name;
            $name = \substr($name, 1);
        } else {
            $raw = $name;
        }

        $this->assertBindableName($name, $raw, $tplLine);

        $this->localVars[$name] = '$' . $name;
        $this->tokenizer->setLocalVars($this->localVars);
    }

    private function rejectVar(string $message, ?int $tplLine): void
    {
        [$file, $line] = $this->resolveCurrentLocation($tplLine);
        throw new ClarityException($message, $file, $line);
    }

    /**
     * Unregister a local variable from the compile-time context.
     *
     * @param string $name  The name of the variable to unregister.
     */
    public function unregisterVar(string $name)
    {
        unset($this->localVars[$name]);
        $this->tokenizer->setLocalVars($this->localVars);
    }

    /**
     * Get the currently registered local variables.
     *
     * @return array<string, string> Map of local variable names to their PHP representations.
     */
    public function getVars(): array
    {
        return $this->localVars;
    }

    // -------------------------------------------------------------------------
    // Extends / Block resolution (static, at compile-time)
    // -------------------------------------------------------------------------

    /**
     * If the source contains {% extends "…" %}, load the parent, merge blocks,
     * and return the merged source.  Recursive: parent may itself extend.
     *
     * @param string $source       Full source of the child template.
     * @param string $currentName  Logical name of the child template (for error reporting).
     * @return string Merged source ready for compilation.
     */
    private function resolveExtends(string $source, string $currentName): string
    {
        if (\in_array($currentName, $this->extendsStack, true)) {
            $chain = [...$this->extendsStack, $currentName];
            throw new ClarityException(
                'Recursive template inheritance detected: ' . \implode(' -> ', $chain),
                $currentName
            );
        }

        $this->extendsStack[] = $currentName;

        try {
            // Match {% extends "path" %} or {% extends 'path' %}
            if (!\preg_match('/\{%-?\s*extends\s+["\']([^"\']+)["\']\s*-?%\}/s', $source, $m, PREG_OFFSET_CAPTURE)) {
                return $this->annotateSourceRegion($source, $currentName, 1);
            }

            $layoutRef    = $m[1][0];
            $layoutName   = $this->resolveLogicalName($layoutRef, $currentName);
            $extendsStart = $m[0][1];
            $extendsEnd   = $extendsStart + \strlen($m[0][0]);
            $childSource  = \substr($source, 0, $extendsStart) . \substr($source, $extendsEnd);

            $layoutSource = $this->readWithDep($layoutName);

            // Recursively resolve the layout's own extends
            $layoutSource = $this->resolveExtends($layoutSource, $layoutName);

            [$layoutPreamble, $layoutBody, $layoutBodyOffset] = $this->splitLeadingSetPreamble($layoutSource);
            [$childPreamble, $childBody, $childBodyOffset] = $this->splitLeadingSetPreamble($childSource, false);

            // Extract child blocks: {% block name %}...{% endblock %}
            $childBlocks = $this->extractBlocks(
                $childBody,
                $currentName,
                $this->sourceLineAtOffset($childSource, $childBodyOffset)
            );

            // Merge: replace layout's blocks with child definitions while keeping
            // leading set directives available to layout blocks.
            $merged = $layoutPreamble
                . $this->annotateSourceRegion($childPreamble, $currentName, 1)
                . $this->buildResumeMarker($layoutSource, $layoutName, $layoutBodyOffset)
                . $this->mergeBlocks($layoutBody, $childBlocks, $layoutSource, $layoutName, $layoutBodyOffset);

            return $merged;
        } finally {
            \array_pop($this->extendsStack);
        }
    }

    /**
     * Split a template into a leading set preamble and the remaining body.
     *
     * Only leading {% set ... %} directives are preserved across inheritance.
     * Rendered content outside blocks remains unsupported and is left in the
     * body, where it continues to be ignored for child templates.
     *
     * @param bool $preservePadding When true, keep leading whitespace/comments as-is.
     *                              Child templates pass false so ignored content stays ignored.
     * @return array{0: string, 1: string}
     */
    private function splitLeadingSetPreamble(string $source, bool $preservePadding = true): array
    {
        $preamble = '';
        $offset   = 0;
        $length   = \strlen($source);

        while ($offset < $length) {
            if (\preg_match('/\G\s+/As', $source, $m, 0, $offset)) {
                if ($preservePadding) {
                    $preamble .= $m[0];
                }
                $offset += \strlen($m[0]);
                continue;
            }

            if (\preg_match('/\G\{#.*?#\}/As', $source, $m, 0, $offset)) {
                if ($preservePadding) {
                    $preamble .= $m[0];
                }
                $offset += \strlen($m[0]);
                continue;
            }

            if (\preg_match('/\G\{%-?\s*set\b.*?-?%\}/As', $source, $m, 0, $offset)) {
                $preamble .= $m[0];
                $offset += \strlen($m[0]);
                continue;
            }

            break;
        }

        return [$preamble, \substr($source, $offset), $offset];
    }

    /**
     * Extract all {% block name %}...{% endblock %} definitions from source.
     *
     * @return array<string, array{content: string, file: string, line: int}> block-name → source metadata
     */
    private function extractBlocks(string $source, string $sourceName, int $baseLine = 1): array
    {
        $blocks = [];

        // Use a simple iterative approach to handle nested blocks
        $offset = 0;
        while (\preg_match('/\{%-?\s*block\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*-?%\}/s', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $blockName  = $m[1][0];
            $blockStart = $m[0][1]; // position of {% block ... %}
            $innerStart = $blockStart + \strlen($m[0][0]);

            // Find the matching {% endblock %}, accounting for nesting
            $depth   = 1;
            $pos     = $innerStart;
            $content = null;

            while ($depth > 0 && \preg_match('/\{%-?\s*(block\s+[a-zA-Z_][a-zA-Z0-9_]*|endblock)\s*-?%\}/s', $source, $nm, PREG_OFFSET_CAPTURE, $pos)) {
                $tag = \trim($nm[1][0]);
                if (\str_starts_with($tag, 'block')) {
                    $depth++;
                } else {
                    $depth--;
                }
                if ($depth === 0) {
                    $content = \substr($source, $innerStart, $nm[0][1] - $innerStart);
                    $offset  = $nm[0][1] + \strlen($nm[0][0]);
                }
                $pos = $nm[0][1] + \strlen($nm[0][0]);
            }

            if ($content !== null) {
                $blocks[$blockName] = [
                    'content' => $content,
                    'file'    => $sourceName,
                    'line'    => $baseLine + \substr_count(\substr($source, 0, $innerStart), "\n"),
                ];
            }
        }

        return $blocks;
    }

    /**
     * Replace each {% block name %}...{% endblock %} in $layoutSource with
     * the child's definition for that block (if one exists).
     *
     * Uses the same iterative nesting-aware approach as extractBlocks() so
     * that layout blocks which themselves contain inner blocks are matched
     * correctly.  The previous lazy-regex approach stopped at the first
     * {% endblock %} regardless of nesting depth.
     *
     * @param array<string, array{content: string, file: string, line: int}> $childBlocks
     */
    private function mergeBlocks(
        string $layoutBody,
        array $childBlocks,
        string $layoutSource,
        string $layoutName,
        int $layoutBodyOffset
    ): string {
        $result = '';
        $offset = 0;

        while (preg_match('/\{%-?\s*block\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*-?%\}/s', $layoutBody, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $blockName  = $m[1][0];
            $tagStart   = $m[0][1];
            $innerStart = $tagStart + strlen($m[0][0]);

            // Walk forward tracking nesting to find the matching {% endblock %}
            $depth    = 1;
            $pos      = $innerStart;
            $innerEnd = null;
            $fullEnd  = null;

            while ($depth > 0 && preg_match('/\{%-?\s*(block\s+[a-zA-Z_][a-zA-Z0-9_]*|endblock)\s*-?%\}/s', $layoutBody, $nm, PREG_OFFSET_CAPTURE, $pos)) {
                $tag = trim($nm[1][0]);
                if (str_starts_with($tag, 'block')) {
                    $depth++;
                } else {
                    $depth--;
                }
                if ($depth === 0) {
                    $innerEnd = $nm[0][1];
                    $fullEnd  = $nm[0][1] + strlen($nm[0][0]);
                }
                $pos = $nm[0][1] + strlen($nm[0][0]);
            }

            if ($innerEnd === null) {
                // Unclosed block tag – append the rest verbatim and bail
                $result .= substr($layoutBody, $offset);
                return $result;
            }

            // Everything before this block tag is passed through verbatim
            $result .= substr($layoutBody, $offset, $tagStart - $offset);

            $parentContent = substr($layoutBody, $innerStart, $innerEnd - $innerStart);

            // Use child's override if present, otherwise keep the default content.
            // The override is re-wrapped in {% block %}...{% endblock %} so that
            // deeper children in a multi-level extends chain can still override it.
            if (isset($childBlocks[$blockName])) {
                $child    = $childBlocks[$blockName];
                $expanded = $this->expandParentPlaceholders($child, $parentContent);
                $result .= '{% block ' . $blockName . ' %}' . $expanded . '{% endblock %}';
                if ($fullEnd < strlen($layoutBody)) {
                    $result .= $this->buildResumeMarker(
                        $layoutSource,
                        $layoutName,
                        $layoutBodyOffset + $fullEnd
                    );
                }
            } else {
                // Not overridden: keep parent content, but recurse into it so
                // that nested blocks can still be overridden by the child.
                $result .= $this->mergeBlocks(
                    $parentContent,
                    $childBlocks,
                    $layoutSource,
                    $layoutName,
                    $layoutBodyOffset + $innerStart
                );
            }

            $offset = $fullEnd;
        }

        // Append any trailing content after the last block
        $result .= substr($layoutBody, $offset);
        return $result;
    }

    /**
     * Resolve `{% @parent %}` placeholders inside a child block override.
     *
     * Child and parent fragments are emitted with their own source markers so
     * mapped compile errors keep pointing at the correct template and line.
     *
     * @param array{content: string, file: string, line: int} $childBlock
     */
    private function expandParentPlaceholders(array $childBlock, string $parentContent): string
    {
        $childContent = $childBlock['content'];

        if (!\preg_match(self::PARENT_PLACEHOLDER_RE, $childContent)) {
            return $this->annotateSourceRegion($childContent, $childBlock['file'], $childBlock['line']);
        }

        $result = '';
        $offset = 0;

        while (\preg_match(self::PARENT_PLACEHOLDER_RE, $childContent, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $match[0][1];
            $matchEnd   = $matchStart + \strlen($match[0][0]);

            $result .= $this->annotateSourceSlice(
                $childContent,
                $childBlock['file'],
                $childBlock['line'],
                $offset,
                $matchStart
            );
            $result .= $parentContent;
            $offset = $matchEnd;
        }

        $result .= $this->annotateSourceSlice(
            $childContent,
            $childBlock['file'],
            $childBlock['line'],
            $offset,
            \strlen($childContent)
        );

        return $result;
    }

    private function annotateSourceSlice(
        string $source,
        string $sourceName,
        int $baseLine,
        int $startOffset,
        int $endOffset
    ): string {
        if ($endOffset <= $startOffset) {
            return '';
        }

        return $this->annotateSourceRegion(
            \substr($source, $startOffset, $endOffset - $startOffset),
            $sourceName,
            $baseLine + \substr_count(\substr($source, 0, $startOffset), "\n")
        );
    }

    // -------------------------------------------------------------------------
    // Code generation
    // -------------------------------------------------------------------------

    /**
     * Compile a (already-merged) template source to PHP render-body code.
     *
     * @param string $source     Merged template source.
     * @param string $sourcePath Absolute path (for error reporting and source-map file tagging).
     * @return string PHP statements that form the body of render().
     */
    private function compileSource(string $source, string $sourcePath): string
    {
        $lines = [];
        $this->compileSourceInto($source, $sourcePath, $lines);
        return implode("\n", $lines);
    }

    /**
     * Compile a template source into the provided $lines accumulator, updating
     * the shared $phpLine counter and $sourceMap in-place.
     *
     * Includes are inlined directly here (rather than returning a string) to
     * avoid double-counting PHP lines in the source map.
     *
     * @param string $source     Template source (already merged with extends/blocks).
     * @param string $sourcePath Absolute path of the template being compiled.
     * @param array  $lines      Accumulator for generated PHP code lines (mutated).
     */
    private function compileSourceInto(string $source, string $sourcePath, array &$lines): void
    {
        if (\in_array($sourcePath, $this->compileStack, true)) {
            $chain = [...$this->compileStack, $sourcePath];
            throw new ClarityException(
                'Recursive static include detected: ' . \implode(' -> ', $chain),
                $sourcePath
            );
        }

        $this->compileStack[] = $sourcePath;
        $this->extractPhpBlocks($source);
        $segments = $this->tokenizer->tokenize($source);

        // Type of the segment immediately PRECEDING the current one, in source
        // order. Only the TEXT case reads it, to apply the post-tag rule below.
        $prevType = null;

        try {
            foreach ($segments as $seg) {
                $tplLine = $seg[Tokenizer::KEY_LINE];
                [$mappedSourcePath, $mappedTplLine] = $this->resolveSegmentSource($sourcePath, $tplLine);

                switch ($seg[Tokenizer::KEY_TYPE]) {
                    case Tokenizer::TEXT:
                        $rawText = $seg[Tokenizer::KEY_CONTENT];
                        if ($rawText === '') {
                            break;
                        }
                        // Update escaping context based on <script>/<style> boundaries.
                        // (Uses the RAW text: this is a context question, not an
                        // output question, so the post-tag rule must not affect it.)
                        $this->updateContextFromText($rawText);

                        // A text segment that directly follows a {% … %} or {# … #}
                        // tag loses ONE leading line break — see the method docblock
                        // for why, and for why {{ … }} is deliberately excluded.
                        $text = $rawText;
                        if ($prevType === Tokenizer::BLOCK || $prevType === Tokenizer::COMMENT) {
                            $text = self::stripOneLineBreakAfterTag($text);
                        }
                        if ($text === '') {
                            break;
                        }
                        $this->addPhpLines(
                            $lines,
                            $this->textToPhp($text),
                            $mappedTplLine,
                            $mappedSourcePath
                        );
                        break;

                    case Tokenizer::COMMENT:
                        $this->processComment($seg[Tokenizer::KEY_CONTENT], $tplLine);
                        break;

                    case Tokenizer::OUTPUT:
                        $phpExpr = $this->tokenizer->processExpression(
                            $seg[Tokenizer::KEY_CONTENT]
                        );
                        $this->addPhpLines(
                            $lines,
                            "echo {$phpExpr};",
                            $mappedTplLine,
                            $mappedSourcePath
                        );
                        break;

                    case Tokenizer::BLOCK:
                        $blockContent = $seg[Tokenizer::KEY_CONTENT];
                        if (isset($this->phpBlockBodies[$blockContent])) {
                            $phpBlock = $this->phpBlockBodies[$blockContent];
                            $this->addPhpBlockLines(
                                $lines,
                                $phpBlock['body'],
                                $mappedTplLine + $phpBlock['offset'],
                                $mappedSourcePath
                            );
                            break;
                        }
                        $compiled = $this->compileBlock(
                            $blockContent,
                            $mappedSourcePath,
                            $mappedTplLine,
                            $lines
                        );
                        if ($compiled !== '') {
                            $this->addPhpLines(
                                $lines,
                                $compiled,
                                $mappedTplLine,
                                $mappedSourcePath
                            );
                        }
                        // A `{% for %}` header is the last line just emitted.  Its index
                        // is kept so that a later `{% else %}` can patch in the flag name
                        // that marks iteration; see compileElse().
                        if ($this->forHeaderPending) {
                            $this->forHeaderPending = false;
                            $top = \count($this->forStack) - 1;
                            if ($top >= 0 && $this->forStack[$top]['headerLine'] === -1) {
                                $this->forStack[$top]['headerLine'] = $this->currentLineIndex($lines);
                            }
                        }
                        break;
                }

                $prevType = $seg[Tokenizer::KEY_TYPE];
            }
        } finally {
            \array_pop($this->compileStack);
        }
    }

    private function processComment(string $content, int $tplLine): void
    {
        $inner = trim($content);
        if (preg_match(self::SOURCE_MARKER_RE, $inner, $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false && $decoded !== '') {
                $this->mappedSourcePath     = $decoded;
                $this->mappedSourceLineBase = (int) $m[2];
                $this->mappedMergedLineBase = $tplLine;
            }
            return;
        }

        if (str_starts_with($inner, '@context ')) {
            // Handle {# @context <name> #} hints.
            static $validContexts = [
                'html' => true,
                'js'   => true,
                'css'  => true
            ];
            $ctx = strtolower(trim(substr($inner, 9)));
            if (isset($validContexts[$ctx])) {
                $this->context = $ctx;
                $this->tokenizer->setEscapeContext($ctx);
            }
        }
    }

    /**
     * Compile a single {% … %} directive to PHP.
     *
     * @param string $content    Inner text of the {% … %} tag (trimmed).
     * @param string $sourcePath Source file path for error messages.
     * @param int    $tplLine    Template line number for error messages.
     * @param array  $lines      Accumulator for generated PHP code lines (mutated).
     */
    private function compileBlock(
        string $content,
        string $sourcePath,
        int $tplLine,
        array &$lines
    ): string {
        if (\preg_match('/^@parent\s*$/i', $content)) {
            throw new ClarityException(
                "'{% @parent %}' is only valid inside an overriding child block.",
                $sourcePath,
                $tplLine
            );
        }

        // Macro call: {% @name(arg1, arg2) %}
        if ($content !== '' && $content[0] === '@') {
            if (!\preg_match('/^@([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)\s*$/s', $content, $mc)) {
                throw new ClarityException("Invalid macro call syntax: '{$content}'", $sourcePath, $tplLine);
            }
            $this->compileMacroCall($mc[1], $mc[2], $sourcePath, $tplLine, $lines);
            return '';
        }

        // Split on first whitespace to get the keyword
        $parts = \preg_split(
            '/\s+/',
            $content,
            2
        );
        $keyword = \strtolower($parts[0]);
        $rest    = $parts[1] ?? '';

        return match ($keyword) {
            'if'     => $this->compileIf($rest, $sourcePath, $tplLine),
            'elseif' => $this->compileElseIf($rest, $sourcePath, $tplLine),
            'else'   => $this->compileElse($sourcePath, $tplLine, $lines),
            'endif'  => $this->compileEndIf(),
            'endfor' => $this->compileEndFor($sourcePath, $tplLine),
            'for'    => $this->compileFor($rest, $sourcePath, $tplLine),
            'set'    => $this->compileSet($rest, $sourcePath, $tplLine),
            // extends/block/endblock/include are handled before this stage; if seen here → ignore
            'extends', 'block', 'endblock' => '',
            'include'                      => $this->compileInclude($rest, $sourcePath, $tplLine, $lines),
            // A `php` keyword that reaches code generation was NOT extracted by
            // extractPhpBlocks(): the raw form was written but never closed.  A
            // complete form never gets here -- extraction handles it, and rejects
            // it outright while the sandbox is enabled.
            'php'                          => throw new ClarityException(
                $this->sandboxMode
                    ? "'{% php %}' is not allowed in sandbox mode. "
                        . "Call setSandboxMode(false) to allow raw PHP."
                    : "Unclosed '{% php %}': expected a matching '{% endphp %}'.",
                $sourcePath,
                $tplLine
            ),
            'endphp'                       => throw new ClarityException(
                "Unexpected '{% endphp %}': no matching '{% php %}' block.",
                $sourcePath,
                $tplLine
            ),
            default                        => $this->registry->hasDirective($keyword)
            ? $this->registry->compileDirective(
                $keyword,
                $rest,
                $sourcePath,
                $tplLine,
                fn(string $e) => $this->tokenizer->processCondition($e),
                $this
            )
            : throw new ClarityException(
                "Unknown directive '{$keyword}'",
                $sourcePath,
                $tplLine
            ),
        };
    }

    // -------------------------------------------------------------------------
    // Macros
    // -------------------------------------------------------------------------

    /**
     * Scan $source for {% macro @name(params) %}...{% endmacro %} definitions,
     * store them in $this->macros, and strip the definitions from the source.
     */
    private function extractMacros(string &$source): void
    {
        $pattern = '/\{%-?\s*macro\s+@([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^)]*)\)\s*-?%\}(.*?)\{%-?\s*endmacro\s*-?%\}/s';
        $source  = (string) \preg_replace_callback($pattern, function (array $m): string {
            $name   = $m[1];
            $params = $m[2] !== '' ? \array_map('trim', \explode(',', $m[2])) : [];
            $this->macros[$name] = ['params' => $params, 'body' => $m[3]];
            return '';
        }, $source);
    }

    /**
     * Extract `{% php %}…{% endphp %}` regions from the source, replacing each
     * with a line-preserving sentinel tag that {@see compileSourceInto()} later
     * turns back into raw PHP.
     *
     * Two interchangeable spellings are accepted:
     *
     *   block form       {% php %} ... {% endphp %}
     *   standalone form  {% php <code> %}
     *
     * The standalone form carries one statement -- or one fragment of a control
     * structure -- per tag, which is what lets PHP structure wrap template
     * markup without a single block spanning it:
     *
     *   {% php if ($items) : %}
     *   ... markup ...
     *   {% php endif %}
     *
     * Both are OPEN-MODE features and are rejected while the sandbox is enabled.
     *
     * The body is held aside rather than tokenized, for two reasons:
     *  - It must reach the compiled class VERBATIM: only the surrounding `{% %}`
     *    trivia is stripped, the body's own whitespace is kept.
     *  - Its interior may be text the template tokenizer would mis-scan (an
     *    unbalanced `{`, a `?>` tag, a template fragment), so the sentinel keeps
     *    only the region's LINE COUNT, which preserves the mapping of every
     *    following segment.
     *
     * LIMITATION: the closing delimiter is found by a plain non-greedy match, so
     * a literal `%}` inside the body ends the region early -- spell it `'%' . '}'`
     * when that exact sequence is needed.  (The block form has the same rule
     * about a literal `endphp %}`.)
     *
     * @param string $source Merged template source (mutated in place).
     */
    private function extractPhpBlocks(string &$source): void
    {
        if (!\str_contains($source, '{%')) {
            return;
        }

        // Block form first: its opener is indistinguishable from an empty
        // standalone tag, so consuming it here keeps the two passes from ever
        // competing for the same text.
        $source = (string) \preg_replace_callback(
            '/(\{%-?\s*php\s*-?%\})(.*?)(\{%-?\s*endphp\s*-?%\})/is',
            fn(array $m): string =>
                $this->storePhpBlock($m[1], $m[2], \substr_count($m[0], "\n")),
            $source
        );

        // Standalone form.  The body must begin with a non-whitespace character,
        // which is exactly what keeps an empty `{% php %}` opener out.
        $source = (string) \preg_replace_callback(
            '/(\{%-?\s*php\s+)(.+?)(-?%\})/is',
            function (array $m): string {
                if (\trim($m[2]) === '') {
                    return $m[0];
                }
                return $this->storePhpBlock($m[1], $m[2], \substr_count($m[0], "\n"));
            },
            $source
        );
    }

    /**
     * Register one raw-PHP body and return the sentinel tag that replaces it.
     *
     * @param string $openTag     The region's opening tag, verbatim.
     * @param string $bodyRaw     Body exactly as written between the delimiters.
     * @param int    $regionLines Line breaks the whole region spans; the sentinel
     *                            carries them so following segments stay aligned.
     */
    private function storePhpBlock(string $openTag, string $bodyRaw, int $regionLines): string
    {
        if ($this->sandboxMode) {
            throw new ClarityException(
                "'{% php %}' is not allowed in sandbox mode. "
                    . "Call setSandboxMode(false) to allow raw PHP."
            );
        }

        // Template line of the body's FIRST line, relative to the region's line:
        // the breaks the opening tag itself spans, plus one when the layout put
        // the body on the following line.
        $offset = \substr_count($openTag, "\n");

        $body = $bodyRaw;
        if (\preg_match('/^\r?\n/', $body) === 1) {
            // ONE leading break is layout, not code: strip it, but count it, so
            // the mapping stays exact and the body's own indentation survives.
            $body = (string) \preg_replace('/^\r?\n/', '', $body, 1);
            $offset++;
        }
        $body = \rtrim($body);

        // Give a complete statement its terminator, but never a fragment that
        // ends INSIDE a control structure: `if ($x) :`, `else:`, `{` and `}` are
        // decided by the author's last character (`endif` still needs its `;`).
        // Appending after `:` or `{` would be legal but would also mangle the
        // text a reader sees in the compiled class.
        if ($body !== '' && !\str_contains(';}{:', $body[-1])) {
            $body .= ';';
        }

        $token = '@@CLARITY_PHP_' . (++$this->phpBlockSeq) . '@@';
        $this->phpBlockBodies[$token] = ['body' => $body, 'offset' => $offset];

        // Keep it a BLOCK tag so the tokenizer routes it to the block arm.  The
        // region's newlines go INSIDE the tag (not after it): that preserves
        // every following segment's original line number for the source map
        // without turning the padding into output text.
        return '{%' . $token . \str_repeat("\n", $regionLines) . '%}';
    }

    /**
     * Inline a macro call into the current output.
     * Params become PHP locals ($__c_m_paramName) scoped to the macro body.
     */
    private function compileMacroCall(
        string $name,
        string $argsRaw,
        string $sourcePath,
        int $tplLine,
        array &$lines
    ): void {
        if (!isset($this->macros[$name])) {
            throw new ClarityException("Call to undefined macro '@{$name}'", $sourcePath, $tplLine);
        }

        // Cycle detection: if this macro is already on the expansion stack, we have a cycle.
        if (\in_array($name, $this->macroExpansionStack, true)) {
            $cycle = [...$this->macroExpansionStack, $name];
            throw new ClarityException(
                'Macro cycle detected: @' . \implode(' → @', $cycle),
                $sourcePath,
                $tplLine
            );
        }

        $macro  = $this->macros[$name];
        $params = $macro['params'];
        $args   = $argsRaw !== '' ? $this->splitArgList($argsRaw) : [];

        if (\count($args) !== \count($params)) {
            throw new ClarityException(
                "Macro '@{$name}' expects " . \count($params) . " argument(s), got " . \count($args),
                $sourcePath,
                $tplLine
            );
        }

        // Assign each argument to a unique PHP local; save compile-scope for restore.
        $restore = [];
        foreach ($params as $idx => $param) {
            $phpVar  = '$__c_m_' . $param;
            $phpExpr = $this->tokenizer->processCondition(\trim($args[$idx]));
            $this->addPhpLines($lines, $phpVar . ' = ' . $phpExpr . ';', $tplLine, $sourcePath);
            $restore[$param] = $this->localVars[$param] ?? null;
            $this->localVars[$param] = $phpVar;
        }
        $this->tokenizer->setLocalVars($this->localVars);

        // Push to expansion stack, compile the macro body inline, then pop.
        $this->macroExpansionStack[] = $name;
        try {
            $this->compileSourceInto($macro['body'], $sourcePath . '#macro@' . $name, $lines);
        } finally {
            \array_pop($this->macroExpansionStack);
        }

        // Restore compile-scope.
        foreach ($restore as $param => $old) {
            if ($old === null) {
                unset($this->localVars[$param]);
            } else {
                $this->localVars[$param] = $old;
            }
        }
        $this->tokenizer->setLocalVars($this->localVars);
    }

    /**
     * Split a comma-separated argument list, respecting nested parentheses and quoted strings.
     *
     * @return list<string>
     */
    private function splitArgList(string $input): array
    {
        $parts    = [];
        $depth    = 0;
        $start    = 0;
        $len      = \strlen($input);
        $inSingle = false;
        $inDouble = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $input[$i];
            if (($inSingle || $inDouble) && $ch === '\\' && $i + 1 < $len) {
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
            if (!$inSingle && !$inDouble) {
                if ($ch === '(' || $ch === '[') {
                    $depth++;
                } elseif ($ch === ')' || $ch === ']') {
                    $depth--;
                } elseif ($ch === ',' && $depth === 0) {
                    $parts[] = \substr($input, $start, $i - $start);
                    $start = $i + 1;
                }
            }
        }
        $parts[] = \substr($input, $start);
        return $parts;
    }

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
                'type'      => 'for',
                'restore'   => $restore,
                'ifDepth'   => $this->ifDepth,
                'headerLine' => -1,
                'hasElse'   => false,
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
            'type'      => 'foreach',
            'restore'   => $restore,
            'ifDepth'   => $this->ifDepth,
            'headerLine' => -1,
            'hasElse'   => false,
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
     * Compile {% endfor %} → the correct PHP closing keyword based on the
     * matching opening loop (native `for` vs `foreach`).
     */
    /**
     * Scan a TEXT segment for <script>/<style> open/close tags and update $this->context
     * to reflect the escaping context that applies AFTER this text block.
     * Uses the last boundary found so that a segment containing both open and close
     * (e.g. an inline <script>…</script>) correctly ends back in 'html'.
     */
    private function updateContextFromText(string $text): void
    {
        $lastPos = -1;
        $newCtx  = null;

        // Only consider fully-formed opening tags (including the closing '>')
        // as boundaries. This avoids switching the escape context to 'js' or
        // 'css' while still inside a start-tag's attributes (which remain
        // HTML context). Partial start-tags (no closing '>') may contain
        // attribute interpolations and must be treated as HTML.
        $boundaries = [
            '/<script\b[^>]*>/i' => 'js',
            '/<\/script>/i'      => 'html',
            '/<style\b[^>]*>/i'  => 'css',
            '/<\/style>/i'       => 'html',
        ];

        foreach ($boundaries as $pattern => $ctx) {
            if (\preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                $last = \end($m[0]);
                if ($last[1] > $lastPos) {
                    $lastPos = $last[1];
                    $newCtx  = $ctx;
                }
            }
        }

        if ($newCtx !== null && $newCtx !== $this->context) {
            $this->context = $newCtx;
            $this->tokenizer->setEscapeContext($newCtx);
        }
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

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a raw TEXT segment to a PHP echo statement that preserves
     * the content verbatim.
     *
     * Produces a **single-line** PHP double-quoted string literal by escaping
     * all control characters (including newlines), backslashes, double-quotes,
     * and dollar signs via addcslashes().  This avoids three problems the
     * previous nowdoc approach had:
     *
     *  1. PHP 7.3+ indented nowdoc: the 8-space class-body indentation added
     *     by buildClass() was silently stripped from the start of every content
     *     line, mangling template text that relied on leading spaces.
     *  2. Marker escape: a time-derived uniqid() marker could theoretically
     *     collide with content the template author controls.
     *  3. Per-segment uniqid() syscall overhead.
     *
     * addcslashes() escapes:
     *   \x00–\x1F  control chars (incl. \n → \n, \r → \r, \t → \t, others → octal)
     *   \x7F       DEL
     *   \          → \\
     *   "          → \"
     *   $          → \$  (prevents PHP variable interpolation)
     */
    private function textToPhp(string $text): string
    {
        return 'echo "' . addcslashes($text, "\0..\37\177\\\"$") . '";';
    }

    /**
     * Remove ONE line break from the start of a text segment that directly
     * follows a `{% … %}` or `{# … #}` tag.
     *
     * WHY THIS EXISTS: a directive alone on its own line used to leave a blank
     * line in the output. Compiling
     *
     *     X
     *     {% for j in 0..2 %}
     *       <s>{{ j }}</s>
     *     {% endfor %}
     *     Y
     *
     * emitted `X\n` + `\n  <s>` per iteration; the tag's own trailing newline
     * doubled up with the next iteration's leading newline into a
     * whitespace-only line. On the competition's 200-item benchmark page that
     * was 2403 blank lines — 44% of the output's 5418 lines — for a page whose
     * visible content is the leanest of the six engines compared.
     *
     * WHY A LINE BREAK AND NOT A DEFAULT: Twig and Stempler both get this for
     * free and neither uses a whitespace-control operator to do it. Twig's lexer
     * ends its block-tag pattern with `%}\n?` and its comment pattern with
     * `#}\n?` — unconditional, exactly ONE newline. Stempler inherits the same
     * effect from PHP itself, whose `?>` consumes one following line break
     * (`?>` is compiled by PHP's lexer as `?>` followed by an optional line
     * break). Both were measured, not assumed: PHP eats a single `\n`/`\r\n`
     * after `?>`, leaves a second newline, and is BLOCKED by a space first, so
     * `" \n"` is untouched. This mirrors that rule so a Clarity template needs
     * no edits and no operator to match the ecosystem's expectation.
     *
     * WHY `{{ … }}` IS EXCLUDED: neither `?>`'s rule nor Twig's applies after an
     * output tag (`}}` has no `\n?` in Twig's lexer, and Stempler emits a call
     * there rather than a tag boundary). Applying it to prints would silently
     * delete line breaks the other engines keep, trading one divergence for
     * another.
     *
     * WHAT IT DOES NOT DO: it does not trim spaces or tabs, so indentation before
     * a `{%` is preserved and an author can keep the line break by putting a
     * space in front of it ("\n" is eaten, " \n" is not) — the same escape hatch
     * PHP and Twig provide. It also only ever removes one break, so a deliberate
     * blank line survives as one newline.
     *
     * This changes the PHP that templates compile to, so COMPILER_VERSION is
     * bumped and Cache::isFresh() recompiles every existing template.
     */
    /**
     * Is a compiled range bound a literal number, and therefore safe to inline
     * into the loop header?
     *
     * The bound has already been through the tokenizer, so a numeric template
     * literal arrives as plain digits (`10`), a negative literal as `-10`, and a
     * float as `2.5`. Anything containing an operator, a variable, a call or a
     * string is rejected, because re-emitting such an expression in the loop
     * header would evaluate it on every iteration instead of once.
     *
     * @param string $expr Compiled PHP expression for the bound.
     */
    private static function isNumericLiteral(string $expr): bool
    {
        return (bool) \preg_match('/^-?\d+(?:\.\d+)?$/', $expr);
    }

    private static function stripOneLineBreakAfterTag(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        // CRLF as one break, so a Windows template behaves like a Unix one and
        // never leaves a stray "\n" behind (which would be the blank line back).
        if (\str_starts_with($text, "\r\n")) {
            return \substr($text, 2);
        }
        if ($text[0] === "\n" || $text[0] === "\r") {
            return \substr($text, 1);
        }
        return $text;
    }

    /**
     * Append PHP code line(s) to the output and update the source map.
     *
     * The source map stores compact ranges: a new entry is only appended when
     * the (file, templateLine) pair changes from the previous entry, so a range
     * implicitly covers all PHP lines up to the start of the next entry.
     *
     * @param array  $lines   The accumulated render-body lines (mutated).
     * @param string $php     The PHP code to append (may contain newlines).
     * @param int    $tplLine The corresponding template source line.
     * @param string $file    Absolute path of the source template file.
     */
    private function addPhpLines(array &$lines, string $php, int $tplLine, string $file): void
    {
        if (!isset($this->sourceFileIndex[$file])) {
            $this->sourceFileIndex[$file] = \count($this->sourceFiles);
            $this->sourceFiles[]          = $file;
        }
        $fileIdx = $this->sourceFileIndex[$file];

        foreach (explode("\n", $php) as $codeLine) {
            $this->phpLine++;
            // Emit a new range only when (fileIndex, tplLine) changes from the last entry.
            $last = end($this->sourceMap);
            if ($last === false || $last[1] !== $fileIdx || $last[2] !== $tplLine) {
                $this->sourceMap[] = [$this->phpLine, $fileIdx, $tplLine];
            }
            $lines[] = $codeLine;
        }
    }

    /**
     * Append a raw-PHP block, mapping each of its lines to its OWN template line.
     *
     * A raw block is the one construct whose compiled lines and template lines
     * run in lockstep, so an exact one-to-one mapping is both possible and worth
     * the extra source-map ranges: a runtime error inside the block then points
     * at the offending template line instead of at the block's opening tag.
     *
     * @param array  $lines        The accumulated render-body lines (mutated).
     * @param string $php          Verbatim PHP body.
     * @param int    $startTplLine Template line of the body's first line.
     * @param string $file         Absolute path of the owning template.
     */
    private function addPhpBlockLines(array &$lines, string $php, int $startTplLine, string $file): void
    {
        foreach (\explode("\n", $php) as $index => $codeLine) {
            $this->addPhpLines($lines, $codeLine, $startTplLine + $index, $file);
        }
    }

    private function annotateSourceRegion(string $source, string $sourceName, int $startLine): string
    {
        if ($source === '') {
            return '';
        }

        return $this->buildSourceMarker($sourceName, $startLine) . $source;
    }

    private function buildResumeMarker(string $source, string $fallbackSourceName, int $offset): string
    {
        [$sourceName, $sourceLine] = $this->resolveSourceOriginAtOffset($source, $fallbackSourceName, $offset);
        return $this->buildSourceMarker($sourceName, $sourceLine);
    }

    private function buildSourceMarker(string $sourceName, int $startLine): string
    {
        return '{# @source ' . base64_encode($sourceName) . ' ' . $startLine . ' #}';
    }

    private function resolveSourceOriginAtOffset(string $source, string $fallbackSourceName, int $offset): array
    {
        $offset     = max(0, min($offset, strlen($source)));
        $mergedLine = $this->sourceLineAtOffset($source, $offset);

        $activeSourceName = $fallbackSourceName;
        $activeSourceLine = 1;
        $activeMergedLine = 1;

        if (preg_match_all('/\{#\s*@source\s+([A-Za-z0-9+\/=]+)\s+(\d+)\s*#\}/', $source, $matches, PREG_OFFSET_CAPTURE)) {
            $matchCount = count($matches[0]);
            for ($index = 0; $index < $matchCount; $index++) {
                $matchOffset = $matches[0][$index][1];
                if ($matchOffset > $offset) {
                    break;
                }

                $decoded = base64_decode($matches[1][$index][0], true);
                if ($decoded === false || $decoded === '') {
                    continue;
                }

                $activeSourceName = $decoded;
                $activeSourceLine = (int) $matches[2][$index][0];
                $activeMergedLine = $this->sourceLineAtOffset($source, $matchOffset);
            }
        }

        return [$activeSourceName, $activeSourceLine + max(0, $mergedLine - $activeMergedLine)];
    }

    /**
     * Resolve the logical template name and 1-based line a compile error should
     * point at, for the compilation unit currently being processed.
     *
     * The current unit is the top of $compileStack: the root template, an
     * included template, or `<owner>#macro@<name>` for a macro body.
     *
     * The mapping cursor ($mappedSourcePath and its companions) only describes
     * the merged top-level source.  A macro body is compiled as its own unit and
     * its line numbers are relative to the macro definition, so there the cursor
     * would name an unrelated file and line: fall back to the macro's own logical
     * name and the unit-relative line instead.
     *
     * @return array{0: string, 1: int}
     */
    private function resolveCurrentLocation(?int $tplLine): array
    {
        $sourceName = $this->compileStack === [] ? '' : \end($this->compileStack);
        $macroAt    = \strpos($sourceName, '#macro@');

        if ($macroAt !== false) {
            return [\substr($sourceName, 0, $macroAt), $tplLine ?? 0];
        }

        if ($tplLine === null) {
            return [$sourceName, 0];
        }

        return $this->resolveSegmentSource($sourceName, $tplLine);
    }

    private function resolveSegmentSource(string $defaultSourcePath, int $tplLine): array
    {
        if ($this->mappedSourcePath === null) {
            return [$defaultSourcePath, $tplLine];
        }

        return [
            $this->mappedSourcePath,
            $this->mappedSourceLineBase + max(0, $tplLine - $this->mappedMergedLineBase),
        ];
    }

    private function sourceLineAtOffset(string $source, int $offset): int
    {
        $offset = max(0, min($offset, strlen($source)));
        return 1 + substr_count(substr($source, 0, $offset), "\n");
    }

    /**
     * Resolve a logical template reference to a normalized logical name.
     *
     * This is where namespace logic and extension stripping would go if we
     * supported those features.  For now, we just trim whitespace and validate
     * that the name contains only safe characters.
     *
     * @param string $ref         The raw template reference (e.g. from an extends/include tag).
     * @param string $currentName The logical name of the template containing this reference (for error messages).
     * @return string Normalized logical name to use for loader lookup.
     */
    private function resolveLogicalName(string $ref, string $currentName): string
    {
        $ref = trim($ref);

        if ($ref === '') {
            throw new ClarityException("Template reference must not be empty.", $currentName);
        }

        // Strip extension if the engine has an explicit extension configured
        if (
            $this->extension !== null
                && $this->extension !== ''
                && str_ends_with($ref, $this->extension)
        ) {
            $ref = substr($ref, 0, -strlen($this->extension));
        }

        // Allow only safe characters: letters, digits, underscores, hyphens, dots, slashes, and ::
        if (!preg_match('/^[\w.\-\/:]+$/u', $ref)) {
            throw new ClarityException(
                "Template reference '{$ref}' contains invalid characters.",
                $currentName
            );
        }

        return $ref; // no normalization, no namespace logic
    }

    /**
     * Load a template's source via the active loader and record revision as a dependency.
     *
     * @param string $name Logical template name.
     */
    private function readWithDep(string $name): string
    {
        $src = $this->loader->load($name);
        if ($src === null) {
            throw new ClarityException("Template '{$name}' not found by loader.");
        }
        $this->dependencies[$name] = $src->revision;
        return $src->getCode();
    }

    /**
     * Locate the line holding the body-base marker in the generated class code.
     *
     * The result is in *code* coordinates (no leading "<?php").  compile() adds
     * +1 for the line below the marker and +1 more for the "<?php" that
     * Cache::writeAndLoad() prepends, which is how $renderBodyLine is derived.
     *
     * @param string $code Generated class code (without leading "<?php").
     * @return int 1-based line of the marker, or 0 when not found.
     */
    private function findBodyMarkerLine(string $code): int
    {
        $offset = \strpos($code, self::BODY_LINE_TOKEN);
        if ($offset === false || $offset === 0) {
            return 0;
        }

        return \substr_count($code, "\n", 0, $offset) + 1;
    }

    /**
     * Wrap the compiled render body in a class with docblock.
     *
     * @param string $className Generated class name.
     * @param string $body      PHP render body statements.
     * @return string Complete PHP class code (without leading <?php).
     */
    private function buildClass(string $className, string $body): string
    {
        // Indent the body
        $indented = implode(
            "\n",
            array_map(fn(string $l) => $l !== '' ? '        ' . $l : '', explode("\n", $body))
        );

        $depsExport  = var_export($this->dependencies, true);
        $filesExport = var_export($this->sourceFiles, true);
        // Compact packed form (see SourceMap): one short single-quoted string
        // instead of a nested array literal per range.
        $mapExport   = SourceMap::packedLiteral($this->sourceMap);
        $debugFlag   = $this->debugMode ? 'true' : 'false';
        $sandboxFlag = $this->sandboxMode ? 'true' : 'false';
        $versionInt  = self::COMPILER_VERSION;

        // Detect which registries are actually referenced in the compiled body.
        // The constructor always accepts both (so the caller stays simple), but
        // render() only unpacks the one(s) that are actually used.
        $usesFunctions = \str_contains($body, '$__c_fn');
        $usesServices  = \str_contains($body, '$__c_sv');

        $unpacks = '';
        if ($usesFunctions) {
            $unpacks .= "                \$__c_fn = \$this->__c_fn;\n";
        }
        if ($usesServices) {
            $unpacks .= "                \$__c_sv = \$this->__c_sv;\n";
        }

        // Open mode only: seed the render scope into PHP locals so a template
        // variable is the same thing in `{{ title }}` and `{% php echo $title; %}`.
        //
        // The internals are bound FIRST so EXTR_SKIP protects them: a view
        // variable named `__c_fn` cannot shadow the callable registry, and `this`
        // cannot be handed to the engine (which PHP would fatal on anyway).
        //
        // EXTR_SKIP only shields those names because they are bound above it;
        // the guard is the binding order, not the prefix, which is why the
        // prefix rule and the ordering are both needed.
        //
        // extract() on the parameter itself needs no copy — `$__c_va` stays a valid
        // array for the explicit `$__c_va['x']` escape hatch — and costs ~180 ns
        // once per render.
        if ($this->seedsScope) {
            $unpacks .= "                extract(\$__c_va, EXTR_SKIP);\n";
        }

        return <<<PHP
        // @generated by Clarity\Engine\Compiler - do not edit.
        class {$className}
        {
            // dependencies: templateName => revision, for cache invalidation
            public static array \$dependencies = {$depsExport};

            // sourceFiles: logical template names, indexed by \$sourceMap
            public static array \$sourceFiles = {$filesExport};

            // sourceMap: packed "lineDelta,fileIndex,tplLineDelta;" ranges
            public static string \$sourceMap = {$mapExport};

            // debugCompiled: whether the compiler was in debug mode when this class was generated
            public static bool \$debugCompiled = {$debugFlag};

            // sandboxCompiled: whether the compiler was in sandbox mode when this class was generated
            public static bool \$sandboxCompiled = {$sandboxFlag};

            // compilerVersion: the compiler that produced this class
            public static int \$compilerVersion = {$versionInt};

            // renderBodyLine: cache-file line where the render body starts
            public static int \$renderBodyLine = 0;

            /**
             * @param array \$__c_fn Callable registry (name => callable)
             * @param array \$__c_sv Service registry (name => mixed)
             */
            public function __construct(private array \$__c_fn, private array \$__c_sv) {}

            /**
             * Render the template with the given variables.
             *
             * @param array \$__c_va Template variables (name => value)
             * @return string Rendered output
             */
            public function render(array \$__c_va): string
            {
        {$unpacks}                ob_start();
                \$__c_ob_level = ob_get_level();
                try {
        /* @@CLARITY_BODY_LINE@@ */
        {$indented}
                    return (string) ob_get_clean();
                } catch (\Throwable \$__c_e) {
                    while (ob_get_level() >= \$__c_ob_level) {
                        ob_end_clean();
                    }
                    throw \$__c_e;
                }
            }
        }

        return '{$className}';
        PHP;
    }
}