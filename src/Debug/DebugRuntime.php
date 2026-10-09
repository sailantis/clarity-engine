<?php

declare(strict_types=1);

namespace Clarity\Debug;

use Clarity\Engine\Registry;

/**
 * Holds the debug state of an engine: the dump renderers, the DumpOptions, the
 * event bus and the optional HTML panel.
 *
 * The engine creates a DebugRuntime when debug is enabled and registers its
 * handlers on the {@see Registry}. Every `dump()` and `dd()` call and every
 * `{{ x |> dump }}` probe is routed through this object, so all debug output
 * is produced by the renderers in this namespace. The registry holds no debug
 * behaviour of its own.
 */
final class DebugRuntime
{
    private HtmlDumpRenderer $html;
    private CliDumpRenderer $cli;
    private JsDumpRenderer $js;
    private CssDumpRenderer $css;

    /** The event bus. */
    public readonly DebugEventBus $bus;

    /** The HTML panel, or null unless the showPanel option is enabled. When set, it is subscribed to $bus. */
    public readonly ?HtmlDebugPanel $panel;

    public function __construct(public readonly DumpOptions $options)
    {
        $this->html = new HtmlDumpRenderer();
        $this->cli  = new CliDumpRenderer();
        $this->js   = new JsDumpRenderer();
        $this->css  = new CssDumpRenderer();
        $this->bus  = new DebugEventBus();

        $this->panel = $options->getShowPanel() ? new HtmlDebugPanel() : null;
        if ($this->panel !== null) {
            $this->bus->subscribe($this->panel);
        }
    }

    /**
     * Registers this runtime's dump and dd handlers on a registry.
     *
     * `dump()` goes through one formatter. It renders for the escape context
     * ('html', 'js' or 'css') that the compiler passes as the first argument,
     * and it masks the keys listed in {@see DumpOptions::maskKeys()}.
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
     * Renders a value for the given escape context. Used by `dump()` and by the
     * `{{ x |> dump }}` probe. A single argument is rendered as-is; several
     * arguments are rendered as one list.
     *
     * @param list<mixed> $args
     */
    public function render(string $ctx, array $args): string
    {
        $value = \count($args) === 1 ? $args[0] : $args;

        return match ($ctx) {
            'js'   => $this->js->render($value, $this->options),
            'css'  => $this->css->render($value, $this->options),
            default => $this->html->render($value, $this->options),
        };
    }

    /**
     * Pass-through probe behind the filter form `{{ x |> dump }}`. Emits the
     * dump at the pipe position and returns the piped value unchanged, so later
     * filters still receive the original value.
     *
     * The dump is echoed rather than returned, because the return value is what
     * continues down the pipe: `{{ x |> dump |> length }}` must measure x. In JS
     * and CSS contexts the dump appears as a comment at the same position where
     * `dump(x)` places it.
     */
    public function probe(string $ctx, mixed $value, mixed ...$args): mixed
    {
        echo $this->render($ctx, \array_merge([$value], \array_values($args)));

        return $value;
    }

    /**
     * Behind `dd()`: renders the value, then ends the request with exit code 1.
     *
     * On the CLI, output goes to STDERR, the same stream `dump()` uses, so STDOUT
     * stays free for program output.
     *
     * With {@see DumpOptions::haltWithException()} set, the value is rendered for
     * the context and thrown as a {@see DumpHaltException} instead, on every SAPI.
     * The process keeps running, so the host decides what to do with the output.
     *
     * The compiler does not prune `dd()`, so the call remains in production
     * templates. This handler is bound only while debug is on; otherwise the
     * registry raises an error.
     *
     * @param list<mixed> $args
     */
    public function dumpAndDie(string $ctx, array $args): never
    {
        if ($this->options->getHaltWithException()) {
            throw new DumpHaltException(
                match ($ctx) {
                    'js', 'css' => $ctx,
                    default     => 'html',
                },
                $this->render($ctx, $args)
            );
        }

        $value = \count($args) === 1 ? $args[0] : $args;

        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            \fwrite(\STDERR, $this->cli->renderForced($value, $this->options));
        } elseif ($ctx === 'js') {
            echo $this->js->render($value, $this->options);
        } elseif ($ctx === 'css') {
            echo $this->css->render($value, $this->options);
        } else {
            echo $this->html->render($value, $this->options);
        }
        exit(1);
    }
}
