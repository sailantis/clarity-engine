<?php
namespace Clarity\Template;

/**
 * Where a template construct sits: the logical name it was compiled under, the
 * line within it, and the physical file that name resolved to.
 *
 * A custom directive handler receives one of these as its second argument. It can
 * pass this to a {@see \Clarity\ClarityException} so the error names both the
 * template and the file:
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
 * The handler cannot derive the path itself, because only the active loader knows
 * the physical file.
 *
 * `$path` is `''` when the active loader has no file to name ({@see ArrayLoader},
 * {@see StringLoader}, a database loader). In that case the logical name is all
 * that is available, as with {@see \Clarity\ClarityException} alone.
 */
final class TemplateLocation
{
    /**
     * @param string $name Logical name being compiled: the root template, an included
     *                     template, or `<owner>#macro#<macro>` for a macro body.
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
