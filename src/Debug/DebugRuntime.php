<?php

declare(strict_types=1);

namespace Clarity\Debug;

use Clarity\Engine\Registry;

/**
 * The one place that knows how debug output is produced.
 *
 * Clarity used to have two debug entry points that disagreed: a low-level
 * `setDebugMode(true)` that merely let `dump()` resolve, and `enableDebug()`
 * that also installed the renderers, the event bus and the HTML panel.  The
 * first was a degraded form of the second — and, because the fallback formatter
 * used `print_r`, a documented public method that printed secrets the masking
 * renderer would have hidden.
 *
 * There is only one debug state now, and this object is it.  The engine builds
 * a DebugRuntime when debug is switched on and hands it to the
 * {@see Registry}, which is what every `dump()`/`dd()` call and every
 * `{{ x |> dump }}` probe actually reaches.
 *
 * The registry deliberately owns no debug behaviour of its own: the renderers
 * and the {@see DumpOptions} live here, so there is no second code path that
 * could render a value without masking it.
 */
final class DebugRuntime
{
    private HtmlDumpRenderer $html;
    private CliDumpRenderer $cli;
    private JsDumpRenderer $js;

    /** The event bus, present whenever debug is on. */
    public readonly DebugEventBus $bus;

    /** The panel, subscribed to the bus only when DumpOptions::showPanel. */
    public readonly ?HtmlDebugPanel $panel;

    public function __construct(public readonly DumpOptions $options)
    {
        $this->html = new HtmlDumpRenderer();
        $this->cli  = new CliDumpRenderer();
        $this->js   = new JsDumpRenderer();
        $this->bus  = new DebugEventBus();

        $this->panel = $options->showPanel ? new HtmlDebugPanel() : null;
        if ($this->panel !== null) {
            $this->bus->subscribe($this->panel);
        }
    }

    /**
     * Install this runtime's formatters on a registry.
     *
     * `dump` goes through exactly one formatter, which renders HTML or a JS
     * comment according to the compile-time context, and masks the keys listed
     * in {@see DumpOptions::$maskKeys}.
     */
    public function register(Registry $registry): void
    {
        $registry->setDumpHandler(
            fn(string $ctx, mixed ...$args): string => $this->render($ctx, $args)
        );
        $registry->setDdHandler(
            fn(string $ctx, mixed ...$args): never => $this->dumpAndDie($ctx, $args)
        );
    }

    /**
     * The body of `dump(…)` and of the `{{ x |> dump }}` probe: the rendered
     * markup, as a string.
     *
     * @param list<mixed> $args
     */
    public function render(string $ctx, array $args): string
    {
        $value = \count($args) === 1 ? $args[0] : $args;

        return $ctx === 'js'
            ? $this->js->render($value, $this->options)
            : $this->html->render($value, $this->options);
    }

    /**
     * The pass-through probe behind the FILTER form `{{ x |> dump }}`: emit the
     * dump at the pipe position, then RETURN the piped value so a following
     * step still sees the original value.
     *
     * The dump is emitted (not returned) because the filter RESULT is the
     * value: `{{ x |> dump |> length }}` must measure x. Emitting keeps the
     * debug output visible in the page and in JS contexts (where the renderer
     * produces a comment), exactly where `dump(x)` puts it.
     */
    public function probe(string $ctx, mixed $value, mixed ...$args): mixed
    {
        echo $this->render($ctx, \array_merge([$value], \array_values($args)));

        return $value;
    }

    /**
     * The body of `dd(…)`: render, then end the request.
     *
     * `dd()` is never pruned, so it is reachable even in production; the
     * registry therefore only binds this handler while debug is on.
     *
     * @param list<mixed> $args
     */
    public function dumpAndDie(string $ctx, array $args): never
    {
        $value = \count($args) === 1 ? $args[0] : $args;

        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            \fwrite(\STDOUT, $this->cli->renderForced($value, $this->options));
        } elseif ($ctx === 'js') {
            echo $this->js->render($value, $this->options);
        } else {
            echo $this->html->render($value, $this->options);
        }
        exit(1);
    }
}
