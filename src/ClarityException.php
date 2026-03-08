<?php
namespace Clarity;

/**
 * Exception thrown when a Clarity template fails to compile or render.
 *
 * Carries the original source template file and the line number within
 * that template, allowing error messages to point at the `.clarity.html`
 * source rather than the compiled PHP cache file.
 */
class ClarityException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $templateFile = '',
        public readonly int $templateLine = 0,
        ?\Throwable $previous = null
    ) {
        $location = $templateFile !== ''
            ? " in {$templateFile}" . ($templateLine > 0 ? " on line {$templateLine}" : '')
            : '';

        parent::__construct($message . $location, 0, $previous);
    }
}
