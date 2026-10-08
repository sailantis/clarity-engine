<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Engine\Policy;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;

/**
 * The `strictTypes` rule, end to end.
 *
 * Two things are tested and they are different questions:
 *
 *  - EMISSION: a strict template's file declares strict types, as the first
 *    statement, and a weak template's does not. The declaration is per-FILE, and
 *    the engine emits the file, so this is the whole mechanism.
 *  - ACCOUNTING: the declaration adds a line at the top of that file, and
 *    `$renderBodyLine` is baked relative to it. If the two ever disagree, a
 *    `TypeError` inside a strict template is reported against the wrong template
 *    line — which looks like a working feature right up until someone has to read
 *    an error message.
 *
 * `strictTypes` is on in every preset, so "weak" here is a policy that denies it.
 */
class StrictTypesTest extends BaseTestCase
{
    private static function strict(): TestClarityEngine
    {
        // The default policy IS strict, so this is the ordinary engine.
        return TestClarityEngine::withPolicy(Policy::default());
    }

    private static function weak(): TestClarityEngine
    {
        return TestClarityEngine::withPolicy(Policy::default()->denyRule('strictTypes'));
    }

    private static function withFilter(TestClarityEngine $engine): TestClarityEngine
    {
        $engine->addFilter('shout', static fn(string $s): string => 'STRICT:' . $s);
        return $engine;
    }

    /** The physical cache file the engine compiled and loaded for $view. */
    private static function compiledFile(TestClarityEngine $engine, string $view): string
    {
        $prop = new \ReflectionProperty($engine, 'cache');
        $prop->setAccessible(true);

        /** @var \Clarity\Engine\Cache $cache */
        $cache     = $prop->getValue($engine);
        $className = $cache->getLoadedClassName($view);

        self::assertIsString($className, 'the template must be compiled and loaded');

        return (string) (new \ReflectionClass($className))->getFileName();
    }

    // =========================================================================
    // Emission
    // =========================================================================

    public function testAStrictTemplateDeclaresStrictTypesAsItsFirstStatement(): void
    {
        self::tpl('st_decl_on', '{{ name }}');

        $engine = self::strict();
        $engine->renderPartial('st_decl_on', ['name' => 'x']);

        $lines = \explode("\n", (string) \file_get_contents(self::compiledFile($engine, 'st_decl_on')));

        // `<?php` then the declaration, with nothing between: a comment above it
        // would be a fatal "strict_types declaration must be the very first
        // statement", which is why the generator comment sits below it.
        $this->assertSame('<?php', \trim($lines[0]));
        $this->assertSame('declare(strict_types=1);', \trim($lines[1]));
    }

    public function testAnUnconfiguredEngineIsStrict(): void
    {
        // The default is the sandbox, and the sandbox is strict: this is the
        // whole of the "on for all modes" decision, asserted where a user would
        // meet it — an engine with no policy at all.
        self::tpl('st_engine_default', '{{ name }}');

        $engine = new TestClarityEngine([
            'viewPath'  => \Clarity\Tests\TestEnvironment::viewDir(),
            'cachePath' => \Clarity\Tests\TestEnvironment::cacheDir(),
            'extension' => 'clarity.html',
        ]);

        $engine->renderPartial('st_engine_default', ['name' => 'x']);

        $source = (string) \file_get_contents(self::compiledFile($engine, 'st_engine_default'));
        $this->assertStringContainsString('declare(strict_types=1)', $source);
    }

    public function testAWeakTemplateDoesNotDeclareStrictTypes(): void
    {
        self::tpl('st_decl_off', '{{ name }}');

        $engine = self::weak();
        $engine->renderPartial('st_decl_off', ['name' => 'x']);

        $source = (string) \file_get_contents(self::compiledFile($engine, 'st_decl_off'));

        $this->assertStringNotContainsString('declare(strict_types=1)', $source);
    }

    // =========================================================================
    // Behaviour
    // =========================================================================

    public function testAWeakTemplateCoercesAMismatchedFilterArgument(): void
    {
        // The behaviour the rule trades away. Pinned here so the pair of
        // tests reads as one decision rather than two unrelated assertions.
        self::tpl('st_weak_arg', '{{ 42 |> shout }}');

        $this->assertSame('STRICT:42', self::withFilter(self::weak())->renderPartial('st_weak_arg'));
    }

    public function testAStrictTemplateThrowsOnAMismatchedFilterArgument(): void
    {
        self::tpl('st_strict_arg', '{{ 42 |> shout }}');

        $this->expectException(ClarityException::class);
        self::withFilter(self::strict())->renderPartial('st_strict_arg');
    }

    public function testAStrictTemplateStillRendersANonStringOutput(): void
    {
        // The output boundary is NOT a call boundary: `htmlspecialchars((string)(…))`
        // is how a number is printed at all, so the rule must not remove that
        // cast or a strict template could not render `{{ 42 }}`.
        self::tpl('st_strict_out', '{{ 42 }}|{{ items |> length }}');

        $this->assertSame(
            '42|3',
            self::strict()->renderPartial('st_strict_out', ['items' => [1, 2, 3]])
        );
    }

    public function testAStrictTemplateStillRendersAMatchingFilterArgument(): void
    {
        self::tpl('st_strict_ok', "{{ 'x' |> shout }}");

        $this->assertSame('STRICT:x', self::withFilter(self::strict())->renderPartial('st_strict_ok'));
    }

    // =========================================================================
    // A numeric string is still refused — the cast is the remedy, not a cast
    // inside the filter
    // =========================================================================

    /**
     * The numeric built-ins do NOT cast their input, and this is deliberate.
     *
     * Every `(float)`/`(int)` wrapper was removed from the built-in filter
     * templates on purpose (see the CHANGELOG entry that removed them): a filter
     * that silently accepts the wrong type defeats the reason `strictTypes` is on.
     * `number` is the one sanctioned exception, because `number_format()` has no
     * `string` overload at all.
     *
     * This test exists so that a future "fix" for the error below has to DELETE a
     * test to land. The answer to `round` refusing a string is an explicit cast at
     * the call site, which states the conversion rather than hiding it:
     *
     *     {{ x |> float |> round(2) }}
     */
    public function testANumericStringIsStillRefusedByTheNumericFilters(): void
    {
        self::tpl('st_numstr', "{{ '3.7' |> round(2) }}");

        $this->expectException(ClarityException::class);
        self::strict()->renderPartial('st_numstr');
    }

    /**
     * The complement: the cast makes the same call work, in strict AND in weak
     * mode, because the conversion is now stated by the template rather than
     * inferred by PHP. One assertion covers both modes because the cast means the
     * mode no longer decides the answer.
     */
    public function testAnExplicitCastMakesTheSameCallWorkInBothModes(): void
    {
        self::tpl('st_numstr_cast', "{{ '3.7' |> float |> round(2) }}");

        $this->assertSame('3.7', self::strict()->renderPartial('st_numstr_cast'));
        $this->assertSame('3.7', self::weak()->renderPartial('st_numstr_cast'));
    }

    /**
     * `number` keeps its cast, so a pipeline whose previous step yields a string
     * still works — the exception the CHANGELOG calls out. Pinned next to the rule
     * it is an exception to, so the two read as one decision.
     */
    public function testNumberStillAcceptsAStringBecauseItCannotWorkOtherwise(): void
    {
        self::tpl('st_number_cast', "{{ ' 3.14 ' |> trim |> number(1) }}");

        $this->assertSame('3.1', self::strict()->renderPartial('st_number_cast'));
    }

    // =========================================================================
    // Error accounting — the declaration must not shift the mapping
    // =========================================================================

    public function testAStrictRuntimeErrorStillNamesTheCorrectTemplateLine(): void
    {
        // The error is on template line 3, and the declaration adds a line to the
        // compiled file above the body. If `$renderBodyLine` were not measured
        // against the code that actually carries the declaration, this would
        // report line 2 or 4.
        self::tpl('st_line', \implode("\n", [
            'first',
            'second',
            '{{ 42 |> shout }}',
            'fourth',
        ]));

        try {
            self::withFilter(self::strict())->renderPartial('st_line');
            $this->fail('the mismatched argument must throw');
        } catch (ClarityException $e) {
            $this->assertSame(3, $e->templateLine);
            $this->assertSame('st_line', $e->templateName);
        }
    }

    public function testAWeakRuntimeErrorNamesTheSameTemplateLine(): void
    {
        // The counterpart: adding the declaration must not change the line a
        // non-strict template already reported.
        self::tpl('st_line_weak', \implode("\n", [
            'first',
            'second',
            '{{ 42 |> shout }}',
            'fourth',
        ]));

        $engine = self::weak();
        $engine->addFilter('shout', static function (string $s): string {
            throw new \RuntimeException('boom');
        });

        try {
            $engine->renderPartial('st_line_weak');
            $this->fail('the filter must throw');
        } catch (ClarityException $e) {
            $this->assertSame(3, $e->templateLine);
        }
    }

    // =========================================================================
    // Config-file policies start strict too
    // =========================================================================

    public function testAPolicyBuiltFromConfigIsStrict(): void
    {
        // The reason the default lives on `restricted()` rather than on
        // `default()`: `fromArray()` starts from `restricted()`, so a config file
        // that names only one unrelated rule must not silently land in weak mode.
        self::tpl('st_from_array', '{{ 42 |> shout }}');

        $engine = TestClarityEngine::withPolicy(['rules' => ['methodCalls' => true]]);
        $engine->addFilter('shout', static fn(string $s): string => 'STRICT:' . $s);

        $this->expectException(ClarityException::class);
        $engine->renderPartial('st_from_array');
    }

    // =========================================================================
    // The rule drives recompilation
    // =========================================================================

    public function testTogglingStrictTypesRecompilesACachedTemplate(): void
    {
        // Same template, same cache directory, one rule difference: the
        // digest has to notice, or the weak class compiled first would keep
        // serving after the rule is granted.
        self::tpl('st_recompile', '{{ 42 |> shout }}');

        $this->assertSame(
            'STRICT:42',
            self::withFilter(self::weak())->renderPartial('st_recompile')
        );

        $this->expectException(ClarityException::class);
        self::withFilter(self::strict())->renderPartial('st_recompile');
    }
}
