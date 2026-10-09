<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Renders debug values as a JavaScript block comment: ;/* DEBUG_DUMP: {json} *\/
 *
 * The output starts with an empty statement (;) followed by the comment, so it
 * can be placed where a JavaScript statement is allowed. Sensitive keys are
 * replaced with '***', and values nested deeper than maxDepth with '…'. The
 * comment-closing sequence in the JSON is escaped by inserting a backslash
 * before the slash, so it cannot end the comment early. '<' and '>' are
 * written as \u003C and \u003E, so the output cannot close a surrounding
 * <script> element.
 */
final class JsDumpRenderer implements DumpRenderer
{
    use DumpMaskingTrait;

    public function render(mixed $value, DumpOptions $opts): string
    {
        $masked = $this->maskValue($value, $opts, 0);
        $json = (string) \json_encode(
            $masked,
            \JSON_UNESCAPED_UNICODE
                | \JSON_UNESCAPED_SLASHES
                | \JSON_PARTIAL_OUTPUT_ON_ERROR
                | \JSON_HEX_TAG
        );

        // Escape any '*/' to prevent closing the JS comment early
        $json = \str_replace('*/', '*\\/', $json);

        return ';/* DEBUG_DUMP: ' . $json . ' */';
    }

    private function maskValue(mixed $value, DumpOptions $opts, int $depth): mixed
    {
        if ($depth >= $opts->getMaxDepth()) {
            return '…';
        }

        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if ($this->isExpandableObject($value)) {
            $value = $this->objectProperties($value);
        }

        if (!\is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $k => $v) {
            if (\is_string($k) && $this->isMasked($k, $opts)) {
                $result[$k] = '***';
            } else {
                $result[$k] = $this->maskValue($v, $opts, $depth + 1);
            }
        }
        return $result;
    }
}
