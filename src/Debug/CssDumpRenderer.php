<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Renders debug values as a CSS block comment: /* DEBUG_DUMP: {json} *\/
 *
 * Sensitive keys are replaced with '***', and values nested deeper than
 * maxDepth with '…'. The comment-closing sequence in the JSON is escaped by
 * inserting a backslash before the slash. '<' and '>' are written as \u003C
 * and \u003E, so the output cannot close a surrounding <style> element.
 */
final class CssDumpRenderer implements DumpRenderer
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

        $json = \str_replace('*/', '*\\/', $json);

        return '/* DEBUG_DUMP: ' . $json . ' */';
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
