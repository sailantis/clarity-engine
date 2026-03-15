<?php

namespace Clarity\Engine;

use Clarity\ClarityException;
use Clarity\Template\FileLoader;
use Clarity\Template\TemplateLoader;
use SebastianBergmann\CodeCoverage\Node\File;

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
 * 4. Class wrapping + docblock with source-map and dependency metadata
 *
 * Output format
 * -------------
 * Each compiled template becomes exactly one PHP class:
 *
 *   class __Clarity_<slug>_<hash> {
 *       public static array $dependencies = ['/abs/path' => mtime, ...];
 *       public static array $sourceMap    = [phpLine => tplLine, ...];
 *       public function __construct(private array $__fl, private array $__fn) {}
 *       public function render(array $vars): string { ... }
 *   }
 *
 * $dependencies and $sourceMap are read via reflection for cache invalidation
 * and error mapping — no file I/O needed on warm paths (OPcache serves them).
 */
class Compiler
{
    private Tokenizer $tokenizer;

    /** @var array<string, int|string>  templateName → revision collected during this compilation */
    private array $dependencies = [];

    /** @var array<int, int>  phpOutputLine → templateLine source map */
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
     * @var list<array{type:string, restore:array<string,string|null>}>
     * Stack tracking loop types and compiler-scope variable bindings to restore on endfor.
     */
    private array $forStack = [];

    /** Counter for generating unique temp-variable names in compiled range loops */
    private int $rangeCounter = 0;

    /** Whether to emit debug-only assertions (range checks) in generated code */
    private bool $debugMode = false;

    /**
     * @var array<string, string>  templateVarName → PHP variable string for locally-bound loop vars.
     * Checked first during expression resolution; falls back to $vars[name] when absent.
     * Simple mapping: 'item' → '$item', 'key' → '$key', etc.
     */
    private array $localVars = [];

    /**
     * Macros defined in the current template (after pre-scan).
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
        $this->loader = $loader;
        $this->dependencies = [];
        $this->sourceMap = [];
        $this->sourceFiles = [];
        $this->sourceFileIndex = [];
        $this->phpLine = 0;
        $this->forStack = [];
        $this->rangeCounter = 0;
        $this->localVars = [];
        $this->tokenizer->setLocalVars([]);
        $this->macros = [];
        $this->macroExpansionStack = [];
        $this->context = 'html';
        $this->tokenizer->setEscapeContext('html');
        $this->extendsStack = [];
        $this->compileStack = [];

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

        return new CompiledTemplate(
            className: $className,
            code: $code,
            sourceMap: $this->sourceMap,
            dependencies: $this->dependencies,
            sourceFiles: $this->sourceFiles,
        );
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
            if (!\preg_match('/\{%-?\s*extends\s+["\']([^"\']+)["\']\s*-?%\}/s', $source, $m)) {
                return $source;
            }

            $layoutRef = $m[1];
            $layoutName = $this->resolveLogicalName($layoutRef, $currentName);

            $layoutSource = $this->readWithDep($layoutName);

            // Recursively resolve the layout's own extends
            $layoutSource = $this->resolveExtends($layoutSource, $layoutName);

            // Extract child blocks: {% block name %}...{% endblock %}
            $childBlocks = $this->extractBlocks($source);

            // Merge: replace layout's blocks with child definitions
            $merged = $this->mergeBlocks($layoutSource, $childBlocks);

            return $merged;
        } finally {
            \array_pop($this->extendsStack);
        }
    }

    /**
     * Extract all {% block name %}...{% endblock %} definitions from source.
     *
     * @return array<string, string>  block-name → inner content
     */
    private function extractBlocks(string $source): array
    {
        $blocks = [];

        // Use a simple iterative approach to handle nested blocks
        $offset = 0;
        while (\preg_match('/\{%-?\s*block\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*-?%\}/s', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $blockName = $m[1][0];
            $blockStart = $m[0][1];   // position of {% block ... %}
            $innerStart = $blockStart + \strlen($m[0][0]);

            // Find the matching {% endblock %}, accounting for nesting
            $depth = 1;
            $pos = $innerStart;
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
                    $offset = $nm[0][1] + \strlen($nm[0][0]);
                }
                $pos = $nm[0][1] + \strlen($nm[0][0]);
            }

            if ($content !== null) {
                $blocks[$blockName] = $content;
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
     * @param string              $layoutSource
     * @param array<string,string> $childBlocks
     */
    private function mergeBlocks(string $layoutSource, array $childBlocks): string
    {
        $result = '';
        $offset = 0;

        while (preg_match('/\{%-?\s*block\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*-?%\}/s', $layoutSource, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $blockName = $m[1][0];
            $tagStart = $m[0][1];
            $innerStart = $tagStart + strlen($m[0][0]);

            // Walk forward tracking nesting to find the matching {% endblock %}
            $depth = 1;
            $pos = $innerStart;
            $innerEnd = null;
            $fullEnd = null;

            while ($depth > 0 && preg_match('/\{%-?\s*(block\s+[a-zA-Z_][a-zA-Z0-9_]*|endblock)\s*-?%\}/s', $layoutSource, $nm, PREG_OFFSET_CAPTURE, $pos)) {
                $tag = trim($nm[1][0]);
                if (str_starts_with($tag, 'block')) {
                    $depth++;
                } else {
                    $depth--;
                }
                if ($depth === 0) {
                    $innerEnd = $nm[0][1];
                    $fullEnd = $nm[0][1] + strlen($nm[0][0]);
                }
                $pos = $nm[0][1] + strlen($nm[0][0]);
            }

            if ($innerEnd === null) {
                // Unclosed block tag – append the rest verbatim and bail
                $result .= substr($layoutSource, $offset);
                return $result;
            }

            // Everything before this block tag is passed through verbatim
            $result .= substr($layoutSource, $offset, $tagStart - $offset);

            // Use child's override if present, otherwise keep the default content
            $result .= $childBlocks[$blockName]
                ?? substr($layoutSource, $innerStart, $innerEnd - $innerStart);

            $offset = $fullEnd;
        }

        // Append any trailing content after the last block
        $result .= substr($layoutSource, $offset);
        return $result;
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
        $segments = $this->tokenizer->tokenize($source);

        try {
            foreach ($segments as $seg) {
                $tplLine = $seg[Tokenizer::KEY_LINE];

                switch ($seg[Tokenizer::KEY_TYPE]) {

                    case Tokenizer::TEXT:
                        if ($seg[Tokenizer::KEY_CONTENT] === '') {
                            break;
                        }
                        // Update escaping context based on <script>/<style> boundaries.
                        $this->updateContextFromText($seg[Tokenizer::KEY_CONTENT]);
                        $this->addPhpLines(
                            $lines,
                            $this->textToPhp($seg[Tokenizer::KEY_CONTENT]),
                            $tplLine,
                            $sourcePath
                        );
                        break;

                    case Tokenizer::COMMENT:
                        $this->processComment($seg[Tokenizer::KEY_CONTENT]);
                        break;

                    case Tokenizer::OUTPUT:
                        $phpExpr = $this->tokenizer->processExpression(
                            $seg[Tokenizer::KEY_CONTENT]
                        );
                        $this->addPhpLines(
                            $lines,
                            "echo {$phpExpr};",
                            $tplLine,
                            $sourcePath
                        );
                        break;

                    case Tokenizer::BLOCK:
                        $compiled = $this->compileBlock(
                            $seg[Tokenizer::KEY_CONTENT],
                            $sourcePath,
                            $tplLine,
                            $lines
                        );
                        if ($compiled !== '') {
                            $this->addPhpLines(
                                $lines,
                                $compiled,
                                $tplLine,
                                $sourcePath
                            );
                        }
                        break;
                }
            }
        } finally {
            \array_pop($this->compileStack);
        }
    }

    private function processComment(string $content): void
    {
        $inner = trim($content);
        if (str_starts_with($inner, '@context ')) {
            // Handle {# @context <name> #} hints.
            static $validContexts = [
            'html' => true,
            'js' => true,
            'css' => true
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
        $rest = $parts[1] ?? '';

        return match ($keyword) {
            'if' => 'if (' . $this->tokenizer->processCondition($rest) . '):',
            'elseif' => 'elseif (' . $this->tokenizer->processCondition($rest) . '):',
            'else' => 'else:',
            'endif' => 'endif;',
            'endfor' => $this->compileEndFor($sourcePath, $tplLine),
            'for' => $this->compileFor($rest, $sourcePath, $tplLine),
            'set' => $this->compileSet($rest, $sourcePath, $tplLine),
            // extends/block/endblock/include are handled before this stage; if seen here → ignore
            'extends', 'block', 'endblock' => '',
            'include' => $this->compileInclude($rest, $sourcePath, $tplLine, $lines),
            default => $this->registry->hasBlock($keyword)
            ? $this->registry->compileBlock(
                $keyword,
                $rest,
                $sourcePath,
                $tplLine,
                fn(string $e) => $this->tokenizer->processCondition($e)
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
        $source = (string) \preg_replace_callback($pattern, function (array $m): string {
            $name = $m[1];
            $params = $m[2] !== '' ? \array_map('trim', \explode(',', $m[2])) : [];
            $this->macros[$name] = ['params' => $params, 'body' => $m[3]];
            return '';
        }, $source);
    }

    /**
     * Inline a macro call into the current output.
     * Params become PHP locals ($__m_paramName) scoped to the macro body.
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

        $macro = $this->macros[$name];
        $params = $macro['params'];
        $args = $argsRaw !== '' ? $this->splitArgList($argsRaw) : [];

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
            $phpVar = '$__m_' . $param;
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
        $parts = [];
        $depth = 0;
        $start = 0;
        $len = \strlen($input);
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

    private const RE_FOR_IN = '/^([a-zA-Z_][a-zA-Z0-9_.]*)(?:\s*,\s*([a-zA-Z_][a-zA-Z0-9_]*))?\s+in\s+(.+?)(?:(\.\.\.?)(.+?)(?:\s+step\s+(.+))?)?$/s';

    /**
     * Compile {% for item in list %} → PHP foreach.
     * Compile {% for item, idx in list %} → PHP foreach.
     * Compile {% for i in start..end %} / {% for i in start...end [step N] %} → native PHP for.
     *
     * Range syntax:
     *   ..   exclusive upper bound  (start ≤ i < end)
     *   ...  inclusive upper bound  (start ≤ i ≤ end)
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
            $start = $this->tokenizer->processCondition(trim($m[3]));
            $inclusive = ($m[4] === '..');
            $end = $this->tokenizer->processCondition(trim($m[5]));
            $step = isset($m[6]) && $m[6] !== '' ? $this->tokenizer->processCondition(trim($m[6])) : '1';
            $cmp = $inclusive ? '<=' : '<';

            $n = $this->rangeCounter++;
            $rb = "\$__rb{$n}";
            $re = "\$__re{$n}";
            $rs = "\$__rs{$n}";

            // Allocate a local PHP variable for the iteration variable (same name as template var)
            $itemTplName = trim($m[1]);
            $itemPhpVar = '$' . $itemTplName;
            $restore = [$itemTplName => $this->localVars[$itemTplName] ?? null];
            $this->localVars[$itemTplName] = $itemPhpVar;
            $this->tokenizer->setLocalVars($this->localVars);

            $this->forStack[] = ['type' => 'for', 'restore' => $restore];
            $rangeLines = ["{$rb} = {$start}; {$re} = {$end}; {$rs} = {$step};"];
            if ($this->debugMode) {
                $srcLabel = addslashes($sourcePath . ':' . $tplLine);
                $rangeLines[] = "if ({$rs} === 0) { throw new \\RuntimeException('Clarity: range step cannot be zero ({$srcLabel})'); }";
                $rangeLines[] = "if (({$re} - {$rb}) * {$rs} < 0) { throw new \\RuntimeException('Clarity: range step moves away from end, would produce an infinite loop ({$srcLabel})'); }";
            }
            $rangeLines[] = "for ({$itemPhpVar} = {$rb}; {$itemPhpVar} {$cmp} {$re}; {$itemPhpVar} += {$rs}):";
            return implode("\n", $rangeLines);
        }

        // Standard foreach — evaluate list in the current (outer) scope first
        $listExpr = $this->tokenizer->processCondition(trim($m[3]));

        $itemTplName = trim($m[1]);
        $itemPhpVar = '$' . $itemTplName;
        $restore = [$itemTplName => $this->localVars[$itemTplName] ?? null];
        $this->localVars[$itemTplName] = $itemPhpVar;

        // Optional key variable
        if (isset($m[2]) && $m[2] !== '') {
            $keyTplName = trim($m[2]);
            $keyPhpVar = '$' . $keyTplName;
            $restore[$keyTplName] = $this->localVars[$keyTplName] ?? null;
            $this->localVars[$keyTplName] = $keyPhpVar;
            $this->tokenizer->setLocalVars($this->localVars);
            $this->forStack[] = ['type' => 'foreach', 'restore' => $restore];
            return "foreach ({$listExpr} as {$keyPhpVar} => {$itemPhpVar}):";
        }

        $this->tokenizer->setLocalVars($this->localVars);
        $this->forStack[] = ['type' => 'foreach', 'restore' => $restore];
        return "foreach ({$listExpr} as {$itemPhpVar}):";
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
        $newCtx = null;

        $boundaries = [
            '/<script[\s>\/]/i' => 'js',
            '/<\/script>/i' => 'html',
            '/<style[\s>\/]/i' => 'css',
            '/<\/style>/i' => 'html',
        ];

        foreach ($boundaries as $pattern => $ctx) {
            if (\preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                $last = \end($m[0]);
                if ($last[1] > $lastPos) {
                    $lastPos = $last[1];
                    $newCtx = $ctx;
                }
            }
        }

        if ($newCtx !== null && $newCtx !== $this->context) {
            $this->context = $newCtx;
            $this->tokenizer->setEscapeContext($newCtx);
        }
    }

    private function compileEndFor(string $sourcePath, int $tplLine): string
    {
        $entry = array_pop($this->forStack);
        if ($entry === null) {
            throw new ClarityException("Unexpected 'endfor' without matching 'for'", $sourcePath, $tplLine);
        }
        // Restore compile-time local var bindings to what they were before this loop
        foreach ($entry['restore'] as $name => $oldValue) {
            if ($oldValue === null) {
                unset($this->localVars[$name]);
            } else {
                $this->localVars[$name] = $oldValue;
            }
        }
        $this->tokenizer->setLocalVars($this->localVars);
        return $entry['type'] === 'for' ? 'endfor;' : 'endforeach;';
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
            $this->sourceFiles[] = $file;
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

        $depsExport = var_export($this->dependencies, true);
        $filesExport = var_export($this->sourceFiles, true);
        $mapExport = var_export($this->sourceMap, true);
        $debugFlag = $this->debugMode ? 'true' : 'false';

        // Detect which registries are actually referenced in the compiled body.
        // The constructor always accepts all three (so the caller stays simple),
        // but render() only unpacks the ones that are actually used.
        $usesFilters = \str_contains($body, '$__fl');
        $usesFunctions = \str_contains($body, '$__fn');
        $usesServices = \str_contains($body, '$__sv');

        $unpacks = '';
        if ($usesFilters) {
            $unpacks .= "                \$__fl = \$this->__fl;\n";
        }
        if ($usesFunctions) {
            $unpacks .= "                \$__fn = \$this->__fn;\n";
        }
        if ($usesServices) {
            $unpacks .= "                \$__sv = \$this->__sv;\n";
        }

        return <<<PHP
        class {$className}
        {
            /** @var array<string,int|string> logicalName => revision for every template read during compilation */
            public static array \$dependencies = {$depsExport};

            /** @var string[] source file paths, indexed by the integer used in \$sourceMap */
            public static array \$sourceFiles = {$filesExport};

            /** @var list<array{int,int,int}> source-map ranges: [phpLineStart, fileIndex, templateLine] */
            public static array \$sourceMap = {$mapExport};

            /** @var bool  whether this template was compiled with debug mode enabled */
            public static bool \$debugCompiled = {$debugFlag};

            /** @param array<string,callable> \$__fl Filter registry */
            /** @param array<string,callable> \$__fn Function registry */
            /** @param array<string,callable> \$__sv Service registry */
            public function __construct(private array \$__fl, private array \$__fn, private array \$__sv) {}

            public function render(array \$vars): string
            {
        {$unpacks}                ob_start();
                try {
        {$indented}
                    return (string) ob_get_clean();
                } catch (\Throwable \$__e) {
                    ob_end_clean();
                    throw \$__e;
                }
            }
        }

        return '{$className}';
        PHP;
    }
}
