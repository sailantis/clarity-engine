<?php
namespace Clarity;

use Clarity\Template\TemplateLocation;

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
    /** Logical template name the loader was asked for; '' when unknown. */
    public readonly string $templateName;

    /** 1-based line within that template; 0 when unknown. */
    public readonly int $templateLine;

    /** Physical file the name resolved to; '' when the loader named none. */
    public readonly string $templatePath;

     /**
      * Construct a new ClarityException.
      *
      * The location may be given as the three separate values an engine layer
      * already holds, or as a single {@see TemplateLocation} — what a directive
      * handler is handed, and can hand straight back:
      *
      * ```php
      * throw new ClarityException('cache needs a key', $at);
      * ```
      *
      * That form matters because the handler cannot know the physical path on its
      * own: the active loader is the only authority on it. Handing the whole
      * location through keeps the exception COMPLETE, so no engine layer has to
      * fill in what the thrower was never given.
      *
      * @param string $message The exception message.
      * @param string|TemplateLocation $templateName The logical name of the template, or a TemplateLocation carrying name, line and path.
      * @param int $templateLine The line number within the template.
      * @param string $templatePath The physical path to the template file.
      * @param ?\Throwable $previous The previous exception, if any.
      */
    public function __construct(
        string $message,
        string|TemplateLocation $templateName = '',
        int $templateLine = 0,
        string $templatePath = '',
        ?\Throwable $previous = null
    ) {
        if ($templateName instanceof TemplateLocation) {
            $this->templatePath = $templateName->path;
            $this->templateLine = $templateName->line;
            $this->templateName = $templateName->name;
        } else {
            $this->templateName = $templateName;
            $this->templateLine = $templateLine;
            $this->templatePath = $templatePath;
        }

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
