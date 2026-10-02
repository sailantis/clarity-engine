<?php

namespace Clarity\Engine\Compiler;

use Clarity\Engine\SourceMap;

/**
 * Extracted from Clarity\Engine\Compiler to keep each file small. See that class for docs.
 */
trait CodeBuilderTrait
{

    /**
     * Compile {% endfor %} → the correct PHP closing keyword based on the
     * matching opening loop (native `for` vs `foreach`).
     */
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
     * and dollar signs via addcslashes().  This avoids three problems the
     * previous nowdoc approach had:
     *
     *  1. PHP 7.3+ indented nowdoc: the 8-space class-body indentation added
     *     by buildClass() was silently stripped from the start of every content
     *     line, mangling template text that relied on leading spaces.
     *  2. Marker escape: a time-derived uniqid() marker could theoretically
     *     collide with content the template author controls.
     *  3. Per-segment uniqid() syscall overhead.
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
     * Remove ONE line break from the start of a text segment that directly
     * follows a `{% … %}` or `{# … #}` tag.
     *
     * WHY THIS EXISTS: a directive alone on its own line used to leave a blank
     * line in the output. Compiling
     *
     *     X
     *     {% for j in 0..2 %}
     *       <s>{{ j }}</s>
     *     {% endfor %}
     *     Y
     *
     * emitted `X\n` + `\n  <s>` per iteration; the tag's own trailing newline
     * doubled up with the next iteration's leading newline into a
     * whitespace-only line. On the competition's 200-item benchmark page that
     * was 2403 blank lines — 44% of the output's 5418 lines — for a page whose
     * visible content is the leanest of the six engines compared.
     *
     * WHY A LINE BREAK AND NOT A DEFAULT: Twig and Stempler both get this for
     * free and neither uses a whitespace-control operator to do it. Twig's lexer
     * ends its block-tag pattern with `%}\n?` and its comment pattern with
     * `#}\n?` — unconditional, exactly ONE newline. Stempler inherits the same
     * effect from PHP itself, whose `?>` consumes one following line break
     * (`?>` is compiled by PHP's lexer as `?>` followed by an optional line
     * break). Both were measured, not assumed: PHP eats a single `\n`/`\r\n`
     * after `?>`, leaves a second newline, and is BLOCKED by a space first, so
     * `" \n"` is untouched. This mirrors that rule so a Clarity template needs
     * no edits and no operator to match the ecosystem's expectation.
     *
     * WHY `{{ … }}` IS EXCLUDED: neither `?>`'s rule nor Twig's applies after an
     * output tag (`}}` has no `\n?` in Twig's lexer, and Stempler emits a call
     * there rather than a tag boundary). Applying it to prints would silently
     * delete line breaks the other engines keep, trading one divergence for
     * another.
     *
     * WHAT IT DOES NOT DO: it does not trim spaces or tabs, so indentation before
     * a `{%` is preserved and an author can keep the line break by putting a
     * space in front of it ("\n" is eaten, " \n" is not) — the same escape hatch
     * PHP and Twig provide. It also only ever removes one break, so a deliberate
     * blank line survives as one newline.
     *
     * This changes the PHP that templates compile to, so COMPILER_VERSION is
     * bumped and Cache::isFresh() recompiles every existing template.
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
     * Wrap the compiled render body in a class with docblock.
     *
     * @param string $className Generated class name.
     * @param string $body      PHP render body statements.
     * @return string Complete PHP class code (without leading <?php).
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

        $unpacks = '';
        if ($usesFunctions) {
            $unpacks .= "                \$__c_fn = \$this->__c_fn;\n";
        }
        if ($usesServices) {
            $unpacks .= "                \$__c_sv = \$this->__c_sv;\n";
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
        // array for the explicit `$__c_va['x']` escape hatch — and costs ~180 ns
        // once per render.
        if ($this->seedsScope) {
            $unpacks .= "                extract(\$__c_va, EXTR_SKIP);\n";
        }

        return <<<PHP
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
             * @param array \$__c_fn Callable registry (name => callable)
             * @param array \$__c_sv Service registry (name => mixed)
             */
            public function __construct(private array \$__c_fn, private array \$__c_sv) {}

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
        PHP;
    }
}
