<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;
use Clarity\Tests\TestEnvironment;

/**
 * Open mode: the sandbox switch that grants templates the full power of PHP.
 *
 * Sandboxed (the default) is the engine's historical behaviour and must stay
 * byte-identical; these tests pin that the switch is OFF by default and that
 * turning it off only ever ADDS capability.
 *
 * The compiled class records which mode built it ($sandboxCompiled), so the
 * loader recompiles whenever the setting changes. That is load-bearing: without
 * it a template compiled sandboxed would be served in open mode (and vice
 * versa), because the cache keys on template source only.
 */
class OpenModeTest extends BaseTestCase
{
    /** A fresh engine with the sandbox disabled. */
    private static function openEngine(array $config = []): TestClarityEngine
    {
        return new TestClarityEngine(\array_merge([
            'viewPath'  => TestEnvironment::viewDir(),
            'cachePath' => TestEnvironment::cacheDir(),
            'extension' => 'clarity.html',
            'sandbox'   => false,
        ], $config));
    }

    private static function sandboxedEngine(array $config = []): TestClarityEngine
    {
        return new TestClarityEngine(\array_merge([
            'viewPath'  => TestEnvironment::viewDir(),
            'cachePath' => TestEnvironment::cacheDir(),
            'extension' => 'clarity.html',
        ], $config));
    }

    // =========================================================================
    // Default is sandboxed
    // =========================================================================

    public function testSandboxedByDefaultRejectsUnknownFunctionCall(): void
    {
        self::tpl('om_default_call', "{{ strtoupper('ab') }}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/unregistered function/i');
        self::render('om_default_call');
    }

    public function testSandboxedUnknownFilterDoesNotResolveToPhpFunction(): void
    {
        self::tpl('om_default_filter', "{{ 'ab' |> strtoupper }}");

        $this->expectException(ClarityException::class);
        self::render('om_default_filter');
    }

    // =========================================================================
    // Arbitrary PHP functions (open mode)
    // =========================================================================

    public function testOpenModeCallsArbitraryFunction(): void
    {
        self::tpl('om_call', "{{ strtoupper('ab') }}");
        $this->assertSame('AB', self::openEngine()->renderPartial('om_call'));
    }

    public function testOpenModeFunctionAsFilterValueFirst(): void
    {
        self::tpl('om_filter', "{{ 'ab' |> strtoupper }}");
        $this->assertSame('AB', self::openEngine()->renderPartial('om_filter'));
    }

    public function testOpenModeFilterWithArgsValueFirst(): void
    {
        // The piped value becomes the FIRST argument: str_pad($value, 3, '-')
        self::tpl('om_filter_args', "{{ 'x' |> str_pad(3, '-') }}");
        $this->assertSame('x--', self::openEngine()->renderPartial('om_filter_args'));
    }

    public function testPlaceholderPositionsValue(): void
    {
        self::tpl('om_placeholder', "{{ 'a' |> str_repeat(_, 3) }}");
        $this->assertSame('aaa', self::openEngine()->renderPartial('om_placeholder'));
    }

    public function testPlaceholderForKeyFirstFunction(): void
    {
        self::tpl('om_placeholder_key', "{{ 'k' |> array_key_exists(_, m) }}");
        $this->assertSame('1', self::openEngine()->renderPartial('om_placeholder_key', ['m' => ['k' => 1]]));
    }

    public function testPlaceholderOnlyOnce(): void
    {
        self::tpl('om_placeholder_twice', "{{ 'a' |> str_repeat(_, _) }}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/only once/');
        self::openEngine()->renderPartial('om_placeholder_twice');
    }

    public function testNamedArgumentOnPhpFunction(): void
    {
        // Positional value + PHP named argument (str_pad's param is `length`).
        self::tpl('om_named', "{{ 'hello' |> str_pad(length: 7) }}");
        $this->assertSame('hello  ', self::openEngine()->renderPartial('om_named'));
    }

    public function testUnknownFilterInOpenModeIsCompileError(): void
    {
        self::tpl('om_unknown', "{{ 'x' |> totally_nonexistent_fn_xyz }}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Unknown filter/');
        self::openEngine()->renderPartial('om_unknown');
    }

    // =========================================================================
    // Function guardrails (opt-in; empty by default)
    // =========================================================================

    public function testNothingIsBlockedByDefaultInOpenMode(): void
    {
        // Open mode is full PHP access. The engine deliberately does NOT smuggle
        // a second, partial sandbox into it, so even the classic sinks run.
        self::tpl('om_default_exec', '{{ exec(\'echo hi\') }}');
        self::tpl('om_default_extract', '{{ extract(x) }}');

        $engine = self::openEngine();
        $this->assertSame('hi', $engine->renderPartial('om_default_exec'));
        $this->assertSame('0', $engine->renderPartial('om_default_extract', ['x' => []]));
    }

    public function testDefaultDenyListIsEmpty(): void
    {
        $this->assertSame([], self::openEngine()->getDeniedFunctions());
    }

    public function testDenyListBlocksFunctionUsedAsFilter(): void
    {
        self::tpl('om_deny_filter', "{{ 'abc' |> strrev }}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/blocked in open mode/');
        self::openEngine(['deniedFunctions' => ['strrev']])->renderPartial('om_deny_filter');
    }

    public function testClearingDenyListAllowsFunction(): void
    {
        self::tpl('om_allow', "{{ 'abc' |> strrev }}");
        $this->assertSame('cba', self::openEngine(['deniedFunctions' => []])->renderPartial('om_allow'));
    }

    public function testDenyListIsCaseInsensitive(): void
    {
        self::tpl('om_deny_case', "{{ 'abc' |> STRREV }}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/blocked in open mode/');
        self::openEngine(['deniedFunctions' => ['strrev']])->renderPartial('om_deny_case');
    }

    // =========================================================================
    // Precedence: registered filters/functions win
    // =========================================================================

    public function testRegisteredFunctionWinsOverPhpFunction(): void
    {
        $engine = self::openEngine();
        $engine->addFunction('strtoupper', static fn(): string => 'REGISTERED');

        self::tpl('om_prec_fn', "{{ strtoupper('ab') }}");
        $this->assertSame('REGISTERED', $engine->renderPartial('om_prec_fn'));
    }

    public function testRegisteredFilterWinsOverPhpFunction(): void
    {
        $engine = self::openEngine();
        $engine->addFilter('strtoupper', static fn(): string => 'REGISTERED');

        self::tpl('om_prec_fl', "{{ 'ab' |> strtoupper }}");
        $this->assertSame('REGISTERED', $engine->renderPartial('om_prec_fl'));
    }

    // =========================================================================
    // ${expr} / $$name — dynamic variable lookup (works in BOTH modes)
    //
    // The lookup resolves against the render scope and loop locals, so it can
    // reach neither a superglobal nor an engine internal in either mode; the
    // sandbox therefore has nothing extra to deny, and the construct replaced the
    // old `expand` filter.
    // =========================================================================

    public function testDynamicLookupResolvesInSandboxMode(): void
    {
        self::tpl('om_dd_sandbox', '{{ $$name }}');
        $this->assertSame('v', self::render('om_dd_sandbox', ['name' => 'x', 'x' => 'v']));
    }

    public function testBracedLookupMatchesTheDollarShorthand(): void
    {
        self::tpl('om_dd_braced', '{{ ${name} }}');
        $this->assertSame('v', self::render('om_dd_braced', ['name' => 'x', 'x' => 'v']));
    }

    public function testDynamicLookupIsStrictForAnAbsentName(): void
    {
        self::tpl('om_dd_absent', '{{ ${missing} }}');

        $this->expectException(ClarityException::class);
        self::render('om_dd_absent', ['missing' => 'nope']);
    }

    public function testDynamicLookupComposesWithNullCoalescing(): void
    {
        self::tpl('om_dd_coalesce', '{{ ${missing} ?? "DEF" }}');
        $this->assertSame('DEF', self::render('om_dd_coalesce', []));
    }

    public function testDynamicLookupCannotReachEngineInternals(): void
    {
        // `__c_fn` names the callable registry, but it is not a scope entry, so a
        // dynamic lookup reports it absent instead of exposing the internal.
        self::tpl('om_dd_internal', '{{ ${which} }}');

        $this->expectException(ClarityException::class);
        self::render('om_dd_internal', ['which' => '__c_fn']);
    }

    public function testVariableVariableIsAllowedInOpenMode(): void
    {
        // Open mode grants the full power of PHP, and the dynamic lookup is an
        // ordinary scope read in both modes.
        self::tpl('om_dd_open', '{{ $$name }}');

        $this->assertSame('v', self::openEngine()->renderPartial('om_dd_open', ['name' => 'x', 'x' => 'v']));
    }

    public function testVariableVariableCannotReachASuperglobalInOpenMode(): void
    {
        // The dynamic form resolves in the render frame, which holds no
        // superglobal slot, so it fails strict access rather than leaking one.
        self::tpl('om_dd_super', '{{ $$name }}');

        $this->expectException(ClarityException::class);
        self::openEngine()->renderPartial('om_dd_super', ['name' => '_SERVER']);
    }

    public function testExplicitGuardrailBlocksScopeIndirectionSinks(): void
    {
        // The engine grants full PHP; an application may still add its own
        // guardrails on top of that decision.
        $engine = self::openEngine(['deniedFunctions' => ['extract', 'get_defined_vars', 'compact', 'call_user_func']]);

        foreach (['extract', 'get_defined_vars', 'compact', 'call_user_func'] as $fn) {
            self::tpl('om_sink_' . $fn, "{{ {$fn}(x) }}");

            try {
                $engine->renderPartial('om_sink_' . $fn, ['x' => []]);
                $this->fail("{$fn}() should be blocked by the explicit guardrail");
            } catch (ClarityException $e) {
                $this->assertMatchesRegularExpression('/blocked in open mode/', $e->getMessage());
            }
        }
    }

    // =========================================================================
    // Method calls (open mode)
    // =========================================================================

    public function testSandboxedMethodCallIsRejected(): void
    {
        self::tpl('om_mc_sandbox', '{{ $obj->name() }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Method calls are not allowed/');
        self::render('om_mc_sandbox', ['obj' => self::makeObject()]);
    }

    public function testOpenModeMethodCall(): void
    {
        self::tpl('om_mc', '{{ $obj->name() }}');
        $this->assertSame('Alice', self::openEngine()->renderPartial('om_mc', ['obj' => self::makeObject()]));
    }

    public function testOpenModeMethodCallWithArguments(): void
    {
        self::tpl('om_mc_args', "{{ \$obj->greet('Bob') }}");
        $this->assertSame('Hi Bob', self::openEngine()->renderPartial('om_mc_args', ['obj' => self::makeObject()]));
    }

    public function testOpenModeChainedMethodCall(): void
    {
        self::tpl('om_mc_chain', '{{ $obj->self()->name() }}');
        $this->assertSame('Alice', self::openEngine()->renderPartial('om_mc_chain', ['obj' => self::makeObject()]));
    }

    public function testOpenModeDynamicMethodCall(): void
    {
        self::tpl('om_mc_dyn', '{{ $obj->{$m}() }}');
        $this->assertSame(
            'Alice',
            self::openEngine()->renderPartial('om_mc_dyn', ['obj' => self::makeObject(), 'm' => 'name'])
        );
    }

    public function testOpenModeNullsafeMethodCall(): void
    {
        self::tpl('om_mc_null', '{{ $obj?->name() }}');
        $this->assertSame('Alice', self::openEngine()->renderPartial('om_mc_null', ['obj' => self::makeObject()]));
    }

    public function testOpenModeMethodCallThenProperty(): void
    {
        self::tpl('om_mc_then_prop', '{{ $obj->self()->label }}');
        $this->assertSame('L', self::openEngine()->renderPartial('om_mc_then_prop', ['obj' => self::makeObject()]));
    }

    public function testBareMethodCallWithoutSigilStillRejectedInOpenMode(): void
    {
        self::tpl('om_mc_nosigil', '{{ obj->name() }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/requires the \$ sigil/');
        self::openEngine()->renderPartial('om_mc_nosigil', ['obj' => self::makeObject()]);
    }

    public function testRootInvocationRejectedInOpenMode(): void
    {
        self::tpl('om_mc_root', '{{ $fn() }}');

        $this->expectException(ClarityException::class);
        self::openEngine()->renderPartial('om_mc_root', ['fn' => static fn(): string => 'x']);
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

            public function self(): static
            {
                return $this;
            }
        };
    }

    // =========================================================================
    // {% php %} raw blocks
    // =========================================================================

    public function testPhpBlockRejectedWhenSandboxed(): void
    {
        self::tpl('om_php_sandbox', "{% php %}echo 'x';{% endphp %}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/not allowed in sandbox mode/');
        self::render('om_php_sandbox');
    }

    public function testPhpBlockRendersInOpenMode(): void
    {
        self::tpl('om_php', "{% php %}echo strtoupper('hi');{% endphp %}");
        $this->assertSame('HI', self::openEngine()->renderPartial('om_php'));
    }

    public function testPhpBlockMultiLine(): void
    {
        // Multi-statement body. The block itself contributes no literal text,
        // and the newline after `{% endphp %}` is stripped by the same
        // post-block rule that applies to every `{% %}` tag.
        $tpl = 'A' . "\n"
            . '{% php %}' . "\n"
            . '$n = 2;' . "\n"
            . 'echo $n * 3;' . "\n"
            . 'echo "\n";' . "\n"
            . '{% endphp %}B';
        self::tpl('om_php_multi', $tpl);
        $this->assertSame("A\n6\nB", self::openEngine()->renderPartial('om_php_multi'));
    }

    public function testPhpBlockReadsIncomingVariableAsLocal(): void
    {
        // Open mode seeds the scope into locals, so a template variable is a
        // plain PHP variable inside the block — no internals, exactly as in
        // Blade / Stempler / Plates.
        self::tpl('om_php_read', '{% php echo strtoupper($title); %}');
        $this->assertSame('TITLE', self::openEngine()->renderPartial('om_php_read', ['title' => 'title']));
    }

    public function testPhpBlockAndTemplateExpressionShareOneVariable(): void
    {
        // The load-bearing property: a local written by a block is the SAME
        // variable a {{ }} expression reads. Two views of one store, not two
        // environments.
        self::tpl('om_php_share', "{% php \$x = 'set'; %}{{ x }}");
        $this->assertSame('set', self::openEngine()->renderPartial('om_php_share'));
    }

    public function testSetAndPhpBlockShareOneVariable(): void
    {
        // The same from the other direction: {% set %} and a raw block agree.
        self::tpl('om_set_php', "{% set t = 'foo' %}{{ t }}|{% php echo \$t; %}");
        $this->assertSame('foo|foo', self::openEngine()->renderPartial('om_set_php'));
    }

    public function testLoopLocalIsSharedWithPhpBlock(): void
    {
        self::tpl('om_loop_php', '{% for f in items %}{{ f }}-{% php echo $f; %};{% endfor %}');
        $this->assertSame(
            'a-a;b-b;',
            self::openEngine()->renderPartial('om_loop_php', ['items' => ['a', 'b']])
        );
    }

    public function testPhpBlockCanStillReadTheVarArray(): void
    {
        // $__c_va survives as the explicit escape hatch (dynamic names, context()).
        self::tpl('om_php_va', "{% php echo \$__c_va['title']; %}");
        $this->assertSame('T', self::openEngine()->renderPartial('om_php_va', ['title' => 'T']));
    }

    public function testUndefinedRootStillCausesAnErrorInOpenMode(): void
    {
        // Scope seeding must NOT soften strict access: an unknown name is
        // undefined in BOTH modes. (A `?? $__c_va[…]` fallback would silence it.)
        self::tpl('om_undef', '{{ missing }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/not defined in this context/');
        self::openEngine()->renderPartial('om_undef');
    }

    public function testUndefinedRootInPhpBlockCausesAnError(): void
    {
        self::tpl('om_undef_php', '{% php echo $missing; %}');

        $this->expectException(ClarityException::class);
        self::openEngine()->renderPartial('om_undef_php');
    }

    public function testLambdaStillReadsOuterVariables(): void
    {
        // A lambda is a closure capturing $__c_va, so the render frame's locals are
        // out of scope there. Root emission must stay array-based or `prefix`
        // would read as undefined.
        self::tpl('om_lambda_outer', '{{ items |> map(x => prefix ~ x) |> join(",") }}');
        $this->assertSame(
            'Pa,Pb',
            self::openEngine()->renderPartial('om_lambda_outer', ['items' => ['a', 'b'], 'prefix' => 'P'])
        );
    }

    public function testPhpBlockInteractsWithFilters(): void
    {
        self::tpl('om_php_mix', "{% php %}\$up = strtoupper('ab'); echo \$up;{% endphp %}-{{ 'cd' |> strtoupper }}");
        $this->assertSame('AB-CD', self::openEngine()->renderPartial('om_php_mix'));
    }

    public function testPhpBlockMapsRuntimeErrorToBodyLine(): void
    {
        self::tpl('om_php_map', "{% php %}\n\$a = 1;\nthrow new \\RuntimeException('boom');\n{% endphp %}");

        try {
            self::openEngine()->renderPartial('om_php_map');
            $this->fail('expected the block to throw');
        } catch (ClarityException $e) {
            // `throw` is template line 3. A block mapped as a single range would
            // report line 1 (its opening tag); per-line mapping points at the bug.
            $this->assertSame(3, $e->templateLine);
        }
    }

    // =========================================================================
    // {% php CODE %} standalone directive
    // =========================================================================

    public function testStandalonePhpStatement(): void
    {
        self::tpl('om_php_sa_stmt', '{% php $x = 1; echo "bla" . $x; %}');
        $this->assertSame('bla1', self::openEngine()->renderPartial('om_php_sa_stmt'));
    }

    public function testStandalonePhpControlStructureWrapsMarkup(): void
    {
        // The point of the standalone form: PHP structure in one tag, markup
        // between, the closing keyword in another.
        self::tpl(
            'om_php_sa_if',
            "{% php if (\$__c_va['ok']) : %}YES{% php else : %}NO{% php endif %}"
        );

        $engine = self::openEngine();
        $this->assertSame('YES', $engine->renderPartial('om_php_sa_if', ['ok' => true]));
        $this->assertSame('NO', $engine->renderPartial('om_php_sa_if', ['ok' => false]));
    }

    public function testStandalonePhpForeachEchoesPhpLocal(): void
    {
        // Inside a php tag a loop local is a genuine PHP variable, so it is
        // echoed by PHP directly (it is not a template variable).
        self::tpl(
            'om_php_sa_foreach',
            '{% php foreach ([1,2,3] as $n) : %}{% php echo $n; %}{% php endforeach %}'
        );
        $this->assertSame('123', self::openEngine()->renderPartial('om_php_sa_foreach'));
    }

    public function testStandalonePhpExposesLocalToTemplateOutput(): void
    {
        self::tpl(
            'om_php_sa_expose',
            '{% php foreach ([7,8] as $n) : %}{% php $__c_va["n"] = $n; %}{{ n }}{% php endforeach %}'
        );
        $this->assertSame('78', self::openEngine()->renderPartial('om_php_sa_expose'));
    }

    public function testStandalonePhpMultiLineTag(): void
    {
        self::tpl('om_php_sa_multi', "A\n{% php\n\$n = 2;\necho \$n * 3;\n%}\nB");
        $this->assertSame("A\n6B", self::openEngine()->renderPartial('om_php_sa_multi'));
    }

    public function testStandalonePhpMapsRuntimeErrorToBodyLine(): void
    {
        self::tpl(
            'om_php_sa_map',
            "line1\n{% php\n\$a = 1;\nthrow new \\RuntimeException('boom');\n%}"
        );

        try {
            self::openEngine()->renderPartial('om_php_sa_map');
            $this->fail('expected the tag to throw');
        } catch (ClarityException $e) {
            // `throw` is template line 4 -- the tag spans lines 2-3.
            $this->assertSame(4, $e->templateLine);
        }
    }

    public function testStandalonePhpSyntaxErrorMapsToTagLine(): void
    {
        self::tpl('om_php_sa_syntax', "top\n{% php \$a = ; %}\nbottom");

        try {
            self::openEngine()->renderPartial('om_php_sa_syntax');
            $this->fail('expected a syntax error');
        } catch (ClarityException $e) {
            $this->assertStringContainsString('Syntax error', $e->getMessage());
            $this->assertSame(2, $e->templateLine);
        }
    }

    public function testStandalonePhpRejectedWhenSandboxed(): void
    {
        self::tpl('om_php_sa_deny', '{% php echo "x"; %}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/not allowed in sandbox mode/');
        self::render('om_php_sa_deny');
    }

    public function testStrayEndphpIsReported(): void
    {
        self::tpl('om_php_stray', '{% php if (true) : %}{% endphp %}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Unexpected/');
        self::openEngine()->renderPartial('om_php_stray');
    }

    // =========================================================================
    // Internal-name reservation and lvalue validation
    // =========================================================================

    public function testSuperglobalIsReadableInOpenMode(): void
    {
        // Open mode grants the full power of PHP, and raw PHP already reaches the
        // superglobals, so the EXPRESSION form must not be stricter than the block
        // form. Absent from the render scope in sandbox mode, so it throws there.
        self::tpl('om_super', '{{ _SERVER |> length > 0 ? "yes" : "no" }}');

        self::tpl('om_super_php', '{% php echo is_array($_SERVER) ? "yes" : "no"; %}');
        $this->assertSame('yes', self::openEngine()->renderPartial('om_super_php'));

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/not defined in this context/');
        self::render('om_super');
    }

    public function testSetThisIsRejectedAsAClarityException(): void
    {
        // `$this = …` is an UNCATCHABLE PHP fatal, so it has to be caught while
        // compiling. It is a syntax-validity check, not a sandbox rule, and
        // applies in both modes.
        self::tpl('om_set_this', "{% set this = 'x' %}");

        foreach ([self::openEngine(), self::sandboxedEngine()] as $engine) {
            try {
                $engine->renderPartial('om_set_this');
                $this->fail('{% set this %} must be rejected');
            } catch (ClarityException $e) {
                $this->assertStringContainsString('cannot be assigned to', $e->getMessage());
            }
        }
    }

    public function testSetInternalPrefixIsRejectedInBothModes(): void
    {
        // `__c_` is the engine's namespace in the render frame: binding one would
        // swap an internal for the rest of the render (e.g. the callable registry).
        foreach ([self::openEngine(), self::sandboxedEngine()] as $engine) {
            self::tpl('om_set_internal_' . ($engine->isSandboxed() ? 'sb' : 'op'), "{% set __c_fn = 'x' %}");

            try {
                $engine->renderPartial('om_set_internal_' . ($engine->isSandboxed() ? 'sb' : 'op'));
                $this->fail('__c_-prefixed binding must be rejected');
            } catch (ClarityException $e) {
                $this->assertStringContainsString("'__c_' are reserved", $e->getMessage());
            }
        }
    }

    public function testDoubleUnderscoreNamesAreFreeForTemplates(): void
    {
        // Only the engine's own prefix is reserved; ordinary `__` names are
        // regular template variables again (previously all of `__` was blocked).
        self::tpl('om_dd_free', '{% set __helper = "h" %}{{ __helper }}|{% for __x in items %}{{ __x }}{% endfor %}');

        foreach ([self::openEngine(), self::sandboxedEngine()] as $engine) {
            $this->assertSame(
                'h|ab',
                $engine->renderPartial('om_dd_free', ['items' => ['a', 'b']])
            );
        }
    }

    public function testSetOfAnOrdinaryNameStillCompiles(): void
    {
        // Negative control for the new lvalue validation: an ordinary name must
        // still bind, in both modes, so the check is not over-eager.
        self::tpl('om_set_plain', '{% set custom = "c" %}{{ custom }}');

        foreach ([self::openEngine(), self::sandboxedEngine()] as $engine) {
            $this->assertSame('c', $engine->renderPartial('om_set_plain'));
        }
    }

    // =========================================================================
    // ${expr} follows the same variable model as every other access
    // =========================================================================

    public function testDynamicLookupResolvesTheScopeInOpenMode(): void
    {
        self::tpl('om_dyn_open', '{{ ${which} }}');
        $this->assertSame('v', self::openEngine()->renderPartial('om_dyn_open', ['which' => 'x', 'x' => 'v']));
    }

    public function testDynamicLookupThrowsForAnAbsentNameInOpenMode(): void
    {
        self::tpl('om_dyn_absent', '{{ ${which} }}');

        $this->expectException(ClarityException::class);
        self::openEngine()->renderPartial('om_dyn_absent', ['which' => 'nope']);
    }

    public function testDynamicLookupCoalescesInOpenMode(): void
    {
        self::tpl('om_dyn_fallback', "{{ \${which} ?? 'fb' }}");
        $this->assertSame('fb', self::openEngine()->renderPartial('om_dyn_fallback', ['which' => 'nope']));
    }

    // =========================================================================
    // Mode change invalidates the compiled cache
    // =========================================================================

    public function testModeFlipRecompilesCachedTemplate(): void
    {
        self::tpl('om_flip', "{{ 'ab' |> strtoupper }}");

        // Compile it sandboxed first (must fail: unknown filter).
        try {
            self::sandboxedEngine()->renderPartial('om_flip');
            $this->fail('sandboxed render should have failed');
        } catch (ClarityException) {}

        // The same cache entry must NOT be reused in open mode.
        $this->assertSame('AB', self::openEngine()->renderPartial('om_flip'));
    }

    public function testCompiledMarkerRecordsSandboxedMode(): void
    {
        self::tpl('om_mark_sandbox', '{{ name }}');
        $engine = self::sandboxedEngine();
        $engine->renderPartial('om_mark_sandbox', ['name' => 'x']);

        $this->assertTrue(self::loadedClassFlag($engine, 'om_mark_sandbox', 'sandboxCompiled'));
    }

    public function testCompiledMarkerRecordsOpenMode(): void
    {
        self::tpl('om_mark_open', '{{ name }}');
        $engine = self::openEngine();
        $engine->renderPartial('om_mark_open', ['name' => 'x']);

        $this->assertFalse(self::loadedClassFlag($engine, 'om_mark_open', 'sandboxCompiled'));
    }

    /**
     * Read a static flag from the compiled class the engine loaded for $view.
     */
    private static function loadedClassFlag(TestClarityEngine $engine, string $view, string $flag): bool
    {
        $prop = new \ReflectionProperty($engine, 'cache');
        $prop->setAccessible(true);

        /** @var \Clarity\Engine\Cache $cache */
        $cache     = $prop->getValue($engine);
        $className = $cache->getLoadedClassName($view);

        self::assertIsString($className, 'the template must be compiled and loaded');

        return (bool) $className::$$flag;
    }
}