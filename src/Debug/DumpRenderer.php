<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Renders a value as debug output.
 *
 * Implementations may have side effects: the CLI renderer writes to STDERR.
 * render() returns the string to insert into the template output, or '' when
 * the output was written directly to STDERR.
 */
interface DumpRenderer
{
    public function render(mixed $value, DumpOptions $opts): string;
}

