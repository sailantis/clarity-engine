<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Tests\BaseTestCase;

/**
 * Chain syntax: how a continuation is spelled.
 *
 * `docs/examples/04-loops.clarity.html` — the most-copied template in the
 * documentation — used `{{ user : name }}` throughout and did NOT compile. The
 * rule that rejected it existed only to keep `a ? b : c` from reading as a key
 * chain, and it did that by forbidding whitespace rather than by tracking the
 * ternary. These tests pin the rule that replaced it, because the failure mode
 * is a template that compiles to something OTHER than what it says:
 *
 *   `user . name`  used to emit PHP concatenation and render the WRONG VALUE
 *   `user -> name` used to emit a dynamic property read of an array
 *   `config : version` used to emit PHP that cannot parse at all
 *
 * Only the last of those announced itself. The first two are the reason the
 * whitespace and dangling-operator cases below assert a THROW: a wrong value is
 * worse than a loud error.
 */
class ChainSyntaxTest extends BaseTestCase
{
    // =========================================================================
    // `.` is property access, never concatenation
    // =========================================================================

    public function testSpacedDotIsAPropertyRead(): void
    {
        $obj = new \stdClass();
        $obj->name = 'Alice';

        self::tpl('c_spaced_dot', '{{ user . name }}');
        $this->assertSame('Alice', self::render('c_spaced_dot', ['user' => $obj]));
    }

    public function testConcatenationRemainsTheTildeOperator(): void
    {
        self::tpl('c_tilde', '{{ a ~ b }}');
        $this->assertSame('AB', self::render('c_tilde', ['a' => 'A', 'b' => 'B']));
    }

    public function testSpacedDotDoesNotConcatenate(): void
    {
        // The regression this guards: `a . b` rendered the concatenation of the
        // raw values instead of reading a property, so a template could be
        // silently wrong and still look like it worked.
        self::tpl('c_dot_not_concat', '{{ a . b }}');

        $obj = new \stdClass();
        $obj->b = 'deep';
        $this->assertSame('deep', self::render('c_dot_not_concat', ['a' => $obj]));
    }

    public function testDanglingDotIsACompileError(): void
    {
        self::tpl('c_dangling_dot', '{{ user . }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/must be followed by a property name/');
        self::render('c_dangling_dot', ['user' => new \stdClass()]);
    }

    public function testDotAfterAParenthesisedOperandIsStillAPropertyRead(): void
    {
        // The tokenizer cannot tell from the previous character whether the
        // operand was a literal; it must refuse to guess rather than emit a
        // concatenation.
        self::tpl('c_dot_after_paren', "{{ ('a' ~ 'b') . length }}");

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/must be followed by a property name/');
        self::render('c_dot_after_paren');
    }

    public function testDecimalLiteralsKeepsTheirDot(): void
    {
        // The one reading of `.` that is not a chain: a float. `1234.56` has a
        // digit on both sides, so it is a number rather than a missing member.
        self::tpl('c_decimal', '{{ 1234.56 }}|{{ .5 }}');
        $this->assertSame('1234.56|0.5', self::render('c_decimal'));
    }

    // =========================================================================
    // Whitespace and line breaks inside a chain
    // =========================================================================

    public function testChainMayWrapAcrossLines(): void
    {
        $obj = new \stdClass();
        $obj->address = new \stdClass();
        $obj->address->city = 'Berlin';

        self::tpl('c_wrapped_dot', "{{ user.\naddress.\ncity }}");
        $this->assertSame('Berlin', self::render('c_wrapped_dot', ['user' => $obj]));
    }

    public function testKeyChainMayWrapAcrossLines(): void
    {
        self::tpl('c_wrapped_key', "{{ config:\nversion }}");
        $this->assertSame('1.2.3', self::render('c_wrapped_key', ['config' => ['version' => '1.2.3']]));
    }

    public function testWhitespaceBeforeAContinuationIsNotSignificant(): void
    {
        $obj = new \stdClass();
        $obj->name = 'Alice';

        self::tpl('c_space_variants', '{{ user.name }}|{{ user . name }}|{{ user. name }}|{{ user .name }}');
        $this->assertSame(
            'Alice|Alice|Alice|Alice',
            self::render('c_space_variants', ['user' => $obj])
        );
    }

    public function testSpaceBetweenOperandsDoesNotExtendAChain(): void
    {
        // `a - b` must stay arithmetic. The chain parser looks past whitespace
        // to find a continuation, so it has to stop when the next token is an
        // operator rather than a member.
        self::tpl('c_no_chain_on_operator', '{{ a - b }}|{{ a + b }}');
        $this->assertSame('1|7', self::render('c_no_chain_on_operator', ['a' => 4, 'b' => 3]));
    }

    public function testTernaryWithSpacedOperandsIsNotAChain(): void
    {
        self::tpl('c_tern_spaced', '{{ active ? "yes" : "no" }}');
        $this->assertSame('yes', self::render('c_tern_spaced', ['active' => true]));
        $this->assertSame('no', self::render('c_tern_spaced', ['active' => false]));
    }

    // =========================================================================
    // `:` keys admit whitespace
    // =========================================================================

    public function testKeySpacingVariantsAllReadTheKey(): void
    {
        $vars = ['config' => ['version' => '1.2.3']];

        self::tpl('c_key_tight', '{{ config:version }}');
        self::tpl('c_key_spaced', '{{ config : version }}');
        self::tpl('c_key_right', '{{ config: version }}');
        self::tpl('c_key_left', '{{ config :version }}');

        $this->assertSame('1.2.3', self::render('c_key_tight', $vars));
        $this->assertSame('1.2.3', self::render('c_key_spaced', $vars));
        $this->assertSame('1.2.3', self::render('c_key_right', $vars));
        $this->assertSame('1.2.3', self::render('c_key_left', $vars));
    }

    public function testNestedKeyChainWithSpacing(): void
    {
        self::tpl('c_key_nested', '{{ config : app : name }}');
        $this->assertSame(
            'Sailantis',
            self::render('c_key_nested', ['config' => ['app' => ['name' => 'Sailantis']]])
        );
    }

    public function testSpacedColonInCondition(): void
    {
        self::tpl('c_key_in_if', '{% if product : stock == 0 %}none{% else %}some{% endif %}');
        $this->assertSame(
            'none',
            self::render('c_key_in_if', ['product' => ['stock' => 0]])
        );
    }

    public function testSpacedColonInLoopSource(): void
    {
        self::tpl('c_key_in_for', '{% for role in user : roles %}[{{ role }}]{% endfor %}');
        $this->assertSame(
            '[a][b]',
            self::render('c_key_in_for', ['user' => ['roles' => ['a', 'b']]])
        );
    }

    // =========================================================================
    // `?` and `?:` stay glued
    // =========================================================================

    public function testOptionalKeyStaysGlued(): void
    {
        self::tpl('c_opt_key', '{{ user?:nickname ?? "none" }}');
        $this->assertSame('nick', self::render('c_opt_key', ['user' => ['nickname' => 'nick']]));
        $this->assertSame('none', self::render('c_opt_key'));
        $this->assertSame('none', self::render('c_opt_key', ['user' => null]));
    }

    public function testSpacedQuestionMarkIsATernaryRatherThanOptionalAccess(): void
    {
        // `user ? x : y` is a condition, so a spaced `?` must not open an
        // optional read. Writing `user ? : nick` therefore reaches the ternary
        // path with nothing between `?` and `:`, which PHP would compile to its
        // `?:` shorthand — a truthiness test, not a key read. Since the two
        // spellings differ by one space, the engine reports it rather than
        // silently choosing one.
        self::tpl('c_spaced_question', '{{ user ? : "fallback" }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/missing its then-branch/');
        self::render('c_spaced_question', ['user' => ['nickname' => 'nick']]);
    }

    public function testOptionalKeyOperatorStillWorksWithoutTheSpace(): void
    {
        // The counterpart to the test above: the glued form is the optional key.
        self::tpl('c_opt_key_glued', '{{ user?:nickname ?? "none" }}');
        $this->assertSame('nick', self::render('c_opt_key_glued', ['user' => ['nickname' => 'nick']]));
    }

    public function testOptionalArrowWithoutSigilIsRejected(): void
    {
        self::tpl('c_bare_opt_arrow', '{{ user ?->name }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/requires the \$ sigil/');
        self::render('c_bare_opt_arrow', ['user' => new \stdClass()]);
    }

    // =========================================================================
    // Layered conditions
    // =========================================================================

    public function testParenthesisedNestedTernaryInThenBranch(): void
    {
        self::tpl('c_nested_paren', '{{ cond ? (foo ? bar : blubb) : blobb }}');
        $vars = ['cond' => true, 'foo' => true, 'bar' => 'BAR', 'blubb' => 'BLUBB', 'blobb' => 'BLOBB'];

        $this->assertSame('BAR', self::render('c_nested_paren', $vars));
        $this->assertSame('BLUBB', self::render('c_nested_paren', ['foo' => false] + $vars));
        $this->assertSame('BLOBB', self::render('c_nested_paren', ['cond' => false] + $vars));
    }

    public function testNestedTernaryInElseBranchMustBeParenthesised(): void
    {
        self::tpl('c_nested_else', '{{ cond ? foo : (bar ? blubb : blobb) }}');
        $vars = ['cond' => false, 'bar' => true, 'foo' => 'FOO', 'blubb' => 'BLUBB', 'blobb' => 'BLOBB'];

        $this->assertSame('BLUBB', self::render('c_nested_else', $vars));
    }

    public function testDeeplyNestedParenthesisedTernary(): void
    {
        self::tpl('c_nested_deep', '{{ a ? (b ? (c ? d : e) : f) : g }}');
        $vars = ['a' => true, 'b' => true, 'c' => true, 'd' => 'D', 'e' => 'E', 'f' => 'F', 'g' => 'G'];

        $this->assertSame('D', self::render('c_nested_deep', $vars));
    }

    public function testNestedTernaryInThenBranchWithoutParenthesesStillCompiles(): void
    {
        // Assigned to a variable rather than nested in one expression, because
        // PHP rejects `a ? b : c ? d : e` while accepting `a ? b ? c : d : e`.
        self::tpl('c_nested_then_flat', '{{ a ? b ? c : d : e }}');
        $vars = ['a' => true, 'b' => true, 'c' => 'C', 'd' => 'D', 'e' => 'E'];

        $this->assertSame('C', self::render('c_nested_then_flat', $vars));
    }

    /**
     * An if/elseif chain is a nested ternary in the ELSE branch, which PHP
     * refuses to parse unless the branch is parenthesised. Emitting it anyway
     * produced an uncatchable fatal error when the compiled class was loaded, so
     * the engine reports it at compile time instead.
     */
    public function testChainedTernaryInElseBranchIsACompileError(): void
    {
        self::tpl('c_nested_chain', '{{ a ? b : c ? d : e }}');

        $this->expectException(ClarityException::class);
        $this->render('c_nested_chain', ['a' => true, 'b' => 1, 'c' => 2, 'd' => 3, 'e' => 4]);
    }

    public function testElseIfIsReportedWithTheParenthesesHint(): void
    {
        self::tpl('c_else_if', '{{ a ? b : if c ? d : e }}');

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/must be parenthesised/');
        self::render('c_else_if', ['a' => true, 'b' => 1, 'c' => 2, 'd' => 3, 'e' => 4]);
    }

    public function testTernaryInFilterArgument(): void
    {
        self::tpl('c_tern_in_arg', '{{ items |> map(u => u ? "y" : "n") |> join(",") }}');
        $this->assertSame('y,n', self::render('c_tern_in_arg', ['items' => [true, false]]));
    }

    public function testTernaryInsideAnIndex(): void
    {
        // The ternary is the index EXPRESSION, so the result is `items['X']`.
        self::tpl('c_tern_in_index', '{{ items[a ? b : c] }}');
        $this->assertSame(
            '1',
            self::render('c_tern_in_index', ['items' => ['X' => 1, 'Y' => 2], 'a' => true, 'b' => 'X', 'c' => 'Y'])
        );
        $this->assertSame(
            '2',
            self::render('c_tern_in_index', ['items' => ['X' => 1, 'Y' => 2], 'a' => false, 'b' => 'X', 'c' => 'Y'])
        );
    }

    public function testOptionalKeyInsideThenBranch(): void
    {
        // An optional-key read sits in the then-branch of a ternary, so the
        // colon that follows the branch must still be found. `?:` is consumed as
        // the optional-key operator before ternary handling sees it, which is
        // what keeps this expressible.
        self::tpl('c_opt_key_in_tern', "{{ cond ? user?:nick ?? 'x' : 'y' }}");
        $vars = ['cond' => true, 'user' => ['nick' => 'nick']];

        $this->assertSame('nick', self::render('c_opt_key_in_tern', $vars));
        $this->assertSame('y', self::render('c_opt_key_in_tern', ['cond' => false] + $vars));
    }
}
