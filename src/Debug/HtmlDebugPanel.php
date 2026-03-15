<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Collects DebugEvents and renders a self-contained floating HTML panel
 * appended to the page bottom-right corner.
 *
 * Register it via enableDebug(new DumpOptions(showPanel: true)) or subscribe
 * it manually to a DebugEventBus and call getHtml() after rendering.
 */
final class HtmlDebugPanel implements DebugListener
{
    /** @var list<DebugEvent> */
    private array $events = [];

    private float $startTime;

    public function __construct()
    {
        $this->startTime = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? \microtime(true));
    }

    public function onEvent(DebugEvent $event): void
    {
        $this->events[] = $event;
    }

    public function getHtml(): string
    {
        $rows = '';
        $count = \count($this->events);

        foreach ($this->events as $event) {
            $type = \htmlspecialchars($event->type, \ENT_QUOTES, 'UTF-8');
            $elapsed = \number_format(($event->timestamp - $this->startTime) * 1000, 2);
            $payload = '';

            foreach ($event->payload as $k => $v) {
                $key = \htmlspecialchars((string) $k, \ENT_QUOTES, 'UTF-8');
                $val = \htmlspecialchars(
                    \is_string($v) ? $v : (string) \json_encode($v),
                    \ENT_QUOTES,
                    'UTF-8'
                );
                $payload .= '<span class="cdp-key">' . $key . '</span>:<span class="cdp-val">' . $val . '</span> ';
            }

            $rows .= '<tr>'
                . '<td class="cdp-ms">' . $elapsed . 'ms</td>'
                . '<td class="cdp-type">' . $type . '</td>'
                . '<td>' . $payload . '</td>'
                . '</tr>' . "\n";
        }

        $countHtml = \htmlspecialchars((string) $count, \ENT_QUOTES, 'UTF-8');

        return '<div id="clarity-debug-panel">'
            . '<div id="cdp-head" onclick="(function(){var b=document.getElementById(\'cdp-body\');b.style.display=b.style.display===\'none\'?\'\':\'none\';})()">'
            . '&#x1F50E; Clarity Debug <small>(' . $countHtml . ' events)</small><span id="cdp-toggle">&#x25B2;</span>'
            . '</div>'
            . '<div id="cdp-body">'
            . '<table>'
            . '<thead><tr><th>Time</th><th>Event</th><th>Payload</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table></div>'
            . '<style>'
            . '#clarity-debug-panel{position:fixed;bottom:0;right:0;z-index:99999;max-width:680px;'
            . 'max-height:420px;overflow:auto;background:#1e1e2e;color:#cdd6f4;'
            . 'font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;'
            . 'border-top-left-radius:8px;border:1px solid #45475a;box-shadow:0 -4px 20px rgba(0,0,0,.4)}'
            . '#cdp-head{background:#181825;padding:6px 14px;cursor:pointer;display:flex;'
            . 'align-items:center;justify-content:space-between;user-select:none;gap:8px}'
            . '#cdp-head small{color:#6c7086}'
            . '#cdp-toggle{margin-left:auto;opacity:.7}'
            . '#cdp-body{padding:0 14px 10px;overflow:auto;max-height:370px}'
            . '#cdp-body table{width:100%;border-collapse:collapse;margin-top:8px}'
            . '#cdp-body th{text-align:left;padding:4px 8px;border-bottom:1px solid #45475a;color:#7dc4e4}'
            . '#cdp-body td{padding:3px 8px;vertical-align:top;border-bottom:1px solid #313244;white-space:nowrap}'
            . '.cdp-ms{color:#6c7086;min-width:55px}'
            . '.cdp-type{color:#89b4fa;min-width:140px}'
            . '.cdp-key{color:#94e2d5}'
            . '.cdp-val{color:#a6e3a1;margin-right:6px}'
            . '</style>'
            . '</div>';
    }
}
