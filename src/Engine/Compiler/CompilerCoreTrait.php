<?php

namespace Clarity\Engine\Compiler;

use Clarity\ClarityException;
use Clarity\Engine\CompiledTemplate;
use Clarity\Template\TemplateLoader;

/**
 * Extracted from Clarity\Engine\Compiler to keep each file small. See that class for docs.
 */
trait CompilerCoreTrait
{

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
        $this->loader           = $loader;
        $this->dependencies     = [];
        $this->sourceMap        = [];
        $this->sourceFiles      = [];
        $this->sourceFileIndex  = [];
        $this->phpLine          = 0;
        $this->forStack         = [];
        $this->ifDepth          = 0;
        $this->forElseSeq       = 0;
        $this->forHeaderPending = false;
        $this->localVars        = [];
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
}
