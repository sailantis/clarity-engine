<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Engine\Policy;
use Clarity\Template\ArrayLoader;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;
use Clarity\Tests\TestEnvironment;

/**
 * Each capability, compiled — the policy's effect on what a template can reach.
 *
 * The point of splitting the old boolean is that a grant is INDEPENDENT: turning
 * on `methodCalls` must not turn on `rawPhp`, and an allowlist must not turn on
 * anything at all beyond the names it lists.  Every test below therefore grants
 * one thing and asserts the neighbours are still refused.
 */
class PolicyCapabilityTest extends BaseTestCase
{
    private static function engine(Policy $policy): TestClarityEngine
    {
        return TestClarityEngine::withPolicy($policy);
    }

    private static function makeObject(): object
    {
        return new class
        {
            public string $label = 'L';
            public function name(): string
            {
                return 'Alice';
            }
            public function greet(string $who): string
            {
                return 'Hi ' . $who;
            }
            public function self(): self
            {
                return $this;
            }
        };
    }

    // =========================================================================
    // rawPhp
    // =========================================================================

    public function testRawPhpIsRefusedWithoutTheCapability(): void
    {
        self::tpl('pc_php_off', "{% php echo 'x'; %}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/'rawPhp' capability/");
        self::engine(Policy::default()->allowCapability('methodCalls'))->renderPartial('pc_php_off');
    }

    public function testRawPhpIsGrantedByItsOwnCapability(): void
    {
        self::tpl('pc_php_on', "{% php echo 'x'; %}");

        $this->assertSame('x', self::engine(Policy::default()->allowCapability('rawPhp'))->renderPartial('pc_php_on'));
    }

    /**
     * The refusal is a diagnostic ABOUT A SOURCE LINE, so it has to name that
     * line.  It is raised during the pre-scan, before any segment is compiled,
     * which is precisely the case the mapping cursor cannot serve.
     */
    public function testRawPhpRefusalPointsAtTheOffendingTemplateLine(): void
    {
        self::tpl('pc_php_line', implode("\n", [
            'first',
            'second',
            "{% php echo 'x'; %}",
            'fourth',
        ]));

        try {
            self::engine(Policy::restricted())->renderPartial('pc_php_line');
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertSame('pc_php_line', $e->templateName);
            $this->assertSame(3, $e->templateLine);

            // A file-backed loader also resolves the physical path, and that is
            // what getFile() reports — the form an IDE or xdebug can open.
            $this->assertSame(
                str_replace('\\', '/', self::normalizedSourcePath('pc_php_line')),
                str_replace('\\', '/', $e->templatePath)
            );
            $this->assertSame($e->templatePath, $e->getFile());
            $this->assertSame(3, $e->getLine());
        }
    }

    /**
     * The path is only filled when the loader HAS one.  An array-backed template
     * has no file to point at, so `templatePath` stays empty and `getFile()`
     * falls back to the logical name — which is still better than the engine
     * frame, and must not be reported as some invented path.
     */
    public function testTemplatePathStaysEmptyForANonFileLoader(): void
    {
        $engine = new TestClarityEngine([
            'viewPath'  => TestEnvironment::viewDir(),
            'cachePath' => TestEnvironment::cacheDir(),
            'extension' => 'clarity.html',
            'policy'    => Policy::restricted(),
        ]);
        $engine->setLoader(new ArrayLoader([
            'pc_php_array' => implode("\n", ['one', "{% php echo 'x'; %}"]),
        ]));

        try {
            $engine->renderPartial('pc_php_array');
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertSame('pc_php_array', $e->templateName);
            $this->assertSame(2, $e->templateLine);
            $this->assertSame('', $e->templatePath);
            $this->assertSame('pc_php_array', $e->getFile());
        }
    }

    /**
     * A namespaced name is routed before it is resolved.  The router strips the
     * `domain::` prefix, so resolving the raw name against the FileLoader would
     * produce a path literally containing `domain::`.
     */
    public function testTemplatePathIsResolvedForANamespacedTemplate(): void
    {
        $namespaceDir = TestEnvironment::viewDir() . DIRECTORY_SEPARATOR . 'ns';
        if (!is_dir($namespaceDir)) {
            mkdir($namespaceDir, 0755, true);
        }
        file_put_contents(
            $namespaceDir . DIRECTORY_SEPARATOR . 'page.clarity.html',
            implode("\n", ['one', "{% php echo 'x'; %}"])
        );

        $engine = new TestClarityEngine([
            'viewPath'  => TestEnvironment::viewDir(),
            'cachePath' => TestEnvironment::cacheDir(),
            'extension' => 'clarity.html',
            'policy'    => Policy::restricted(),
            'namespaces' => ['ns' => $namespaceDir],
        ]);

        try {
            $engine->renderPartial('ns::page');
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertSame('ns::page', $e->templateName);
            $this->assertSame(2, $e->templateLine);
            $this->assertStringEndsWith(
                'ns/page.clarity.html',
                str_replace('\\', '/', $e->templatePath)
            );
            $this->assertStringNotContainsString('ns::', $e->templatePath);
        }
    }

    /**
     * An inlined include is compiled with its markers copied into the host's
     * source, so the refusal must name the INCLUDED file — reporting the host
     * would send the author to the `include` line instead of the tag.
     */
    public function testRawPhpRefusalInAnIncludedTemplateNamesTheIncludedFile(): void
    {
        self::tpl('pc_php_inc_host', implode("\n", [
            'host one',
            '{% include "pc_php_inc_part" %}',
            'host three',
        ]));
        self::tpl('pc_php_inc_part', implode("\n", [
            'part one',
            "{% php echo 'x'; %}",
        ]));

        try {
            self::engine(Policy::restricted())->renderPartial('pc_php_inc_host');
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertSame('pc_php_inc_part', $e->templateName);
            $this->assertSame(2, $e->templateLine);

            // ... and the physical path must follow the SAME name, so an editor
            // opens the included file rather than its host.
            $this->assertStringEndsWith(
                'pc_php_inc_part.clarity.html',
                str_replace('\\', '/', $e->templatePath)
            );
            $this->assertStringNotContainsString('host', $e->templatePath);
        }
    }

    /**
     * A child template is merged with its layout, so the child's own lines are
     * offset in the merged source.  The marker the merge emits is what keeps the
     * child's line number, rather than the line it happens to occupy after
     * inlining.
     */
    public function testRawPhpRefusalInAnExtendingChildUsesTheChildLine(): void
    {
        self::tpl('pc_php_layout', implode("\n", [
            '<html>',
            '{% block content %}{% endblock %}',
            '</html>',
        ]));
        self::tpl('pc_php_child', implode("\n", [
            '{% extends "pc_php_layout" %}',
            '{% block content %}',
            '<p>two</p>',
            "{% php echo 'x'; %}",
            '{% endblock %}',
        ]));

        try {
            self::engine(Policy::restricted())->renderPartial('pc_php_child');
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertSame('pc_php_child', $e->templateName);
            $this->assertSame(4, $e->templateLine);
        }
    }

    /**
     * An empty `{% php %}` is not a raw-PHP region, so on a denying policy it is
     * refused by the tag-level policy check in the code generator rather than by
     * the pre-scan gate — and must still carry the right line.
     */
    public function testEmptyPhpTagIsStillRefusedWithTheCorrectLine(): void
    {
        self::tpl('pc_php_empty_off', implode("\n", ['one', '{% php %}']));

        try {
            self::engine(Policy::restricted())->renderPartial('pc_php_empty_off');
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertStringContainsString('rawPhp', $e->getMessage());
            $this->assertSame('pc_php_empty_off', $e->templateName);
            $this->assertSame(2, $e->templateLine);
        }
    }

    /**
     * With the capability granted, an empty tag keeps its own message — the
     * pre-scan gate must not have swallowed it as a policy refusal.
     */
    public function testEmptyPhpTagKeepsItsOwnMessageWhenRawPhpIsGranted(): void
    {
        self::tpl('pc_php_empty_on', implode("\n", ['one', '{% php %}']));

        try {
            self::engine(Policy::default()->allowCapability('rawPhp'))->renderPartial('pc_php_empty_on');
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertStringContainsString('Empty', $e->getMessage());
            $this->assertStringNotContainsString('rawPhp', $e->getMessage());
            $this->assertSame(2, $e->templateLine);
        }
    }

    /**
     * A macro body is a compilation unit of its own: the compiler reports it
     * under the owning template's name, with a line relative to the macro
     * definition.  A `{% php %}` tag inside one must follow the same rule rather
     * than leaking the internal `#macro@` name or a merged-source line.
     */
    public function testRawPhpRefusalInsideAMacroNamesTheOwningTemplate(): void
    {
        self::tpl('pc_php_macro', implode("\n", [
            '{% macro @bad() %}',
            "{% php echo 'x'; %}",
            '{% endmacro %}',
            '{% @bad() %}',
        ]));

        try {
            self::engine(Policy::restricted())->renderPartial('pc_php_macro');
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertSame('pc_php_macro', $e->templateName);
            $this->assertSame(2, $e->templateLine);
        }
    }

    // =========================================================================
    // methodCalls
    // =========================================================================

    public function testMethodCallIsRefusedWithoutTheCapability(): void
    {
        self::tpl('pc_mc_off', '{{ $obj->name() }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Method calls are not allowed/');
        self::engine(Policy::default()->allowCapability('rawPhp'))
            ->renderPartial('pc_mc_off', ['obj' => self::makeObject()]);
    }

    public function testMethodCallIsGrantedByItsOwnCapability(): void
    {
        self::tpl('pc_mc_on', "{{ \$obj->greet('Bob') }}");

        $this->assertSame(
            'Hi Bob',
            self::engine(Policy::default()->allowCapability('methodCalls'))
                ->renderPartial('pc_mc_on', ['obj' => self::makeObject()])
        );
    }

    /**
     * The capability gates the CALL, not a spelling — so it enables the bare
     * forms too, which is what lets a template written in dot syntax adopt the
     * grant without a rewrite.
     */
    public function testBareMethodCallIsGrantedByTheSameCapability(): void
    {
        self::tpl('pc_mc_bare', "{{ obj.greet('Bob') }}");

        $this->assertSame(
            'Hi Bob',
            self::engine(Policy::default()->allowCapability('methodCalls'))
                ->renderPartial('pc_mc_bare', ['obj' => self::makeObject()])
        );
    }

    public function testBareMethodCallIsRefusedWithoutTheCapability(): void
    {
        self::tpl('pc_mc_bare_off', '{{ obj.name() }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/methodCalls/');
        self::engine(Policy::default()->allowCapability('rawPhp'))
            ->renderPartial('pc_mc_bare_off', ['obj' => self::makeObject()]);
    }

    /**
     * The refusal must name the grant that would fix it — the promise the policy
     * docs make — in both spellings.
     */
    public function testRefusalNamesTheGrantInBothSpellings(): void
    {
        self::tpl('pc_mc_msg_bare', '{{ obj.name() }}');
        self::tpl('pc_mc_msg_sigil', '{{ $obj->name() }}');

        foreach (['pc_mc_msg_bare', 'pc_mc_msg_sigil'] as $view) {
            try {
                self::engine(Policy::restricted())->renderPartial($view, ['obj' => self::makeObject()]);
                $this->fail("{$view} must not compile without the capability");
            } catch (ClarityException $e) {
                $this->assertStringContainsString('methodCalls', $e->getMessage(), $view);
            }
        }
    }

    // =========================================================================
    // newExpressions
    // =========================================================================

    public function testNewIsRefusedWithoutTheCapability(): void
    {
        self::tpl('pc_new_off', '{{ new DateTime("2020-01-02") }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/'newExpressions' capability/");
        self::engine(Policy::trusted())->renderPartial('pc_new_off');
    }

    public function testNewIsGrantedByItsOwnCapability(): void
    {
        self::tpl('pc_new_on', '{{ new DateTime("2020-01-02") |> date("Y") }}');

        $this->assertSame(
            '2020',
            self::engine(Policy::default()->allowCapability('newExpressions'))->renderPartial('pc_new_on')
        );
    }

    public function testNewAcceptsAFullyQualifiedName(): void
    {
        // The name is compiled to a fully qualified form, so a leading separator
        // is not a second spelling that happens to work — it is the same one.
        self::tpl('pc_new_fqn', '{{ new \DateTime("2020-01-02") |> date("Y-m-d") }}');

        $this->assertSame(
            '2020-01-02',
            self::engine(Policy::default()->allowCapability('newExpressions'))->renderPartial('pc_new_fqn')
        );
    }

    public function testNewArgumentsAreCompiledAsClarityExpressions(): void
    {
        // `rows` must resolve through the scope, not as a PHP constant.
        self::tpl('pc_new_args', '{% if new ArrayObject(rows) |> length > 1 %}Y{% else %}N{% endif %}');

        $this->assertSame(
            'Y',
            self::engine(Policy::default()->allowCapability('newExpressions'))
                ->renderPartial('pc_new_args', ['rows' => [1, 2, 3]])
        );
    }

    // =========================================================================
    // staticCalls
    // =========================================================================

    public function testStaticCallIsRefusedWithoutTheCapability(): void
    {
        self::tpl('pc_st_off', '{{ DateTime::ATOM }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/'staticCalls' capability/");
        self::engine(Policy::default()->allowCapability('newExpressions'))->renderPartial('pc_st_off');
    }

    public function testStaticMethodCallAndConstantBothWork(): void
    {
        self::tpl('pc_st_method', '{{ DateTime::createFromFormat("Y-m-d", "2020-01-02") |> date("Y") }}');
        self::tpl('pc_st_const', '{{ DateTime::class }}');

        $engine = self::engine(Policy::default()->allowCapability('staticCalls'));
        $this->assertSame('2020', $engine->renderPartial('pc_st_method'));
        $this->assertSame('DateTime', $engine->renderPartial('pc_st_const'));
    }

    public function testStaticCallOnANamespacedClass(): void
    {
        self::tpl('pc_st_ns', '{{ \Clarity\Tests\Engine\PolicyCapabilityFixture::label() }}');

        $this->assertSame(
            'fixture',
            self::engine(Policy::default()->allowCapability('staticCalls'))->renderPartial('pc_st_ns')
        );
    }

    // =========================================================================
    // instanceof — grammar, available in every policy
    // =========================================================================

    public function testInstanceofTakesAClassNameAndWorksInEveryPolicy(): void
    {
        self::tpl('pc_io', '{% if obj instanceof DateTime %}Y{% else %}N{% endif %}');

        // The default policy: no capability needed, because instanceof does not
        // reach anything the scope did not already hold.
        $engine = self::engine(Policy::restricted());
        $this->assertSame('Y', $engine->renderPartial('pc_io', ['obj' => new \DateTime()]));
        $this->assertSame('N', $engine->renderPartial('pc_io', ['obj' => 5]));
    }

    public function testABareClassnameOutsideAConstructIsRefused(): void
    {
        // `Foo\Bar` is a name the operator loop cannot emit, and it is not a
        // scope read either — so it is reported rather than silently compiled.
        self::tpl('pc_bare_ns', '{{ Foo\Bar }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/A PHP class name .* is not allowed here/');
        self::engine(Policy::unrestricted())->renderPartial('pc_bare_ns');
    }

    // =========================================================================
    // superglobals
    // =========================================================================

    public function testSuperglobalsAreRefusedWithoutTheCapability(): void
    {
        self::tpl('pc_sg_off', '{{ _SERVER["PHP_SELF"] }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/_SERVER.* is not defined in this context/');
        self::engine(Policy::default()->allowCapability('phpVariables'))
            ->renderPartial('pc_sg_off');
    }

    public function testSuperglobalsAreGrantedByTheirOwnCapability(): void
    {
        // Note the scope is NOT seeded: the read is PHP's `$_SERVER` because the
        // capability says so, not because a local happens to exist.
        self::tpl('pc_sg_on', '{{ _SERVER["PHP_SELF"] |> length > 0 ? "yes" : "no" }}');

        $this->assertSame(
            'yes',
            self::engine(Policy::default()->allowCapability('superglobals'))->renderPartial('pc_sg_on')
        );
    }

    public function testASuperglobalReadIsNotAScopeRead(): void
    {
        // A scope entry spelled `_SERVER` must not shadow PHP's own variable once
        // the capability is granted: the capability is what the name means.
        self::tpl('pc_sg_shadow', '{{ _SERVER["marker"] }}');

        $engine = self::engine(Policy::default()->allowCapability('superglobals'));
        try {
            $engine->renderPartial('pc_sg_shadow', ['_SERVER' => ['marker' => 'from-scope']]);
            $this->fail('a granted superglobal must not resolve to the render scope');
        } catch (ClarityException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testOnlyKnownSuperglobalsAreTreatedAsSuperglobals(): void
    {
        // `_SERVERX` is an ordinary scope name even with the capability on.
        self::tpl('pc_sg_near', '{{ _SERVERX }}');

        $this->assertSame(
            'scope',
            self::engine(Policy::default()->allowCapability('superglobals'))
                ->renderPartial('pc_sg_near', ['_SERVERX' => 'scope'])
        );
    }

    // =========================================================================
    // variableVariables
    // =========================================================================

    public function testVariableVariablesAreOnByDefault(): void
    {
        self::tpl('pc_vv_on', '{{ $$name }}');

        $this->assertSame(
            'v',
            self::engine(Policy::restricted())->renderPartial('pc_vv_on', ['name' => 'x', 'x' => 'v'])
        );
    }

    // =========================================================================
    // phpVariables
    // =========================================================================

    public function testScopeIsNotSeededWithoutPhpVariables(): void
    {
        // The capability is about the SEEDING, not about a read: a template can
        // still read its scope through the `$__c_va` form.
        self::tpl('pc_pv_off', '{{ title }}');

        $this->assertSame(
            'T',
            self::engine(Policy::restricted())->renderPartial('pc_pv_off', ['title' => 'T'])
        );
    }

    public function testPhpVariablesMakesALocalAndTheScopeTheSameName(): void
    {
        self::tpl('pc_pv_on', "{% php \$t = 'from-php'; %}{{ t }}");

        $this->assertSame(
            'from-php',
            self::engine(Policy::default()->allowCapability('phpVariables')->allowCapability('rawPhp'))
                ->renderPartial('pc_pv_on')
        );
    }
}
