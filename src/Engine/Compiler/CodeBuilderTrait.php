<?php

namespace Clarity\Engine\Compiler;

use Clarity\Engine\SourceMap;

/**
 * Extracted from Clarity\Engine\Compiler to keep each file small. See that class for docs.
 */
trait CodeBuilderTrait
{
    /**
     * Scan a TEXT segment for <script>/<style> open/close tags and update $this->context
     * to reflect the escaping context that applies AFTER this text block.
     * Uses the last boundary found so that a segment containing both open and close
     * (e.g. an inline <script>…</script>) correctly ends back in 'html'.
     */
    private function updateContextFromText(string $text): void
    {
        $lastPos = -1;
        $newCtx  = null;

        // Only consider fully-formed opening tags (including the closing '>')
        // as boundaries. This avoids switching the escape context to 'js' or
        // 'css' while still inside a start-tag's attributes (which remain
        // HTML context). Partial start-tags (no closing '>') may contain
        // attribute interpolations and must be treated as HTML.
        $boundaries = [
            '/<script\b[^>]*>/i' => 'js',
            '/<\/script>/i'      => 'html',
            '/<style\b[^>]*>/i'  => 'css',
            '/<\/style>/i'       => 'html',
        ];

        foreach ($boundaries as $pattern => $ctx) {
            if (\preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                $last = \end($m[0]);
                if ($last[1] > $lastPos) {
                    $lastPos = $last[1];
                    $newCtx  = $ctx;
                }
            }
        }

        if ($newCtx !== null && $newCtx !== $this->context) {
            $this->context = $newCtx;
            $this->tokenizer->setEscapeContext($newCtx);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a raw TEXT segment to a PHP echo statement that preserves
     * the content verbatim.
     *
     * Produces a **single-line** PHP double-quoted string literal by escaping
     * all control characters (including newlines), backslashes, double-quotes,
     * and dollar signs via addcslashes().  A single-line literal needs no heredoc
     * or nowdoc, so indentation handling cannot alter the text, and there is no
     * marker that template content could collide with.
     *
     * addcslashes() escapes:
     *   \x00–\x1F  control chars (incl. \n → \n, \r → \r, \t → \t, others → octal)
     *   \x7F       DEL
     *   \          → \\
     *   "          → \"
     *   $          → \$  (prevents PHP variable interpolation)
     */
    private function textToPhp(string $text): string
    {
        return 'echo "' . addcslashes($text, "\0..\37\177\\\"$") . '";';
    }

    /**
     * Remove one line break from the start of a text segment that directly
     * follows a `{% … %}` or `{# … #}` tag.
     *
     * Without this, a tag alone on its own line leaves a blank line in the
     * output: the tag's trailing newline and the next segment's leading newline
     * both reach the output. Only one break is removed, so a deliberate blank
     * line survives as a single newline. CRLF counts as one break.
     *
     * Spaces and tabs are not trimmed. A space before the line break keeps it
     * ("\n" is removed, " \n" is kept), which gives authors a way to keep it.
     *
     * `{{ … }}` is not affected: the caller applies this only after a block or
     * comment tag.
     */
    private static function stripOneLineBreakAfterTag(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        // CRLF as one break, so a Windows template behaves like a Unix one and
        // never leaves a stray "\n" behind (which would be the blank line back).
        if (\str_starts_with($text, "\r\n")) {
            return \substr($text, 2);
        }
        if ($text[0] === "\n" || $text[0] === "\r") {
            return \substr($text, 1);
        }
        return $text;
    }

    /**
     * Wrap the compiled render body in the generated class.
     *
     * @param string $className Generated class name.
     * @param string $body      PHP render body statements.
     * @return string Complete PHP source for the class file, without the leading
     *                `<?php`. It ends with `return '{className}';`.
     */
    private function buildClass(string $className, string $body): string
    {
        // Indent the body
        $indented = implode(
            "\n",
            array_map(fn(string $l) => $l !== '' ? '        ' . $l : '', explode("\n", $body))
        );

        $depsExport  = var_export($this->dependencies, true);
        $filesExport = var_export($this->sourceFiles, true);
        $pathsExport = var_export($this->sourcePaths, true);
        // Compact packed form (see SourceMap): one short single-quoted string
        // instead of a nested array literal per range.
        $mapExport    = SourceMap::packedLiteral($this->sourceMap);
        $debugFlag    = $this->debugMode ? 'true' : 'false';
        $versionInt   = self::COMPILER_VERSION;
        $policyDigest = $this->policy->digest();

        // Detect which registries are actually referenced in the compiled body.
        // The constructor always accepts both (so the caller stays simple), but
        // render() only unpacks the one(s) that are actually used.
        $usesFunctions = \str_contains($body, '$__c_fn');
        $usesServices  = \str_contains($body, '$__c_sv');

        // The PROPERTIES are named `functions` / `services` so a directive handler
        // can read them as `$this->services['key']` — a form that works in every
        // position the compiler emits a handler's snippet, because the generated
        // closures are non-static and therefore inherit `$this`.  The LOCALS stay
        // `$__c_fn` / `$__c_sv` because that is the spelling the registry and
        // service contract is written in (see {@see \Clarity\Engine\Tokenizer\CallableTrait}).
        $unpacks = '';
        if ($usesFunctions) {
            $unpacks .= "                \$__c_fn = \$this->functions;\n";
        }
        if ($usesServices) {
            $unpacks .= "                \$__c_sv = \$this->services;\n";
        }

        // A `vars()` call inside a `{% for %}` or a macro body gathers the
        // variables the compiler bound to PHP LOCALS, which are not `$__c_va`
        // entries — see {@see \Clarity\Engine\Tokenizer::setDynamicBindings()}.
        // Whether the body does that is a property of the TEMPLATE, not the
        // policy, so it is detected from the compiled body (which is also how the
        // registries above are detected) rather than from $this->dynamicBindings,
        // which is only the scope left over at the END of the compile.
        $usesBindings = \str_contains($body, '$__c_dyn');
        if ($usesBindings) {
            $unpacks .= "                \$__c_dyn = true;\n";
        }

        // A policy that grants `phpVariables` seeds the render scope into PHP
        // locals, so a template variable is the same thing in `{{ title }}` and
        // `{% php echo $title; %}`.
        //
        // The internals are bound FIRST so EXTR_SKIP protects them: a view
        // variable named `__c_fn` cannot shadow the callable registry, and `this`
        // cannot be handed to the engine (which PHP would fatal on anyway).
        //
        // EXTR_SKIP only shields those names because they are bound above it;
        // the guard is the binding order, not the prefix, which is why the
        // prefix rule and the ordering are both needed.
        //
        // extract() on the parameter itself needs no copy — `$__c_va` stays a valid
        // array for the explicit `$__c_va['x']` escape hatch — and runs once per render.
        if ($this->seedsScope) {
            $unpacks .= "                extract(\$__c_va, EXTR_SKIP);\n";
        }

        return $this->withStrictTypes(<<<PHP
        // @generated by Clarity\Engine\Compiler - do not edit.
        class {$className}
        {
            // dependencies: templateName => revision, for cache invalidation
            public static array \$dependencies = {$depsExport};

            // sourceFiles: logical template names, indexed by \$sourceMap
            public static array \$sourceFiles = {$filesExport};

            // sourcePaths: physical file per \$sourceFiles entry, or '' — same index
            public static array \$sourcePaths = {$pathsExport};

            // sourceMap: packed "lineDelta,fileIndex,tplLineDelta;" ranges
            public static string \$sourceMap = {$mapExport};

            // debugCompiled: whether the compiler was in debug mode when this class was generated
            public static bool \$debugCompiled = {$debugFlag};

            // policyDigest: fingerprint of the policy this class was compiled under
            public static string \$policyDigest = '{$policyDigest}';

            // compilerVersion: the compiler that produced this class
            public static int \$compilerVersion = {$versionInt};

            // renderBodyLine: cache-file line where the render body starts
            public static int \$renderBodyLine = 0;

            /**
             * @param array \$functions Callable registry (name => callable)
             * @param array \$services  Service registry (name => mixed)
             */
            public function __construct(private array \$functions, private array \$services) {}

            /**
             * Render the template with the given variables.
             *
             * @param array \$__c_va Template variables (name => value)
             * @return string Rendered output
             */
            public function render(array \$__c_va): string
            {
        {$unpacks}                ob_start();
                \$__c_ob_level = ob_get_level();
                try {
        /* @@CLARITY_BODY_LINE@@ */
        {$indented}
                    return (string) ob_get_clean();
                } catch (\Throwable \$__c_e) {
                    while (ob_get_level() >= \$__c_ob_level) {
                        ob_end_clean();
                    }
                    throw \$__c_e;
                }
            }
        }

        return '{$className}';
        PHP);
    }

    /**
     * Put a `declare(strict_types=1);` at the top of the generated file when the
     * policy `strictTypes` rule is on.
     *
     * The declaration is per-FILE, so this is the only way a template can carry
     * it — the engine emits the file (`Cache::writeAndLoad()` prepends `<?php`).
     * Prepending it here rather than in the Cache keeps the compiler the single
     * owner of the emitted code, and keeps the line arithmetic in one place:
     * `$renderBodyLine` is measured relative to this code, and this adds its
     * lines at the top, so the marker (and the property it bakes) move with them
     * and are counted correctly. A file that declared strict types only after the
     * body had shifted would map every runtime error to the wrong template line.
     *
     * The declaration must be the first statement, so it is placed before the
     * generated class. PHP allows a comment before it, so only its position
     * relative to other code matters.
     */
    private function withStrictTypes(string $code): string
    {
        if (!$this->policy->strictTypes()) {
            return $code;
        }

        return "declare(strict_types=1);\n" . $code;
    }
}
