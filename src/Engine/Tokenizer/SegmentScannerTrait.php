<?php

namespace Clarity\Engine\Tokenizer;

use Clarity\ClarityException;

/**
 * Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.
 */
trait SegmentScannerTrait
{

    // -------------------------------------------------------------------------
    // Segment splitting
    // -------------------------------------------------------------------------

    /**
     * Split a raw template source into an ordered array of segments.
     *
     * Tag boundaries are located by a quote-aware, brace-depth-aware scanner
     * rather than a single flat regex. A closing delimiter can appear inside a
     * string literal (`{{ '}}' }}`), or a brace in the expression can contain
     * one (`{{ user{k}}}` closes after `user{k}`, not after `user{k`).
     *
     * Each element is an array keyed by the KEY_TYPE, KEY_CONTENT and KEY_LINE
     * constants. The type is TEXT, OUTPUT, BLOCK or COMMENT. The line is the
     * 1-based line where the segment starts.
     *
     * @param string $source Raw template source.
     * @return array<int, array{int, string, int}>
     * @throws ClarityException When a tag is opened and never closed. Stray
     *                          closing delimiters in text are emitted as text.
     */
    public function tokenize(string $source): array
    {
        // All opener candidates in ONE regex pass. Openers that turn out to sit
        // inside a previous tag's content (e.g. a literal `{{` in a string) are
        // skipped by the $pos guard below, so this stays correct while paying
        // for a single PCRE invocation rather than one per tag.
        if (!\preg_match_all('/\{\{|\{%|\{#/', $source, $matches, \PREG_OFFSET_CAPTURE)) {
            return [
                [
                    self::KEY_TYPE    => self::TEXT,
                    self::KEY_CONTENT => \trim($source),
                    self::KEY_LINE    => 1,
                ]
            ];
        }

        $segments     = [];
        $sourceLen    = \strlen($source);
        $line         = 1;
        $pos          = 0;
        $trimNextText = false;

        foreach ($matches[0] as [$opener, $tagPos]) {
            if ($tagPos < $pos) {
                continue; // opener inside the content of an already-consumed tag
            }

            switch ($opener) {
                case '{{':
                    $type = self::OUTPUT;
                    break;
                case '{%':
                    $type = self::BLOCK;
                    break;
                default:
                    $type = self::COMMENT;
                    break;
            }

            // Whitespace control: `{%-` (and `{{-`, `{#-`) suppress the
            // whitespace immediately BEFORE the tag; a `-` before the closer
            // suppresses the whitespace immediately AFTER it.
            $trimBefore = ($source[$tagPos + 2] ?? '') === '-';
            $beforePos  = $tagPos;

            if ($trimBefore) {
                // `{%-` consumes the whitespace up to $beforePos. Extend it
                // leftwards over spaces, tabs and newlines, but never over the
                // previous tag's closing delimiter — which is what keeps
                // `{% set a = 1 -%}{%- if x %}` from swallowing the assignment.
                $b = $beforePos;
                while ($b > $pos && \str_contains(" \t\r\n", $source[$b - 1])) {
                    $b--;
                }
                $beforePos = $b;
            }

            if ($beforePos > $pos) {
                $text = \substr($source, $pos, $beforePos - $pos);
                if ($trimNextText) {
                    $text = self::trimLeftWhitespace($text);
                }
                if (self::hasVisibleText($text)) {
                    $segments[] = [
                        self::KEY_TYPE    => self::TEXT,
                        self::KEY_CONTENT => $text,
                        self::KEY_LINE    => $line,
                    ];
                }
                $line += \substr_count($text, "\n");
            }

            $innerStart = $tagPos + 2;
            if ($trimBefore) {
                $innerStart++;
            }

            if ($type === self::COMMENT) {
                $close = \strpos($source, '#}', $innerStart);
                if ($close === false) {
                    throw new ClarityException(
                        'Unclosed comment tag opened on template line ' . $line
                            . ": no matching '#}' before the end of the template.",
                        '',
                        $line
                    );
                }
                $end = $close + 2;
            } else {
                $closer = $type === self::OUTPUT ? '}}' : '%}';
                $close  = self::findTagClose($source, $innerStart, $sourceLen, $closer);
                if ($close === null) {
                    throw new ClarityException(
                        'Unclosed ' . ($type === self::OUTPUT ? 'output' : 'block')
                            . ' tag opened on template line ' . $line
                            . ": no matching '{$closer}' before the end of the template.",
                        '',
                        $line
                    );
                }
                $end = $close + 2;
            }

            // A `-` glued to the closer suppresses following whitespace.
            $trimAfter    = ($source[$end - 3] ?? '') === '-';
            $trimNextText = $trimAfter;

            $contentEnd = $end - 2;
            if ($trimAfter) {
                $contentEnd--;
            }

            $segments[] = [
                self::KEY_TYPE    => $type,
                self::KEY_CONTENT => \trim(\substr($source, $innerStart, $contentEnd - $innerStart)),
                self::KEY_LINE    => $line,
            ];

            $line += \substr_count(\substr($source, $tagPos, $end - $tagPos), "\n");
            $pos = $end;
        }

        if ($pos < $sourceLen) {
            $rest = \substr($source, $pos);
            if ($trimNextText) {
                $rest = self::trimLeftWhitespace($rest);
            }
            if (self::hasVisibleText($rest)) {
                $segments[] = [
                    self::KEY_TYPE    => self::TEXT,
                    self::KEY_CONTENT => $rest,
                    self::KEY_LINE    => $line,
                ];
            }
        }

        return $segments;
    }

    /**
     * Strip leading whitespace (spaces, tabs, newlines) from a text segment.
     */
    private static function trimLeftWhitespace(string $text): string
    {
        return \ltrim($text, " \t\r\n");
    }

    /**
     * Whether a text segment carries anything other than whitespace.
     *
     * Equivalent to `\trim($segment) !== ''` but without materialising the
     * trimmed copy, which matters because generated pages run to hundreds of
     * kilobytes and a `trim()` of every text run shows up in the compile cost.
     */
    private static function hasVisibleText(string $segment): bool
    {
        return \strspn($segment, " \t\n\r\0\x0B") < \strlen($segment);
    }

    /**
     * Locate a tag's closing delimiter, ignoring delimiters that appear inside
     * a string literal and braces nested in a collection literal.
     *
     * @param string $source Full template source.
     * @param int    $from   Offset just past the two-character opener.
     * @param int    $len    Length of $source.
     * @param string $closer Two-character closer ('}}' or '%}').
     * @return int|null Offset of the closer's first character, or null when the
     *                  source ends before a balanced closer is found.
     */
    private static function findTagClose(string $source, int $from, int $len, string $closer): ?int
    {
        // Fast path: most tags contain no quote and no brace, so the first closer
        // found by strpos is the answer. One bulk scan from $from decides that
        // without entering the loop below.
        $firstCloser = \strpos($source, $closer, $from);
        if ($firstCloser === false) {
            return null;
        }

        // Characters that can affect the scan. Everything else is skipped in
        // bulk: strcspn returns the length of the run that contains none of
        // them, so a long literal string costs one engine call, not one PHP
        // loop iteration per byte. The closer's own characters are folded in
        // (duplicates are harmless).
        $special = "\"'{" . $closer;
        $i       = $from + \strcspn($source, $special, $from, $firstCloser - $from);

        if ($i >= $firstCloser) {
            return $firstCloser;
        }

        $depth = 0;
        $c1    = $closer[0];
        $c2    = $closer[1];

        while ($i < $len) {
            $i += \strcspn($source, $special, $i, $len - $i);
            if ($i >= $len) {
                break;
            }

            $ch = $source[$i];

            if ($ch === "'" || $ch === '"') {
                $i = self::skipStringLiteral($source, $i, $len);
                continue;
            }

            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                if ($depth > 0) {
                    $depth--;
                } elseif ($c1 === '}' && ($source[$i + 1] ?? '') === $c2) {
                    return $i;
                }
            } elseif ($depth === 0 && $ch === $c1 && ($source[$i + 1] ?? '') === $c2) {
                return $i;
            }

            $i++;
        }

        return null;
    }

    /**
     * Advance past a quoted string literal. $i must point at the opening quote.
     * Backslash escapes are honoured; an unterminated literal runs to the end.
     *
     * @return int Offset just past the closing quote, or $len when unterminated.
     */
    private static function skipStringLiteral(string $source, int $i, int $len): int
    {
        $quote = $source[$i];
        $i++;

        while ($i < $len) {
            $ch = $source[$i];
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if ($ch === $quote) {
                return $i + 1;
            }
            $i++;
        }

        return $len;
    }
}
