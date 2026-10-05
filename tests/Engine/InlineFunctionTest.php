<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Engine\Policy;
use Clarity\Engine\Registry;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;
use Clarity\Tests\TestEnvironment;

/**
 * Inline FUNCTIONS: codegen that compiles into the template but is NOT reachable
 * with the pipe operator (`filter => false`, registered via
 * `addInlineFunction()`).
 *
 * `isset` is the built-in in this shape, and it is the case the flag exists for.
 * It is a presence probe on a variable chain, so its argument is SOURCE the
 * compiler must see, not a value to transform — `x |> isset` would ask whether
 * the already-compiled piped expression exists, which is a constant.
 *
 * The two contracts pinned here:
 *   1. `isset(x)` answers presence and tolerates an absent name (like `is defined`).
 *   2. `x |> isset` is a COMPILE-TIME error, not a runtime surprise.
 */
class InlineFunctionTest extends BaseTestCase
{
    private static function openEngine(): TestClarityEngine
    {
        return new TestClarityEngine([
            'viewPath'  => TestEnvironment::viewDir(),
            'cachePath' => TestEnvironment::cacheDir(),
            'extension' => 'clarity.html',
            'policy'    => Policy::unrestricted(),
        ]);
    }

    // =========================================================================
    // Present / absent
    // =========================================================================

    public function testIsSetIsTrueForAPresentName(): void
    {
        self::tpl('ifn_present', '{{ isset(name) }}');
        $this->assertSame('1', self::render('ifn_present', ['name' => 'x']));
    }

    public function testIsSetIsFalseForAnAbsentNameWithoutThrowing(): void
    {
        // The whole point of a presence probe: an absent name is a `false`, not
        // the "Variable … is not defined" a strict read would raise.
        self::tpl('ifn_absent', '{{ isset(nope) }}');
        $this->assertSame('', self::render('ifn_absent'));
    }

    /**
     * `isset` is a presence test, not a null test: a name holding `null` is
     * reported absent. That is the same contract `is defined` states, because it
     * is the same PHP probe.
     */
    public function testIsSetIsFalseForExplicitNull(): void
    {
        self::tpl('ifn_null', '{{ isset(user) }}');
        $this->assertSame('', self::render('ifn_null', ['user' => null]));
        $this->assertSame('1', self::render('ifn_null', ['user' => 'x']));
    }

    // =========================================================================
    // Chains: every access operator the engine compiles is a legal operand
    // =========================================================================

    public function testIsSetOnAnArrayKeyChain(): void
    {
        // `user:email` compiles to a plain `$__c_va['user']['email']`, which is
        // exactly the operand PHP's isset() wants — the guard runs on the
        // COMPILED read, so the `:` spelling works despite looking like a named
        // argument in the template source.
        self::tpl('ifn_key', '{{ isset(user:email) }}');
        $this->assertSame('1', self::render('ifn_key', ['user' => ['email' => 'a@b.c']]));
        $this->assertSame('', self::render('ifn_key', ['user' => []]));
    }

    public function testIsSetOnAnObjectPropertyChain(): void
    {
        $obj = new \stdClass();
        $obj->email = 'a@b.c';

        self::tpl('ifn_prop', '{{ isset(user.email) }}');
        $this->assertSame('1', self::render('ifn_prop', ['user' => $obj]));

        $empty = new \stdClass();
        $this->assertSame('', self::render('ifn_prop', ['user' => $empty]));
    }

    public function testIsSetOnAnIndexChain(): void
    {
        self::tpl('ifn_index', '{{ isset(items[0]) }}');
        $this->assertSame('1', self::render('ifn_index', ['items' => ['a']]));
        $this->assertSame('', self::render('ifn_index', ['items' => []]));
    }

    public function testIsSetOnANestedChain(): void
    {
        self::tpl('ifn_nested', '{{ isset(a:b:c) }}');
        $this->assertSame('1', self::render('ifn_nested', ['a' => ['b' => ['c' => 1]]]));
        $this->assertSame('', self::render('ifn_nested', ['a' => ['b' => []]]));
    }

    // =========================================================================
    // Non-operands are refused at COMPILE time, not as a PHP fatal
    // =========================================================================

    /**
     * PHP rejects `isset()` on an expression at its own lexer level ("Cannot use
     * isset() on the result of an expression"). Before the call guard this
     * reached the compiled cache file and killed the request; the template author
     * gets a located ClarityException instead.
     *
     * @dataProvider nonPresenceOperands
     */
    public function testIsSetRejectsANonPresenceOperand(string $operand): void
    {
        self::tpl('ifn_bad_' . \md5($operand), '{{ isset(' . $operand . ') }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/needs a name or a name chain/');
        self::render('ifn_bad_' . \md5($operand));
    }

    public static function nonPresenceOperands(): array
    {
        return [
            'arithmetic' => ['1 + 1'],
            'literal'    => ["'x'"],
            'zero'       => ['0'],
            'ternary'    => ['a ? b : c'],
        ];
    }

    public function testIsSetRejectsASecondArgument(): void
    {
        self::tpl('ifn_two_args', '{{ isset(a, b) }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/requires exactly one argument/');
        self::render('ifn_two_args', ['a' => 1, 'b' => 2]);
    }

    // =========================================================================
    // Call-only: the pipe form is refused
    // =========================================================================

    public function testIsSetIsNotUsableAsAFilter(): void
    {
        self::tpl('ifn_pipe', '{{ name |> isset }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/is a function, not a filter/');
        self::render('ifn_pipe', ['name' => 'x']);
    }

    public function testIsSetIsNotUsableAsAFilterInOpenMode(): void
    {
        self::tpl('ifn_pipe_open', '{{ name |> isset }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/is a function, not a filter/');
        self::openEngine()->renderPartial('ifn_pipe_open', ['name' => 'x']);
    }

    // =========================================================================
    // Codegen: no runtime dispatch
    // =========================================================================

    public function testIsSetCompilesIntoAPlainPhpIsset(): void
    {
        self::tpl('ifn_codegen', '{{ isset(user:email) }}');
        self::render('ifn_codegen', ['user' => ['email' => 'a@b.c']]);

        $source = $this->compiledSource('ifn_codegen');
        $this->assertStringContainsString("isset(", $source);
        $this->assertStringContainsString("\$__c_va['user']['email']", $source);
        $this->assertStringNotContainsString("\$__c_fn['isset']", $source);
    }

    // =========================================================================
    // The registration API
    // =========================================================================

    public function testAddInlineFunctionCompilesButIsNotFilterable(): void
    {
        $engine = self::openEngine();
        $engine->addInlineFunction('present', [
            'php'       => 'isset({1})',
            'callGuard' => 'presence',
        ]);

        self::tpl('ifn_custom_ok', '{{ present(thing) }}');
        $this->assertSame('1', $engine->renderPartial('ifn_custom_ok', ['thing' => 1]));
        $this->assertSame('', $engine->renderPartial('ifn_custom_ok'));

        self::tpl('ifn_custom_pipe', '{{ thing |> present }}');
        try {
            $engine->renderPartial('ifn_custom_pipe', ['thing' => 1]);
            $this->fail('an inline function must not be pipeable');
        } catch (ClarityException $e) {
            $this->assertStringContainsString('is a function, not a filter', $e->getMessage());
        }
    }

    public function testAddInlineFunctionRegistersNoRuntimeCallable(): void
    {
        $registry = new Registry();

        $registry->addInlineFunction('only_call', ['php' => 'f({1})']);

        $this->assertTrue($registry->hasCallable('only_call'));
        $this->assertTrue($registry->isInlineFunction('only_call'));
        $this->assertFalse($registry->hasFilter('only_call'));
        $this->assertArrayNotHasKey('only_call', $registry->allCallables());
    }
}
