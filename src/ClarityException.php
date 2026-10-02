<?php
namespace Clarity;

/**
 * Exception thrown when a Clarity template fails to compile or render.
 *
 * Carries where the failure belongs, in two forms:
 *
 *  - `$templateName` — the LOGICAL name the loader was asked for
 *    (`pages/home`, `generated-docs::intro`).  Always known.
 *  - `$templatePath` — the PHYSICAL file that name resolved to, when the active
 *    loader is file-backed.  Empty for an array/string/database loader, which
 *    has no path to give.
 *
 * together with `$templateLine` within that template.
 *
 * The location is also written onto the inherited `$file` / `$line`, so
 * `getFile()` / `getLine()` — and everything built on them, beginning with the
 * uncaught-fatal line `Fatal error: Uncaught … in <file>:<line>` and xdebug's
 * develop-mode error page — name the template rather than the engine frame that
 * threw.  `$templatePath` is preferred there, because it is the form an IDE can
 * open; the logical name is the fallback.  Those two properties are `protected`
 * and their getters are `final`, so assigning them is the only way to relocate
 * the location; Smarty does the same thing for the same reason.  The trace is
 * deliberately left alone: the real call path through the engine stays useful,
 * and Clarity's own runtime mapping walks it.
 */
class ClarityException extends \RuntimeException
{
     /**
      * Construct a new ClarityException.
      * @param string $message The exception message.
      * @param string $templateName The logical name of the template.
      * @param int $templateLine The line number within the template.
      * @param string $templatePath The physical path to the template file.
      * @param ?\Throwable $previous The previous exception, if any.
      */
    public function __construct(
        string $message,
        public readonly string $templateName = '',
        public readonly int $templateLine = 0,
        public string $templatePath = '',
        ?\Throwable $previous = null
    ) {
        $this->relocate();
        parent::__construct($message, 0, $previous);
    }

    /**
     * Point `getFile()` / `getLine()` at the template.
     */
    private function relocate(): bool
    {
        // The physical path first: that is the one an IDE, xdebug's develop-mode
        // error page or a Sentry frame can open.  The logical name is the
        // fallback, and is still far more useful than the engine frame.
        $where = $this->templatePath ?: $this->templateName;

        // No location at all: leave the exception reporting where it was thrown,
        // rather than inventing one.
        if (empty($where)) {
            return false;
        }

        $this->file = $where;
        $this->line = $this->templateLine;
        return true;
    }
}
