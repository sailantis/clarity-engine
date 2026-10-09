<?php
namespace Clarity;

use Clarity\Template\TemplateLocation;

/**
 * Exception thrown when a Clarity template fails to compile or render.
 *
 * Records where the failure occurred:
 *
 *  - `$templateName`: the logical name the loader was asked for (`pages/home`,
 *    `generated-docs::intro`). Set whenever it is known.
 *  - `$templatePath`: the physical file that name resolved to. Set only for
 *    file-backed loaders; empty for array, string, and database loaders.
 *  - `$templateLine`: the 1-based line within that template.
 *
 * The location is also written to the inherited `$file` and `$line`, so
 * `getFile()` and `getLine()` point at the template instead of the engine frame
 * that threw. `$templatePath` is preferred there because IDEs and xdebug can
 * open it; `$templateName` is the fallback. The properties are `protected` and
 * their getters are `final`, so reassigning them is the only way to relocate
 * the exception. The stack trace is left unchanged, because Clarity's runtime
 * source mapping walks it.
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
     * The location may be given as three separate values, or as a single
     * {@see TemplateLocation}, which is what a directive handler receives and can
     * pass straight back:
     *
     * ```php
     * throw new ClarityException('cache needs a key', $at);
     * ```
     *
     * A handler cannot determine the physical path itself, because the active
     * loader decides it. Passing the whole location keeps the exception complete,
     * so no engine layer needs to fill in missing values.
     *
     * @param string $message The exception message.
     * @param string|TemplateLocation $templateName The logical template name, or a TemplateLocation with name, line, and path.
     * @param int $templateLine The 1-based line number within the template.
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
     * Point `getFile()` and `getLine()` at the template.
     *
     * @return bool True when a location was applied, false when none is known.
     */
    private function relocate(): bool
    {
        // Prefer the physical path, which IDEs, xdebug, and Sentry can open.
        // Fall back to the logical name.
        $where = $this->templatePath ?: $this->templateName;

        // No location known: keep the original throw site rather than inventing one.
        if (empty($where)) {
            return false;
        }

        $this->file = $where;
        $this->line = $this->templateLine;
        return true;
    }
}
