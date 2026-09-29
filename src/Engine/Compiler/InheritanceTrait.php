<?php

namespace Clarity\Engine\Compiler;

use Clarity\ClarityException;
use Clarity\Engine\CompiledTemplate;
use Clarity\Engine\Compiler;
use Clarity\Engine\Registry;
use Clarity\Engine\SourceMap;
use Clarity\Engine\Tokenizer;
use Clarity\Template\FileLoader;
use Clarity\Template\TemplateLoader;

/**
 * Extracted from Clarity\Engine\Compiler to keep each file small. See that class for docs.
 */
trait InheritanceTrait
{


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
}
