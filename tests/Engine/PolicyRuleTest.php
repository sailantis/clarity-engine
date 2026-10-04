<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Engine\Policy;
use Clarity\Template\ArrayLoader;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;
use Clarity\Tests\TestEnvironment;

/**
 * Each rule, compiled — the policy's effect on what a template can reach.
 *
 * The point of splitting the old boolean is that a grant is INDEPENDENT: turning
 * on `methodCalls` must not turn on `rawPhp`, and an allowlist must not turn on
 * anything at all beyond the names it lists.  Every test below therefore grants
 * one thing and asserts the neighbours are still refused.
 */
class PolicyRuleTest extends BaseTestCase
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

    public function testRawPhpIsRefusedWithoutTheRule(): void
    {
        self::tpl('pc_php_off', "{% php echo 'x'; %}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/'rawPhp' rule/");
        self::engine(Policy::default()->allowRule('methodCalls'))->renderPartial('pc_php_off');
    }

    public function testRawPhpIsGrantedByItsOwnRule(): void
    {
        self::tpl('pc_php_on', "{% php echo 'x'; %}");

        $this->assertSame('x', self::engine(Policy::default()->allowRule('rawPhp'))->renderPartial('pc_php_on'));
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
     * With the rule granted, an empty tag keeps its own message — the
     * pre-scan gate must not have swallowed it as a policy refusal.
     */
    public function testEmptyPhpTagKeepsItsOwnMessageWhenRawPhpIsGranted(): void
    {
        self::tpl('pc_php_empty_on', implode("\n", ['one', '{% php %}']));

        try {
            self::engine(Policy::default()->allowRule('rawPhp'))->renderPartial('pc_php_empty_on');
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
     * than leaking the internal `#macro#` name or a merged-source line.
     */
    public function testRawPhpRefusalInsideAMacroNamesTheOwningTemplate(): void
    {
        self::tpl('pc_php_macro', implode("\n", [
            '{% macro bad() %}',
            "{% php echo 'x'; %}",
            '{% endmacro %}',
            '{% call bad() %}',
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

    public function testMethodCallIsRefusedWithoutTheRule(): void
    {
        self::tpl('pc_mc_off', '{{ $obj->name() }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Method calls are not allowed/');
        self::engine(Policy::default()->allowRule('rawPhp'))
            ->renderPartial('pc_mc_off', ['obj' => self::makeObject()]);
    }

    public function testMethodCallIsGrantedByItsOwnRule(): void
    {
        self::tpl('pc_mc_on', "{{ \$obj->greet('Bob') }}");

        $this->assertSame(
            'Hi Bob',
            self::engine(Policy::default()->allowRule('methodCalls'))
                ->renderPartial('pc_mc_on', ['obj' => self::makeObject()])
        );
    }

    /**
     * The rule gates the CALL, not a spelling — so it enables the bare
     * forms too, which is what lets a template written in dot syntax adopt the
     * grant without a rewrite.
     */
    public function testBareMethodCallIsGrantedByTheSameRule(): void
    {
        self::tpl('pc_mc_bare', "{{ obj.greet('Bob') }}");

        $this->assertSame(
            'Hi Bob',
            self::engine(Policy::default()->allowRule('methodCalls'))
                ->renderPartial('pc_mc_bare', ['obj' => self::makeObject()])
        );
    }

    public function testBareMethodCallIsRefusedWithoutTheRule(): void
    {
        self::tpl('pc_mc_bare_off', '{{ obj.name() }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/methodCalls/');
        self::engine(Policy::default()->allowRule('rawPhp'))
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
                $this->fail("{$view} must not compile without the rule");
            } catch (ClarityException $e) {
                $this->assertStringContainsString('methodCalls', $e->getMessage(), $view);
            }
        }
    }

    // =========================================================================
    // newExpressions
    // =========================================================================

    public function testNewIsRefusedWithoutTheRule(): void
    {
        self::tpl('pc_new_off', '{{ new DateTime("2020-01-02") }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/'newExpressions' rule/");
        self::engine(Policy::trusted())->renderPartial('pc_new_off');
    }

    public function testNewIsGrantedByItsOwnRule(): void
    {
        self::tpl('pc_new_on', '{{ new DateTime("2020-01-02") |> date("Y") }}');

        $this->assertSame(
            '2020',
            self::engine(Policy::default()->allowRule('newExpressions'))->renderPartial('pc_new_on')
        );
    }

    public function testNewAcceptsAFullyQualifiedName(): void
    {
        // The name is compiled to a fully qualified form, so a leading separator
        // is not a second spelling that happens to work — it is the same one.
        self::tpl('pc_new_fqn', '{{ new \DateTime("2020-01-02") |> date("Y-m-d") }}');

        $this->assertSame(
            '2020-01-02',
            self::engine(Policy::default()->allowRule('newExpressions'))->renderPartial('pc_new_fqn')
        );
    }

    public function testNewArgumentsAreCompiledAsClarityExpressions(): void
    {
        // `rows` must resolve through the scope, not as a PHP constant.
        self::tpl('pc_new_args', '{% if new ArrayObject(rows) |> length > 1 %}Y{% else %}N{% endif %}');

        $this->assertSame(
            'Y',
            self::engine(Policy::default()->allowRule('newExpressions'))
                ->renderPartial('pc_new_args', ['rows' => [1, 2, 3]])
        );
    }

    // =========================================================================
    // staticCalls
    // =========================================================================

    public function testStaticCallIsRefusedWithoutTheRule(): void
    {
        self::tpl('pc_st_off', '{{ DateTime::ATOM }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/'staticCalls' rule/");
        self::engine(Policy::default()->allowRule('newExpressions'))->renderPartial('pc_st_off');
    }

    public function testStaticMethodCallAndConstantBothWork(): void
    {
        self::tpl('pc_st_method', '{{ DateTime::createFromFormat("Y-m-d", "2020-01-02") |> date("Y") }}');
        self::tpl('pc_st_const', '{{ DateTime::class }}');

        $engine = self::engine(Policy::default()->allowRule('staticCalls'));
        $this->assertSame('2020', $engine->renderPartial('pc_st_method'));
        $this->assertSame('DateTime', $engine->renderPartial('pc_st_const'));
    }

    public function testStaticCallOnANamespacedClass(): void
    {
        self::tpl('pc_st_ns', '{{ \Clarity\Tests\Engine\PolicyRuleFixture::label() }}');

        $this->assertSame(
            'fixture',
            self::engine(Policy::default()->allowRule('staticCalls'))->renderPartial('pc_st_ns')
        );
    }

    // =========================================================================
    // instanceof — grammar, available in every policy
    // =========================================================================

    public function testInstanceofTakesAClassNameAndWorksInEveryPolicy(): void
    {
        self::tpl('pc_io', '{% if obj instanceof DateTime %}Y{% else %}N{% endif %}');

        // The default policy: no rule needed, because instanceof does not
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

    public function testSuperglobalsAreRefusedWithoutTheRule(): void
    {
        self::tpl('pc_sg_off', '{{ _SERVER["PHP_SELF"] }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/_SERVER.* is not defined in this context/');
        self::engine(Policy::default()->allowRule('phpVariables'))
            ->renderPartial('pc_sg_off');
    }

    public function testSuperglobalsAreGrantedByTheirOwnRule(): void
    {
        // Note the scope is NOT seeded: the read is PHP's `$_SERVER` because the
        // rule says so, not because a local happens to exist.
        self::tpl('pc_sg_on', '{{ _SERVER["PHP_SELF"] |> length > 0 ? "yes" : "no" }}');

        $this->assertSame(
            'yes',
            self::engine(Policy::default()->allowRule('superglobals'))->renderPartial('pc_sg_on')
        );
    }

    public function testASuperglobalReadIsNotAScopeRead(): void
    {
        // A scope entry spelled `_SERVER` must not shadow PHP's own variable once
        // the rule is granted: the rule is what the name means.
        self::tpl('pc_sg_shadow', '{{ _SERVER["marker"] }}');

        $engine = self::engine(Policy::default()->allowRule('superglobals'));
        try {
            $engine->renderPartial('pc_sg_shadow', ['_SERVER' => ['marker' => 'from-scope']]);
            $this->fail('a granted superglobal must not resolve to the render scope');
        } catch (ClarityException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testOnlyKnownSuperglobalsAreTreatedAsSuperglobals(): void
    {
        // `_SERVERX` is an ordinary scope name even with the rule on.
        self::tpl('pc_sg_near', '{{ _SERVERX }}');

        $this->assertSame(
            'scope',
            self::engine(Policy::default()->allowRule('superglobals'))
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
        // The rule is about the SEEDING, not about a read: a template can
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
            self::engine(Policy::default()->allowRule('phpVariables')->allowRule('rawPhp'))
                ->renderPartial('pc_pv_on')
        );
    }

    // =========================================================================
    // strictTypes
    // =========================================================================

    public function testStrictTypesMakesAMismatchedFilterArgumentThrow(): void
    {
        // The rule this exists for: a typed filter is handed the wrong type
        // and, with the declaration in the compiled file, says so instead of
        // accepting the coercion. It is now the DEFAULT, so the weak half of this
        // test has to deny the rule to be weak at all.
        self::tpl('pc_st_off', '{{ 42 |> shout }}');
        self::tpl('pc_st_on', '{{ 42 |> shout }}');

        $define = static function (TestClarityEngine $engine): TestClarityEngine {
            $engine->addFilter('shout', static fn(string $s): string => 'STRICT:' . $s);
            return $engine;
        };

        $weak = $define(self::engine(Policy::default()->denyRule('strictTypes')));
        $this->assertSame('STRICT:42', $weak->renderPartial('pc_st_off'));

        $strict = $define(self::engine(Policy::default()));
        $this->expectException(ClarityException::class);
        $strict->renderPartial('pc_st_on');
    }

    /**
     * The counterpart to the test above, and the reason `strictTypes` is scoped
     * the way it is: it governs the CONTRACT at a call boundary, not the
     * stringification of output. `htmlspecialchars((string)(…))` at the output
     * boundary is not a filter call and is how any non-string renders at all, so
     * the rule must leave it alone — otherwise a strict template could not
     * print a number.
     */
    public function testStrictTypesStillRendersNonStringOutput(): void
    {
        self::tpl('pc_st_render', '{{ 42 }}|{{ items |> length }}');

        $this->assertSame(
            '42|3',
            self::engine(Policy::default())->renderPartial('pc_st_render', ['items' => [1, 2, 3]])
        );
    }

    /**
     * A related rule must stay refused — the whole point of granting one
     * thing is that the neighbours do not come along.
     */
    public function testStrictTypesDoesNotGrantPhpAccess(): void
    {
        self::tpl('pc_st_isolated', "{% php echo 'x'; %}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/'rawPhp' rule/");
        // Denying strictTypes, to show the reach answer does not depend on it
        // either — the rule is orthogonal to PHP access in both directions.
        self::engine(Policy::default()->denyRule('strictTypes'))->renderPartial('pc_st_isolated');
    }
}
