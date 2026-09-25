<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestEnvironment;

/**
 * The strict access syntax: object properties, array keys, dynamic access,
 * optional forms, the dollar sigil, and container operations.
 *
 * These are the contract tests. Most of them assert a THROW as often as a value,
 * because "strict" means a wrong operator is an error rather than a silent null —
 * and a silent null is the failure mode that looks like working code.
 *
 * The emitted-PHP assertions exist because the whole point of the change is that
 * a property read costs one property access. A future refactor could restore the
 * eager object→array conversion, render identical pages, and only show up as a
 * benchmark regression; pinning the emitted code is what makes that visible.
 */
class AccessSyntaxTest extends BaseTestCase
{
    // =========================================================================
    // Object property access (`.`)
    // =========================================================================

    public function testObjectPropertyAccess(): void
    {
        $obj = new \stdClass();
        $obj->name = 'Alice';

        self::tpl('a_prop', '{{ user.name }}');
        $this->assertSame('Alice', self::render('a_prop', ['user' => $obj]));
    }

    public function testNestedObjectPropertyAccess(): void
    {
        $inner = new \stdClass();
        $inner->city = 'Berlin';
        $outer = new \stdClass();
        $outer->address = $inner;

        self::tpl('a_nested', '{{ user.address.city }}');
        $this->assertSame('Berlin', self::render('a_nested', ['user' => $outer]));
    }

    public function testMissingPropertyThrows(): void
    {
        self::tpl('a_missing', '{{ user.nope }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/nope/');
        self::render('a_missing', ['user' => new \stdClass()]);
    }

    public function testPropertyAccessOnArrayThrows(): void
    {
        self::tpl('a_prop_on_arr', '{{ user.name }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Cannot read property "name" on array/');
        self::render('a_prop_on_arr', ['user' => ['name' => 'Alice']]);
    }

    // =========================================================================
    // Array key access (`:`)
    // =========================================================================

    public function testStaticKeyAccess(): void
    {
        self::tpl('a_key', '{{ user:name }}');
        $this->assertSame('Alice', self::render('a_key', ['user' => ['name' => 'Alice']]));
    }

    public function testNestedStaticKeyAccess(): void
    {
        self::tpl('a_keys', '{{ a:b:c }}');
        $this->assertSame('deep', self::render('a_keys', ['a' => ['b' => ['c' => 'deep']]]));
    }

    public function testKeyAccessAfterIndex(): void
    {
        self::tpl('a_key_after_idx', '{{ items[0]:name }}');
        $this->assertSame(
            'first',
            self::render('a_key_after_idx', ['items' => [['name' => 'first'], ['name' => 'second']]])
        );
    }

    public function testMissingKeyThrows(): void
    {
        self::tpl('a_missing_key', '{{ user:nope }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/nope/');
        self::render('a_missing_key', ['user' => []]);
    }

    /**
     * A key holding NULL is PRESENT, so a strict read returns null and does not
     * throw. This is why the engine uses array_key_exists() rather than isset().
     */
    public function testNullValuedKeyDoesNotThrow(): void
    {
        self::tpl('a_null_key', '[{{ user:nickname ?? "fallback" }}]');
        $this->assertSame(
            '[fallback]',
            self::render('a_null_key', ['user' => ['nickname' => null]])
        );
    }

    // =========================================================================
    // Array index (`[ ]`)
    // =========================================================================

    public function testNumericIndex(): void
    {
        self::tpl('a_idx', '{{ items[1] }}');
        $this->assertSame('b', self::render('a_idx', ['items' => ['a', 'b', 'c']]));
    }

    public function testDynamicIndex(): void
    {
        self::tpl('a_dyn_idx', '{{ items[pick] }}');
        $this->assertSame('c', self::render('a_dyn_idx', ['items' => ['a', 'b', 'c'], 'pick' => 2]));
    }

    public function testStringKeyViaBracket(): void
    {
        self::tpl('a_bracket_str', "{{ data['odd key'] }}");
        $this->assertSame('v', self::render('a_bracket_str', ['data' => ['odd key' => 'v']]));
    }

    // =========================================================================
    // Dynamic property access (`{ }`)
    // =========================================================================

    public function testDynamicPropertyAccess(): void
    {
        $obj = new \stdClass();
        $obj->name = 'Bob';

        self::tpl('a_dyn_prop', '{{ user{field} }}');
        $this->assertSame('Bob', self::render('a_dyn_prop', ['user' => $obj, 'field' => 'name']));
    }

    public function testDynamicPropertyAccessWithLiteral(): void
    {
        $obj = new \stdClass();
        $obj->age = 40;

        self::tpl('a_dyn_prop_lit', "{{ user{'age'} }}");
        $this->assertSame('40', self::render('a_dyn_prop_lit', ['user' => $obj]));
    }

    // =========================================================================
    // The dollar sigil
    // =========================================================================

    public function testDollarSigilWithArrow(): void
    {
        $obj = new \stdClass();
        $obj->name = 'Carol';

        self::tpl('a_sigil_arrow', '{{ $user->name }}');
        $this->assertSame('Carol', self::render('a_sigil_arrow', ['user' => $obj]));
    }

    public function testDollarSigilWithDot(): void
    {
        $obj = new \stdClass();
        $obj->name = 'Carol';

        self::tpl('a_sigil_dot', '{{ $user.name }}');
        $this->assertSame('Carol', self::render('a_sigil_dot', ['user' => $obj]));
    }

    public function testArrowWithoutSigilIsACompileError(): void
    {
        self::tpl('a_bare_arrow', '{{ user->name }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/requires the \$ sigil/');
        self::render('a_bare_arrow', ['user' => new \stdClass()]);
    }

    /**
     * `?->` is three characters, so a check that looks only at the character
     * after `?` never sees it. Without this, `{{ a?->b }}` emitted raw PHP
     * nullsafe syntax — the same class of leak the sigil rule exists to prevent,
     * reachable through the OPTIONAL path instead of the plain one.
     */
    public function testOptionalArrowWithoutSigilIsACompileError(): void
    {
        self::tpl('a_bare_opt_arrow', '{{ user?->name }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/requires the \$ sigil/');
        self::render('a_bare_opt_arrow', ['user' => new \stdClass()]);
    }

    public function testOptionalArrowWithSigilIsAllowed(): void
    {
        $obj = new \stdClass();
        $obj->name = 'Zoe';

        self::tpl('a_sigil_opt_arrow', '{{ $user?->name }}');
        $this->assertSame('Zoe', self::render('a_sigil_opt_arrow', ['user' => $obj]));
    }

    public function testOptionalArrowWithSigilToleratesAnAbsentReceiver(): void
    {
        self::tpl('a_sigil_opt_arrow_missing', '[{{ $user?->name ?? "none" }}]');
        $this->assertSame('[none]', self::render('a_sigil_opt_arrow_missing'));
        $this->assertSame('[none]', self::render('a_sigil_opt_arrow_missing', ['user' => null]));
    }

    /**
     * The nullsafe operator IS the emission for object optional access (unlike a
     * raw-PHP LEAK, which the sigil rule prevents). Asserted on the output so a
     * future reimplementation has to justify itself against the behavioural
     * tests rather than silently changing the emission.
     */
    public function testOptionalObjectAccessUsesTheNullsafeOperator(): void
    {
        self::tpl('a_opt_emits_nullsafe', '{{ user?.address?.city }}');
        $address = new \stdClass();
        $address->city = 'C';
        $user = new \stdClass();
        $user->address = $address;

        $this->assertSame('C', self::render('a_opt_emits_nullsafe', ['user' => $user]));

        $body = $this->compiledBody('a_opt_emits_nullsafe');
        $this->assertStringContainsString('?->city', $body, 'a mid-chain optional read is a nullsafe read');
        $this->assertStringNotContainsString('?? null', $body, 'the member read must stay strict');
    }

    /**
     * `->` must never provide a way to CALL anything.
     */
    public function testMethodCallViaArrowIsRejected(): void
    {
        self::tpl('a_method', '{{ $user->getName() }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Method calls are not allowed/');
        self::render('a_method', ['user' => new \stdClass()]);
    }

    public function testBareDollarIsRejected(): void
    {
        self::tpl('a_bare_dollar', '{{ $ }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Direct PHP variable access/');
        self::render('a_bare_dollar');
    }

    // =========================================================================
    // Optional access
    // =========================================================================

    /**
     * `?` guards the RECEIVER. An absent or null receiver yields null, and the
     * member read that follows is STRICT (asserted in
     * testOptionalAccessOnlyGuardsTheReceiver).
     */
    public function testOptionalPropertyToleratesAnAbsentReceiver(): void
    {
        self::tpl('a_opt_prop', '[{{ user.missing ?? "none" }}]');
        $this->assertSame('[none]', self::render('a_opt_prop', ['user' => new \stdClass()]));
    }

    public function testOptionalPropertyViaQuestionDotToleratesAnAbsentReceiver(): void
    {
        self::tpl('a_opt_qdot', '[{{ user?.name ?? "none" }}]');
        $this->assertSame('[none]', self::render('a_opt_qdot'));
        $this->assertSame('[none]', self::render('a_opt_qdot', ['user' => null]));
    }

    /**
     * A mid-chain receiver may be PRESENT BUT NULL, which `?` tolerates — the
     * absent case and the null case are the same for a nullsafe operator.
     */
    public function testOptionalChainIsComposable(): void
    {
        $obj = new \stdClass();
        $obj->name    = 'x';
        $obj->address = null;

        self::tpl('a_opt_chain', '[{{ user?.address?.city ?? "none" }}]');
        $this->assertSame('[none]', self::render('a_opt_chain', ['user' => $obj]));
        $this->assertSame('[none]', self::render('a_opt_chain'));
    }

    public function testOptionalChainStillReadsPresentValues(): void
    {
        $address = new \stdClass();
        $address->city = 'Rome';
        $obj = new \stdClass();
        $obj->address = $address;

        self::tpl('a_opt_chain_ok', '{{ user?.address?.city }}');
        $this->assertSame('Rome', self::render('a_opt_chain_ok', ['user' => $obj]));
    }

    /**
     * `items?[9]` tolerates only the RECEIVER. With `items` present, index 9 is a
     * strict read and an out-of-range index is reported (use `?? "none"` for a
     * positional fallback). With `items` absent, the `?` yields null.
     */
    public function testOptionalIndexGuardsTheReceiverNotTheIndex(): void
    {
        self::tpl('a_opt_idx', '[{{ items?[9] }}]');
        $this->assertSame('[]', self::render('a_opt_idx'));
        $this->assertSame('[]', self::render('a_opt_idx', ['items' => null]));

        $this->expectException(ClarityException::class);
        self::render('a_opt_idx', ['items' => ['a']]);
    }

    public function testOptionalIndexReadsPresentValues(): void
    {
        self::tpl('a_opt_idx_ok', '{{ items?[1] }}');
        $this->assertSame('b', self::render('a_opt_idx_ok', ['items' => ['a', 'b']]));
    }

    /**
     * The array side has a documented ASYMMETRY against the object side, and this
     * test pins it so it cannot regress silently.
     *
     * The array guard is a ternary (`isset(R) ? R[k] : null`), and a branch is
     * EVALUATED before an outer operator sees it — so `R?[k] ?? 'fb'` cannot
     * suppress a missing key: the branch reads it and warns first. On the object
     * side the guard is the native `?->`, which composes with a following `??`,
     * so `R?->k ?? 'fb'` DOES work.
     *
     * The escape hatch on the array side is to coalesce a STRICT read: `R[k] ?? 'fb'`
     * is member-tolerant with no warning. Use `?` only when the RECEIVER may be
     * absent, and `??` when the MEMBER may be.
     */
    public function testOptionalArrayAccessComposesWithCoalesceOnlyForTheReceiver(): void
    {
        self::tpl('a_opt_idx_fb', '[{{ items?[9] ?? "fb" }}]');
        // receiver absent/null -> `?` handles it, and `??` still supplies the value
        $this->assertSame('[fb]', self::render('a_opt_idx_fb'));

        // receiver PRESENT but the index missing -> the strict read reports it
        try {
            self::render('a_opt_idx_fb', ['items' => ['a']]);
            $this->fail('a missing index is a strict read and must report');
        } catch (ClarityException $e) {
            $this->assertMatchesRegularExpression('/9/', $e->getMessage());
        }

        // the member-tolerant spelling: `??` on the STRICT read
        self::tpl('a_idx_fb', '[{{ items[9] ?? "fb" }}]');
        $this->assertSame('[fb]', self::render('a_idx_fb', ['items' => ['a']]));
        $this->assertSame('[j]', self::render('a_idx_fb', ['items' => range('a', 'j')]));
    }

    public function testOptionalStaticKeyToleratesAnAbsentReceiver(): void
    {
        self::tpl('a_opt_key', '[{{ user?:name ?? "none" }}]');
        $this->assertSame('[none]', self::render('a_opt_key'));
        $this->assertSame('[none]', self::render('a_opt_key', ['user' => null]));
    }

    /**
     * The optional form must distinguish ABSENT from PRESENT-BUT-NULL, exactly as
     * the strict form does. `?? "none"` would collapse both, so the render below
     * omits the fallback: a null-valued key must render empty, not throw.
     */
    public function testOptionalStaticKeyKeepsNullValuedKey(): void
    {
        self::tpl('a_opt_key_null', '[{{ user?:name }}]');
        $this->assertSame('[]', self::render('a_opt_key_null', ['user' => ['name' => null]]));
    }

    public function testOptionalDynamicPropertyKeepsNullValuedProperty(): void
    {
        $obj = new \stdClass();
        $obj->name = null;

        self::tpl('a_opt_dyn_null', '[{{ user?{field} }}]');
        $this->assertSame('[]', self::render('a_opt_dyn_null', ['user' => $obj, 'field' => 'name']));
    }

    public function testOptionalDynamicPropertyToleratesAnAbsentReceiver(): void
    {
        self::tpl('a_opt_dyn', '[{{ user?{field} ?? "none" }}]');
        $this->assertSame('[none]', self::render('a_opt_dyn', ['field' => 'name']));
        $this->assertSame('[none]', self::render('a_opt_dyn', ['user' => null, 'field' => 'name']));
    }

    // =========================================================================
    // `:` and the ternary
    // =========================================================================

    public function testSpacedTernaryColonWorks(): void
    {
        self::tpl('a_ternary', '{{ ok ? "yes" : "no" }}');
        $this->assertSame('yes', self::render('a_ternary', ['ok' => true]));
        $this->assertSame('no', self::render('a_ternary', ['ok' => false]));
    }

    public function testNamedFilterArgumentIsNotAChain(): void
    {
        // `decimals:2` must stay a named argument, not become a key chain.
        self::tpl('a_named_arg', '{{ price |> number(decimals=0) }}');
        $this->assertSame('10', self::render('a_named_arg', ['price' => 9.6]));
    }

    // =========================================================================
    // Container operations are LAZY
    // =========================================================================

    public function testObjectIteratesItsPublicProperties(): void
    {
        $obj = new class
        {
            public string $a = '1';
            public string $b = '2';
            private string $secret = 'no';
        };

        self::tpl('a_iter_obj', '{% for k, v in data %}{{ k }}={{ v }};{% endfor %}');
        $this->assertSame('a=1;b=2;', self::render('a_iter_obj', ['data' => $obj]));
    }

    public function testLengthCountsArrayEntries(): void
    {
        self::tpl('a_len_arr', '{{ items |> length }}');
        $this->assertSame('3', self::render('a_len_arr', ['items' => [1, 2, 3]]));
    }

    public function testLengthCountsObjectProperties(): void
    {
        $obj = new \stdClass();
        $obj->a = 1;
        $obj->b = 2;

        self::tpl('a_len_obj', '{{ data |> length }}');
        $this->assertSame('1', self::render('a_len_obj', ['data' => $obj]));
    }

    public function testKeysAndValuesOnObject(): void
    {
        $obj = new \stdClass();
        $obj->a = 1;
        $obj->b = 2;

        self::tpl('a_keys_obj', '{{ data |> keys |> join(",") }}|{{ data |> values |> join(",") }}');
        $this->assertSame('a,b|1,2', self::render('a_keys_obj', ['data' => $obj]));
    }

    public function testInvalidIndexKeyIsReportedNotSilent(): void
    {
        // A non-existent nested index on a string raises a PHP error that the
        // engine must map, rather than resolving to an empty string.
        self::tpl('a_bad_idx', "{{ 'abc'[bad] }}");

        $this->expectException(ClarityException::class);
        self::render('a_bad_idx', ['bad' => 5]);
    }

    // =========================================================================
    // Emitted PHP: the conversion must NOT come back
    // =========================================================================

    /**
     * A property-only template must emit NO container call. If someone later
     * "simplifies" by converting the whole scope in renderPartial(), the output
     * would be identical and only a benchmark would notice — so the emitted code
     * is pinned instead.
     */
    public function testPropertyOnlyTemplateEmitsNoContainerCall(): void
    {
        $obj = new \stdClass();
        $obj->name    = 'x';
        $obj->address = $obj;

        self::tpl('a_emit_prop', '{{ user.name }}{{ user.address.name }}');
        self::render('a_emit_prop', ['user' => $obj]);

        $body = $this->compiledBody('a_emit_prop');
        $this->assertStringNotContainsString('Access::', $body, 'a property read must not touch the container runtime');
        $this->assertStringContainsString('$__va[\'user\']->name', $body);
    }

    public function testArrayKeyTemplateEmitsNoContainerCall(): void
    {
        self::tpl('a_emit_key', '{{ user:name }}');
        self::render('a_emit_key', ['user' => ['name' => 'x']]);

        $body = $this->compiledBody('a_emit_key');
        $this->assertStringNotContainsString('Access::', $body);
        $this->assertStringContainsString('$__va[\'user\'][\'name\']', $body);
    }

    /**
     * Strictness must hold inside a CONDITION too, not only in an echo. A
     * missing key there silently evaluates falsy, which is the failure mode the
     * strict contract exists to prevent (it looks like working code). The
     * condition path is compiled separately from the echo path, so it is
     * asserted separately.
     */
    public function testMissingAccessInAConditionThrows(): void
    {
        self::tpl('a_if_missing_key', '{% if user:nope %}A{% else %}B{% endif %}');
        self::tpl('a_if_missing_prop', '{% if user.nope %}A{% else %}B{% endif %}');

        try {
            self::render('a_if_missing_key', ['user' => []]);
            $this->fail('a missing key in a condition must throw, not evaluate falsy');
        } catch (ClarityException $e) {
            $this->assertMatchesRegularExpression('/nope/', $e->getMessage());
        }

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/nope/');
        self::render('a_if_missing_prop', ['user' => new \stdClass()]);
    }

    /** Return the compiled render body for a template name. */
    private function compiledBody(string $view): string
    {
        $cache = new \ReflectionProperty(TestEnvironment::engine(), 'cache');
        $cache->setAccessible(true);
        $className = $cache->getValue(TestEnvironment::engine())->getLoadedClassName($view);
        $this->assertIsString($className, 'the template must be compiled and loaded');

        $file  = (new \ReflectionClass($className))->getFileName();
        $src   = (string) file_get_contents($file);
        $start = strpos($src, 'try {');
        $end   = strpos($src, 'return (string) ob_get_clean();');
        $this->assertNotFalse($start, 'the compiled class must contain the render body');
        $this->assertNotFalse($end, 'the compiled class must contain the render body');

        return substr($src, $start, $end - $start);
    }

    // =========================================================================
    // Optional access guards the RECEIVER only
    // =========================================================================

    /**
     * THE CONTRACT. `?` captures the RECEIVER: an absent or null receiver yields
     * null. The member read stays STRICT, so a present receiver that lacks the
     * member is REPORTED rather than silently nulled.
     *
     * This is what separates `user?.name` from `user.name ?? 'x'`: the former
     * tolerates a missing user but not a missing name; the latter tolerates
     * both. Getting it backwards makes every mistyped property read render
     * empty instead of failing — the exact failure mode the strict design
     * exists to prevent.
     */
    public function testOptionalAccessOnlyGuardsTheReceiver(): void
    {
        $ok = new \stdClass();
        $ok->name = 'x';

        self::tpl('a_opt_present', '[{{ user?.name }}]');
        self::tpl('a_opt_null_recv', '[{{ user?.name }}]');
        self::tpl('a_strict_null_recv', '[{{ user.name }}]');

        $this->assertSame('[x]', self::render('a_opt_present', ['user' => $ok]));

        // receiver null -> tolerated by `?.`
        $this->assertSame('[]', self::render('a_opt_null_recv', ['user' => null]));

        // the same shape without `?` reports the null receiver
        try {
            self::render('a_strict_null_recv', ['user' => null]);
            $this->fail('a strict read on a null receiver must report');
        } catch (ClarityException $e) {
            $this->assertMatchesRegularExpression('/null/', $e->getMessage());
        }
    }

    /**
     * A present-but-wrong receiver is NOT tolerated: the member read is strict.
     * Same rule for an OBJECT receiver and an ARRAY receiver — otherwise `?`
     * would be a blanket "never report anything" switch.
     */
    public function testOptionalObjectAccessReportsAMissingProperty(): void
    {
        self::tpl('a_opt_obj_member', '[{{ user?.name }}]');
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Property "name" is not defined/');
        self::render('a_opt_obj_member', ['user' => new \stdClass()]);
    }

    public function testOptionalArrayAccessReportsAMissingKey(): void
    {
        self::tpl('a_opt_arr_member', '[{{ user?:name }}]');
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/"name" is not defined/');
        self::render('a_opt_arr_member', ['user' => []]);
    }

    /**
     * Member tolerance is the OTHER contract, and it is spelled with `??` on a
     * read whose receiver is known to exist: `list:k ?? 'fb'` yields the fallback
     * whether the key is missing or null, and PHP's native `??` does that with no
     * warning. So the two contracts stay separable:
     *
     *   receiver may be absent  -> `?`   (member must then exist)
     *   member   may be absent  -> `??`  (receiver must then exist)
     *
     * This is why `?` must not ALSO swallow a missing member: doing so collapses
     * the two into one operator and makes a mistyped key indistinguishable from
     * an absent one.
     */
    public function testCoalesceOnAStrictReadSuppliesTheMemberFallback(): void
    {
        self::tpl('a_key_fallback', '[{{ list:k ?? "fb" }}]');

        $this->assertSame('[fb]', self::render('a_key_fallback', ['list' => []]));
        $this->assertSame('[fb]', self::render('a_key_fallback', ['list' => ['k' => null]]));
        $this->assertSame('[v]', self::render('a_key_fallback', ['list' => ['k' => 'v']]));
    }

    /**
     * The array side cannot use a nullsafe operator, so it guards with isset().
     * isset() on a bare root also makes an ABSENT ROOT a clean false, which is
     * the tolerance `?` promises on that side.
     */
    public function testOptionalArrayAccessUsesAnIssetGuard(): void
    {
        self::tpl('a_opt_emits_isset', '{{ list?:k }}');
        $this->assertSame('V', self::render('a_opt_emits_isset', ['list' => ['k' => 'V']]));

        $body = $this->compiledBody('a_opt_emits_isset');
        $this->assertStringContainsString("isset(\$__va['list'])", $body);
        $this->assertStringNotContainsString('?? null', $body);
    }

    /**
     * THE SIZE GUARD. A guard form must never re-embed its receiver. An earlier
     * revision duplicated the receiver once per segment: the object side grew as
     * 3^N (seven optional segments emitted 31 907 characters for one read) and,
     * after a partial fix, the array side as 2^N (six segments emitted 2 245).
     *
     * Both are pinned HERE because they are invisible to a behavioural test: the
     * output is identical, and only the size (i.e. what must be parsed and
     * opcached on every request) shows the regression.
     */
    public function testOptionalChainCodeStaysLinear(): void
    {
        $objChain = 'a?.b';
        $arrChain = 'a?:b';
        for ($i = 2; $i <= 7; $i++) {
            $objChain .= '?.x' . $i;
            $arrChain .= '?:x' . $i;
        }

        self::tpl('a_opt_size_obj', '{{ ' . $objChain . ' }}');
        self::tpl('a_opt_size_arr', '{{ ' . $arrChain . ' }}');
        self::render('a_opt_size_obj', ['a' => null]);
        self::render('a_opt_size_arr', ['a' => null]);

        $this->assertLessThan(600, \strlen($this->compiledBody('a_opt_size_obj')), 'object chain must stay linear');
        $this->assertLessThan(1200, \strlen($this->compiledBody('a_opt_size_arr')), 'array chain must stay linear');
    }

    /**
     * A STRICT continuation after an optional one must read THROUGH the optional
     * segment: `a?.b.c` with a present `a` has to reach `c`. It must also still
     * report a missing `b`, because only the `?`-marked segment is tolerant.
     */
    public function testStrictSegmentAfterOptionalReadsThrough(): void
    {
        $inner = new \stdClass();
        $inner->c = 'deep';
        $obj = new \stdClass();
        $obj->b = $inner;
        $objNoB = new \stdClass();

        self::tpl('a_opt_then_strict', '[{{ a?.b.c }}]');

        $this->assertSame('[deep]', self::render('a_opt_then_strict', ['a' => $obj]));

        // `a` present but without `b`: the unmarked read must report it.
        try {
            self::render('a_opt_then_strict', ['a' => $objNoB]);
            $this->fail('a strict read after an optional segment must report a missing member');
        } catch (ClarityException $e) {
            $this->assertMatchesRegularExpression('/Property "b" is not defined/', $e->getMessage());
        }

        // `a` null: only the `?`-marked segment is tolerant, so the strict `.c`
        // that follows reports the null it was handed.
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/null/');
        self::render('a_opt_then_strict', ['a' => null]);
    }

    /**
     * `??` is a value-level suppression, so an optional read returns null for an
     * absent root INSTEAD of the "Variable is not defined" error. That is the
     * intended semantics of the operator, and it must not extend to the strict
     * form, which still reports the missing variable (the strict form carries no
     * `??`; a trailing `?? fallback` is the author asking for the same silence).
     */
    public function testOptionalRootVariableIsToleratedButStrictRootIsNot(): void
    {
        self::tpl('a_opt_root', '[{{ missing?.name ?? "none" }}]');
        $this->assertSame('[none]', self::render('a_opt_root'));

        self::tpl('a_strict_root', '[{{ missing.name }}]');
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/"missing" is not defined/');
        self::render('a_strict_root');
    }
}
