<?php

declare(strict_types=1);

namespace Clarity\Debug;

/**
 * Thrown by dd() when {@see DumpOptions::haltWithException()} is set. It
 * carries the rendered dump, so the host can send it as the response.
 *
 * The host must catch this exception at the request boundary. If nothing
 * catches it, it propagates like any other uncaught error.
 */
final class DumpHaltException extends \RuntimeException
{
    /**
     * @param string $context The escape context of the dd() call: 'html', 'js' or 'css'.
     * @param string $output  The rendered dump for that context.
     */
    public function __construct(
        public readonly string $context,
        public readonly string $output,
    ) {
        parent::__construct('dd() halted execution.');
    }
}
