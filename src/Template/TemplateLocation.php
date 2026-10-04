<?php
namespace Clarity\Template;

/**
 * Where a template construct sits: the logical name it was compiled under, the
 * line within it, and the physical file that name resolved to.
 *
 * A custom directive handler receives one of these as its second argument, so it
 * can raise a {@see \Clarity\ClarityException} that is COMPLETE — naming the
 * template AND the file an editor can open — instead of only the logical name:
 *
 * ```php
 * $engine->addDirective('cache', function (string $rest, TemplateLocation $at, callable $processExpr): string {
 *     if ($rest === '') {
 *         throw new ClarityException('cache needs a key', $at);
 *     }
 *     return "\$__c_sv['cache']->begin({$processExpr($rest)});";
 * });
 * ```
 *
 * The handler cannot derive this itself: the active loader is the only authority
 * on the physical path, and the compiler is the only layer holding it. Carrying
 * the path here is what keeps a handler's exception from being re-wrapped with
 * information the handler was never given.
 *
 * `$path` is `''` when the active loader has no file to name ({@see ArrayLoader},
 * {@see StringLoader}, a database loader). The logical name is then all there is,
 * exactly as in {@see \Clarity\ClarityException}.
 */
final class TemplateLocation
{
    /**
     * @param string $name Logical template name being compiled (`host`, `part`, or
     *                     `<owner>#macro#<macro>` for a macro body).
     * @param int    $line 1-based line within that template.
     * @param string $path Physical file the name resolved to, or `''` when the
     *                     active loader named none.
     */
    public function __construct(
        public readonly string $name,
        public readonly int $line,
        public readonly string $path = '',
    ) {
    }
}
