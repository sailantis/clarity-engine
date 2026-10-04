<?php

namespace Clarity\Engine\Compiler;

use Clarity\ClarityException;
use Clarity\Engine\Tokenizer;

/**
 * Extracted from Clarity\Engine\Compiler to keep each file small. See that class for docs.
 */
trait BodyCompilerTrait
{

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
        // 0, not 1: the scanner knows the real line for an unclosed tag and
        // carries it on the exception. Passing a line here would overwrite it.
        $segments = $this->withLocation(
            fn() => $this->tokenizer->tokenize($source),
            $sourcePath,
            0
        );

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
                        $phpExpr = $this->withLocation(
                            fn() => $this->tokenizer->processExpression(
                                $seg[Tokenizer::KEY_CONTENT]
                            ),
                            $mappedSourcePath,
                            $mappedTplLine
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
     * @param string $content    Inner text of the {% … %} tag (trimmed), original case.
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
        if (\preg_match('/^parent\s*$/i', $content)) {
            throw new ClarityException(
                "'{% parent %}' is only valid inside an overriding child block.",
                $sourcePath,
                $tplLine
            );
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
            // A well-formed definition never gets here: extractMacros() removes it
            // before tokenizing. What does is an unclosed one, or the removed
            // `@`-prefixed spelling.
            'macro'  => throw new ClarityException(
                $this->macroDefinitionError($rest),
                $sourcePath,
                $tplLine,
                $this->templatePath($sourcePath)
            ),
            // A macro call is dispatched here, not through the registry, so
            // `{% call name() %}` can never be captured by a registered directive.
            'call'   => $this->compileMacroCallTag($rest, $sourcePath, $tplLine, $lines),
            // extends/block/endblock/include are handled before this stage; if seen here → ignore
            'extends', 'block', 'endblock' => '',
            'include'                      => $this->compileInclude($rest, $sourcePath, $tplLine, $lines),
            // A `php` keyword that reaches code generation was NOT extracted by
            // extractPhpBlocks(): either the sandbox refused it, or the tag
            // carried no body.  A well-formed `{% php <code> %}` never gets here
            // -- extraction handles it.
            'php'                          => throw new ClarityException(
                !$this->policy->allows('rawPhp')
                    ? "'{% php %}' is not allowed by this policy. "
                        . "Grant the 'rawPhp' rule to allow it."
                    : "Empty '{% php %}' tag: it needs code, as in "
                        . "'{% php echo \$total; %}'.",
                $sourcePath,
                $tplLine,
                $this->templatePath($sourcePath)
            ),
            default                        => $this->registry->hasDirective($keyword)
            ? $this->registry->compileDirective(
                $keyword,
                $rest,
                $sourcePath,
                $tplLine,
                fn(string $e) => $this->withLocation(
                    fn() => $this->tokenizer->processCondition($e),
                    $sourcePath,
                    $tplLine
                ),
                $this
            )
            : throw new ClarityException(
                $this->macroCallSyntaxHint($content) ?? "Unknown directive '{$keyword}'",
                $sourcePath,
                $tplLine
            ),
        };
    }
}
