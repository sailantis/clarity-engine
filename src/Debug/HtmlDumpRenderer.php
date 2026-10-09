<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Renders debug values as a collapsible HTML tree using <details>/<summary>.
 *
 * Each array is labelled with its type and size: "object (n)" for a non-empty
 * associative array, showing key: value entries, and "array (n)" for a
 * sequential array. Empty arrays render as []. Objects are shown by their
 * properties, like associative arrays. The top-level array is expanded and
 * nested arrays are collapsed. Scalar values are HTML-escaped. Values under
 * masked keys or property names are shown as ***.
 *
 * Every render includes the inline <style> block, so each dump is
 * self-contained.
 */
final class HtmlDumpRenderer implements DumpRenderer
{
    use DumpMaskingTrait;

    public function render(mixed $value, DumpOptions $opts): string
    {
        return self::css() . '<div class="clarity-dump">' . $this->renderValue($value, $opts, 0) . '</div>';
    }

    private function renderValue(mixed $value, DumpOptions $opts, int $depth): string
    {
        if ($depth >= $opts->getMaxDepth()) {
            return '<em class="cd-truncated">…</em>';
        }

        if (\is_array($value)) {
            return $this->renderArray($value, $opts, $depth);
        }

        if (\is_null($value)) {
            return '<span class="cd-null">null</span>';
        }

        if (\is_bool($value)) {
            return '<span class="cd-bool">' . ($value ? 'true' : 'false') . '</span>';
        }

        if (\is_int($value) || \is_float($value)) {
            return '<span class="cd-num">'
                . \htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . '</span>';
        }

        if (\is_string($value)) {
            return '<span class="cd-str">&quot;'
                . \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . '&quot;</span>';
        }

        if ($this->isExpandableObject($value)) {
            return $this->renderArray($this->objectProperties($value), $opts, $depth);
        }

        return '<span class="cd-other">'
            . \htmlspecialchars(\print_r($value, true), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
            . '</span>';
    }

    private function renderArray(array $arr, DumpOptions $opts, int $depth): string
    {
        $count   = \count($arr);
        $isAssoc = $count > 0 && \array_keys($arr) !== \range(0, $count - 1);
        $open    = $isAssoc ? '{' : '[';
        $close   = $isAssoc ? '}' : ']';
        $label   = $isAssoc ? "object ({$count})" : "array ({$count})";

        if ($count === 0) {
            return '<span class="cd-empty">' . $open . $close . '</span>';
        }

        $items = '';
        $shown = 0;
        foreach ($arr as $k => $v) {
            if ($shown >= $opts->getMaxItems()) {
                $remaining = $count - $shown;
                $items .= '<li class="cd-more">… ' . $remaining . ' more …</li>';
                break;
            }

            $keyHtml = $isAssoc
                ? '<span class="cd-key">' . \htmlspecialchars((string) $k, \ENT_QUOTES, 'UTF-8') . '</span>: '
                : '';

            if ($isAssoc && \is_string($k) && $this->isMasked($k, $opts)) {
                $items .= '<li>' . $keyHtml . '<span class="cd-masked">***</span></li>';
            } else {
                $items .= '<li>' . $keyHtml . $this->renderValue($v, $opts, $depth + 1) . '</li>';
            }

            $shown++;
        }

        $openAttr = $depth === 0 ? 'open' : '';
        return <<<HTML
            <details {$openAttr}>
                <summary class="cd-label">{$label}</summary>
                <ul>{$items}</ul>
            </details>
        HTML;
    }

    private static function css(): string
    {
        return <<<HTML
            <style>
            .clarity-dump{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;color: #1e1e2e;background:#f8f8fc;border:1px solid #d0d0e0;border-radius:6px;padding:10px 14px;margin:8px 0;max-width:100%;overflow:auto}
            .clarity-dump details{margin:4px 0;border-left:2px solid                                    #d0d0e0;padding-left:10px}
            .clarity-dump summary{cursor:pointer;user-select:none;font-weight:600;list-style:none}
            .clarity-dump summary::before{content:"▶ ";font-size:10px;opacity:.6}
            .clarity-dump details[open]>summary::before{content:"▼ "}
            .clarity-dump ul{list-style:none;margin:4px 0 0;padding:0}
            .clarity-dump li{margin:2px 0;padding-left:4px}
            .clarity-dump .cd-label{color:     #555}
            .clarity-dump .cd-key{color:       #0070c1}
            .clarity-dump .cd-str{color:       #098658}
            .clarity-dump .cd-num{color:       #098658}
            .clarity-dump .cd-bool{color:      #0000ff}
            .clarity-dump .cd-null{color:      #800080}
            .clarity-dump .cd-masked{color:    #999;font-style:italic}
            .clarity-dump .cd-more{color:      #888;font-style:italic}
            .clarity-dump .cd-truncated{color: #999;font-style:italic}
            .clarity-dump .cd-empty{color:     #999}
            .clarity-dump .cd-other{color:     #555}
            </style>
            HTML;
    }
}
