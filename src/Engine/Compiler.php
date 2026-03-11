<?php
namespace Clarity\Engine;

use Clarity\ClarityException;

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

    /** @var array<string, int>  absolutePath → mtime collected during this compilation */
    private array $dependencies = [];

    /** @var array<int, int>  phpOutputLine → templateLine source map */
    private array $sourceMap = [];

    /** @var string[]  de-duplicated list of source file paths, in order of first appearance */
    private array $sourceFiles = [];

    /** @var array<string,int>  path → index in $sourceFiles */
    private array $sourceFileIndex = [];

    /** Current PHP output line counter (tracks lines emitted to the render body) */
    private int $phpLine = 0;

    /** View base path used to resolve relative extends/include paths */
    private string $basePath = '';

    /** View extension (e.g. '.clarity.html') */
    private string $extension = '.clarity.html';

    /** @var array<string, string>  namespace → path */
    private array $namespaces = [];

    /** @var list<'for'|'foreach'>  stack tracking loop types for endfor compilation */
    private array $forStack = [];

    /** Counter for generating unique temp-variable names in compiled range loops */
    private int $rangeCounter = 0;

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

    public function setBasePath(string $path): static
    {
        $this->basePath = rtrim($path, '/\\');
        return $this;
    }

    public function setExtension(string $ext): static
    {
        $this->extension = $ext[0] === '.' ? $ext : '.' . $ext;
        return $this;
    }

    public function setNamespaces(array $namespaces): static
    {
        $this->namespaces = $namespaces;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Compile a template file and return a CompiledTemplate value object.
     *
     * @param string $sourcePath Absolute path to the .clarity.html file.
     * @throws ClarityException On compilation errors.
     */
    public function compile(string $sourcePath): CompiledTemplate
    {
        $this->dependencies = [];
        $this->sourceMap = [];
        $this->sourceFiles = [];
        $this->sourceFileIndex = [];
        $this->phpLine = 0;
        $this->forStack = [];
        $this->rangeCounter = 0;
        $this->extendsStack = [];
        $this->compileStack = [];

        if (!is_file($sourcePath)) {
            throw new ClarityException("Template file not found: {$sourcePath}", $sourcePath);
        }

        $source = $this->readWithDep($sourcePath);

        // Resolve extends before anything else
        $source = $this->resolveExtends($source, $sourcePath);

        // Unique class name prevents redeclaration collisions in long-running
        // processes (Swoole, RoadRunner, etc.) when a template is recompiled
        // mid-flight. The md5 prefix keeps it identifiable per source file.
        $className = '__Clarity_' . \md5($sourcePath) . '_' . \substr(\str_replace('.', '', \uniqid('', true)), -12);

        // Compile the render body
        $body = $this->compileSource($source, $sourcePath);

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
     * @param string $source      Full source of the child template.
     * @param string $sourcePath  Absolute path of the child (for error reporting).
     * @return string Merged source ready for compilation.
     */
    private function resolveExtends(string $source, string $sourcePath): string
    {
        if (\in_array($sourcePath, $this->extendsStack, true)) {
            $chain = [...$this->extendsStack, $sourcePath];
            throw new ClarityException(
                'Recursive template inheritance detected: ' . \implode(' -> ', $chain),
                $sourcePath
            );
        }

        $this->extendsStack[] = $sourcePath;

        try {
            // Match {% extends "path" %} or {% extends 'path' %}
            if (!\preg_match('/\{%-?\s*extends\s+["\']([^"\']+)["\']\s*-?%\}/s', $source, $m)) {
                return $source;
            }

            $layoutRef = $m[1];
            $layoutPath = $this->resolvePath($layoutRef, $sourcePath);

            if (!is_file($layoutPath)) {
                throw new ClarityException("Layout file not found: {$layoutPath}", $sourcePath);
            }

            $layoutSource = $this->readWithDep($layoutPath);

            // Recursively resolve the layout's own extends
            $layoutSource = $this->resolveExtends($layoutSource, $layoutPath);

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
                        $this->addPhpLines(
                            $lines,
                            $this->textToPhp($seg[Tokenizer::KEY_CONTENT]),
                            $tplLine,
                            $sourcePath
                        );
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
            $item = $this->tokenizer->processLvalue($m[1]);
            $start = $this->tokenizer->processCondition(trim($m[3]));
            $inclusive = ($m[4] === '..');
            $end = $this->tokenizer->processCondition(trim($m[5]));
            $step = isset($m[6]) && $m[6] !== '' ? $this->tokenizer->processCondition(trim($m[6])) : '1';
            $cmp = $inclusive ? '<=' : '<';

            $n = $this->rangeCounter++;
            $rb = "\$__rb{$n}";
            $re = "\$__re{$n}";
            $rs = "\$__rs{$n}";

            $srcLabel = addslashes($sourcePath . ':' . $tplLine);

            $this->forStack[] = 'for';
            return implode("\n", [
                "{$rb} = {$start}; {$re} = {$end}; {$rs} = {$step};",
                "if ({$rs} === 0) { throw new \\RuntimeException('Clarity: range step cannot be zero ({$srcLabel})'); }",
                "if (({$re} - {$rb}) * {$rs} < 0) { throw new \\RuntimeException('Clarity: range step moves away from end, would produce an infinite loop ({$srcLabel})'); }",
                "for ({$item} = {$rb}; {$item} {$cmp} {$re}; {$item} += {$rs}):",
            ]);
        }

        // Standard foreach
        $item = $this->tokenizer->processLvalue($m[1]);
        $listExpr = $this->tokenizer->processCondition(trim($m[3]));

        // Optional key
        if (isset($m[2]) && $m[2] !== '') {
            $idx = $this->tokenizer->processLvalue($m[2]);
            $this->forStack[] = 'foreach';
            return "foreach ({$listExpr} as {$idx} => {$item}):";
        }

        // No key
        $this->forStack[] = 'foreach';
        return "foreach ({$listExpr} as {$item}):";
    }

    /**
     * Compile {% endfor %} → the correct PHP closing keyword based on the
     * matching opening loop (native `for` vs `foreach`).
     */
    private function compileEndFor(string $sourcePath, int $tplLine): string
    {
        $type = array_pop($this->forStack);
        if ($type === null) {
            throw new ClarityException("Unexpected 'endfor' without matching 'for'", $sourcePath, $tplLine);
        }
        return $type === 'for' ? 'endfor;' : 'endforeach;';
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
     * Compile {% include "path" %} by recursively compiling the included file
     * and writing its output directly into $outLines, preserving source-map
     * accuracy (no double-counting of PHP lines).
     *
     * @param string $rest      Everything after the 'include' keyword.
     * @param string $sourcePath Absolute path of the including file.
     * @param int    $tplLine   Template line of the include directive.
     * @param array  $outLines  Accumulator to write the compiled lines into (mutated).
     */
    private function compileInclude(string $rest, string $sourcePath, int $tplLine, array &$outLines): string
    {
        if (!\preg_match(self::RE_INCLUDE, trim($rest), $m)) {
            throw new ClarityException("Malformed include directive: 'include {$rest}'", $sourcePath, $tplLine);
        }

        $includePath = $this->resolvePath($m[1], $sourcePath);

        if (\in_array($includePath, $this->compileStack, true)) {
            $chain = [...$this->compileStack, $includePath];
            throw new ClarityException(
                'Recursive static include detected: ' . \implode(' -> ', $chain),
                $sourcePath,
                $tplLine
            );
        }

        if (!is_file($includePath)) {
            throw new ClarityException("Included file not found: {$includePath}", $sourcePath, $tplLine);
        }

        $includeSource = $this->readWithDep($includePath);
        $includeSource = $this->resolveExtends($includeSource, $includePath);

        // Inline directly into the caller's accumulator so PHP line counts remain
        // contiguous and each line is attributed to the correct source file.
        $this->compileSourceInto($includeSource, $includePath, $outLines);
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
     * Resolve a template reference to an absolute filesystem path.
     *
     * Accepted forms
     * --------------
     * - Relative:   "layouts/main", "partials.header", "user_profile"
     *               Dots and forward slashes are both valid directory separators;
     *               dots are normalized to slashes before path construction.
     * - Namespace:  "admin::dashboard.index"  (namespace registered via setNamespaces())
     *               The part after '::' follows the same dot/slash normalization.
     *
     *
     * @param string $ref        Reference from the template (e.g. "layouts/main", "admin::layout").
     * @param string $sourcePath Absolute path of the currently-compiling file (for error reporting).
     * @throws ClarityException On traversal attempts, absolute paths, or invalid characters.
     */
    private function resolvePath(string $ref, string $sourcePath): string
    {
        $ref = trim($ref);

        if ($ref === '') {
            throw new ClarityException("Template reference must not be empty.", $sourcePath);
        }

        $addExtension = !str_ends_with($ref, $this->extension);

        $ns = \strstr($ref, '::', true);
        if ($ns !== false) {
            // ----- Namespace path: "ns::segment.or/path" -----

            $name = \substr($ref, \strlen($ns) + 2);

            if (!isset($this->namespaces[$ns])) {
                throw new ClarityException("Unknown view namespace '{$ns}'", $sourcePath);
            }

            // Validate the path portion (alphanumeric, underscores, hyphens, dots, slashes)
            if (!\preg_match('/^[\w_.\-]+$/u', $name)) {
                throw new ClarityException(
                    "Template reference '{$ref}' contains invalid characters in the name portion.",
                    $sourcePath
                );
            }

            // Dots and slashes are both accepted as separators; normalise to slashes.
            $path = $this->namespaces[$ns] . '/' . str_replace('.', '/', $name);
        } else {
            // ----- Relative path: "layouts/main", "partials.header" -----

            // Allow only safe characters: letters, digits, underscores, hyphens, dots, slashes.
            if (!\preg_match('/^[\w_.\-\/]+$/u', $ref)) {
                throw new ClarityException(
                    "Template reference '{$ref}' contains invalid characters.",
                    $sourcePath
                );
            }

            // Dots and slashes are both accepted as separators; normalise to slashes.
            $path = $this->basePath . '/' . str_replace('.', '/', $ref);
        }

        if ($addExtension) {
            $path .= $this->extension;
        }

        return $path;
    }

    /**
     * Read a file's contents and record it as a dependency.
     */
    private function readWithDep(string $absolutePath): string
    {
        $this->dependencies[$absolutePath] = (int) filemtime($absolutePath);
        return file_get_contents($absolutePath);
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

        return <<<PHP
        class {$className}
        {
            /** @var array<string,int> absolutePath => mtime for every file read during compilation */
            public static array \$dependencies = {$depsExport};

            /** @var string[] source file paths, indexed by the integer used in \$sourceMap */
            public static array \$sourceFiles = {$filesExport};

            /** @var list<array{int,int,int}> source-map ranges: [phpLineStart, fileIndex, templateLine] */
            public static array \$sourceMap = {$mapExport};

            /** @param array<string,callable> \$__fl Filter registry */
            /** @param array<string,callable> \$__fn Function registry */
            /** @param array<string,callable> \$__sv Service registry */
            public function __construct(private array \$__fl, private array \$__fn, private array \$__sv) {}

            public function render(array \$vars): string
            {
                \$__fl = \$this->__fl;
                \$__fn = \$this->__fn;
                \$__sv = \$this->__sv;
                ob_start();
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
