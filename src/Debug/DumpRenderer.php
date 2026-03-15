<?php

declare(strict_types=1);

namespace Clarity\Debug;

interface DumpRenderer
{
    /**
     * Render $value for display.
     *
     * May have side effects (e.g. writing to STDERR for the CLI renderer).
     * Returns a string to be concatenated into the template output; returns ''
     * when the output was sent directly to STDERR.
     */
    public function render(mixed $value, DumpOptions $opts): string;
}
