<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Engine\Policy;
use Clarity\Engine\Tokenizer\CastTrait;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;

/**
 * The `(type) expr` cast syntax.
 *
 * This is the one construct that shares its opening character with something the
 * grammar already had, because `(int)` and `(a)` are lexically the same shape. So
 * this file is in two halves and the second half matters more than the first:
 *
 *  - the new spellings, and that each compiles to the same PHP as its filter form;
 *  - A REGRESSION GUARD for every existing reading of `( … )` — a grouped value, an
 *    operator's grouping, a ternary condition, a filter argument. The cast matcher
 *    must return null for all of them.
 *
 * The disambiguation rule is whitespace: `(int) x` is a cast because the `)` is
 * followed by whitespace and then an operand opener, while `(a) + b` is not,
 * because the `+` cannot open an operand. Both sides of that line are pinned.
 */
class CastSyntaxTest extends BaseTestCase
{
    private static function renderWith(Policy $policy, string $template, array $vars = []): string
    {
        $view = 'casts_' . \md5($template . \serialize($vars));
        self::tpl($view, $template);

        return TestClarityEngine::withPolicy($policy)->renderPartial($view, $vars);
    }

    private static function renderTpl(string $template, array $vars = []): string
    {
        return self::renderWith(Policy::default(), $template, $vars);
    }

    /** Assert the syntax and the filter compile to the same rendered output. */
    private function assertSameAsFilter(string $syntax, string $filterForm, array $vars, string $expected): void
    {
        $this->assertSame($expected, self::renderTpl($syntax, $vars), "syntax form: {$syntax}");
        $this->assertSame($expected, self::renderTpl($filterForm, $vars), "filter form: {$filterForm}");
    }

    // =========================================================================
    // The new spellings
    // =========================================================================

    public function testEachCastTypeHasASyntaxForm(): void
    {
        $this->assertSameAsFilter('{{ (int) x }}', '{{ x |> int }}', ['x' => '3.7'], '3');
        $this->assertSameAsFilter('{{ (float) x }}', '{{ x |> float }}', ['x' => '3.7'], '3.7');
        $this->assertSameAsFilter('{{ (string) x }}', '{{ x |> string }}', ['x' => 1.5], '1.5');
        $this->assertSameAsFilter('{{ (bool) x }}', '{{ x |> bool }}', ['x' => 'y'], '1');
    }

    /**
     * There is exactly ONE spelling per cast, and these four long spellings are
     * deliberately not cast types:
     *
     *   `(integer)` / `(boolean)` / `(double)`  redundant — `int`, `bool` and
     *       `float` spell the same cast more briefly, and these are the names a
     *       template most plausibly holds as data.
     *   `binary`  inert — a legacy alias for `string` producing identical output,
     *       not a base-2 conversion. Verified against PHP: byte-identical for an
     *       int, a bool, `null`, an array and a string.
     *
     * Each falls through to the ordinary parenthesised-expression path, so the name
     * inside is read as the VARIABLE it spells — which is what these assertions pin,
     * and why each failure names real data rather than a cast.
     */
    public function testTheRedundantAndInertCastSpellingsAreNotCasts(): void
    {
        $this->assertSame(
            '9',
            self::renderTpl('{{ (integer) }}', ['integer' => 9]),
            "'(integer)' must read the variable, not cast"
        );
        $this->assertSame(
            '1',
            self::renderTpl('{{ (boolean) }}', ['boolean' => 1]),
            "'(boolean)' must read the variable, not cast"
        );
        $this->assertSame(
            '1.25',
            self::renderTpl('{{ (double) }}', ['double' => 1.25]),
            "'(double)' must read the variable, not cast"
        );
        $this->assertSame(
            'B',
            self::renderTpl('{{ (binary) }}', ['binary' => 'B']),
            "'(binary)' must read the variable, not cast"
        );
    }

    /**
     * The canonical spellings are unaffected by the removals above.
     */
    public function testTheCanonicalCastSpellingsStillWork(): void
    {
        $this->assertSame('3', self::renderTpl('{{ (int) x }}', ['x' => '3.7']));
        $this->assertSame('3.7', self::renderTpl('{{ (float) x }}', ['x' => '3.7']));
        $this->assertSame('1.5', self::renderTpl('{{ (string) 1.5 }}'));
        $this->assertSame('1', self::renderTpl('{{ (bool) x }}', ['x' => 'x']));
    }

    /**
     * `(real)` and `(unset)` were removed in PHP 8, so accepting them would emit a
     * fatal parse error into the compiled template. They must stay ordinary
     * parenthesised expressions — which means the name inside is read as a SCOPE
     * VARIABLE, exactly as before. Binding a variable of that name proves it.
     */
    public function testRemovedPhpCastSpellingsAreNotCasts(): void
    {
        foreach (['real', 'unset'] as $removed) {
            $this->assertSame(
                'R',
                self::renderTpl("{{ ({$removed}) }}", [$removed => 'R']),
                "'({$removed})' must read the variable, not act as a cast"
            );
        }
    }

    /**
     * `(object)` turns a scalar or array into an object, which is what a JSON
     * field needs when a consumer expects an object rather than a bare value.
     * It is the one cast whose result has no `__toString()`, so it is asserted
     * through `json`.
     */
    public function testObjectIsACast(): void
    {
        // `(object) 1` is `{"scalar":1}`, where the array cast would be `[1]` — so
        // this distinguishes the object cast from the array one.
        $this->assertSame(
            '{"scalar":1}',
            self::renderTpl('{{ (object) x |> json |> raw }}', ['x' => 1])
        );
    }

    /**
     * The syntax and the filter form accept the SAME six names. `object` is easy
     * to leave out of one of them by accident, so this pins the two sets equal
     * rather than listing what each happens to contain.
     */
    public function testTheSyntaxAndFilterFormsAcceptTheSameNames(): void
    {
        $registry = TestClarityEngine::withPolicy(Policy::default())->getRegistry();

        foreach (['int', 'float', 'string', 'bool', 'array', 'object'] as $name) {
            $this->assertTrue($registry->hasFilter($name), "'{$name}' must be pipeable");

            // Each name is an inline `php` template, so the CAST path and the
            // FILTER path emit the same conversion.
            $this->assertSame(
                self::renderTpl("{{ x |> {$name} |> json |> raw }}", ['x' => '1']),
                self::renderTpl("{{ ({$name}) x |> json |> raw }}", ['x' => '1']),
                "'{$name}' must read the same as a filter and as a cast"
            );
        }
    }

    // =========================================================================
    // Operand forms
    // =========================================================================

    public function testOperandMayBeAChainKeyOrIndexRead(): void
    {
        $this->assertSame('42', self::renderTpl('{{ (int) user:age }}', ['user' => ['age' => '42']]));
        $this->assertSame('7', self::renderTpl('{{ (int) x[0] }}', ['x' => ['7', '8']]));
    }

    public function testOperandMayBeAParenthesisedExpression(): void
    {
        $this->assertSame('15', self::renderTpl('{{ (int) (10 + 5) }}'));
        $this->assertSame('xy', self::renderTpl('{{ (string) (a ~ b) }}', ['a' => 'x', 'b' => 'y']));
    }

    public function testCastsNest(): void
    {
        $this->assertSame('3', self::renderTpl('{{ (int) (float) x }}', ['x' => '3.9']));
        $this->assertSame('3', self::renderTpl('{{ (int) (string) x }}', ['x' => 3.9]));
    }

    public function testOperandMayBeALiteral(): void
    {
        $this->assertSame('1', self::renderTpl('{{ (int) true }}'));
        $this->assertSame('0', self::renderTpl('{{ (int) null }}'));
        $this->assertSame('42', self::renderTpl('{{ (int) "42abc" }}'));
        $this->assertSame('-5', self::renderTpl('{{ (int) -5 }}'));
    }

    public function testACastComposesWithTheFilterPipeline(): void
    {
        $this->assertSame('3', self::renderTpl('{{ (int) x |> string }}', ['x' => '3.9']));
        $this->assertSame('1', self::renderTpl('{{ (array) x |> length }}', ['x' => 'solo']));
    }

    /**
     * The exact case that motivated the feature: a cast in call form, inside a
     * PHP function's argument list.
     */
    public function testCastInCallFormInsideAnArgumentList(): void
    {
        $this->assertSame('4343', self::renderTpl('{{ abs((int) x) }}', ['x' => '-4343']));
    }

    public function testCastsWorkUnderTheRestrictedPolicy(): void
    {
        $this->assertSame('3', self::renderWith(Policy::restricted(), '{{ (int) x }}', ['x' => '3.7']));
    }

    /**
     * A call in the operand position obeys the SAME policy as a call anywhere else,
     * and is refused with the same message.
     *
     * This is not a nicety: an earlier implementation passed the call through to the
     * tokenizer's own call handler, which emitted `\foo(...)` with no rule check, and
     * the failure escaped the runtime error handler as a raw PHP `Error: Call to
     * undefined function` instead of a `ClarityException` naming the rule. The
     * assertion on the message is the part that matters.
     */
    public function testACallInTheOperandObeysTheSamePolicyAsAnyOtherCall(): void
    {
        try {
            self::renderWith(Policy::restricted(), '{{ (int) foo(bar) }}', ['foo' => 'F', 'bar' => 'B']);
            $this->fail('an unregistered call must be refused under restricted()');
        } catch (ClarityException $e) {
            $this->assertStringContainsString('foo()', $e->getMessage());
            $this->assertStringContainsString("'phpFunctions'", $e->getMessage());
        }

        // A registered function is dispatched as usual, cast or not.
        $this->assertSame('3', self::renderWith(Policy::restricted(), '{{ (int) len(items) }}', ['items' => [1, 2, 3]]));
    }

    /**
     * The syntax is grammar, not a capability: no rule gates it, so it must not
     * appear in the policy's rule table, and granting or denying anything else
     * must not change whether it compiles.
     */
    public function testCastSyntaxIsNotAPolicyRule(): void
    {
        $this->assertNotContains('casts', Policy::RULES);
        $this->assertNotContains('castSyntax', Policy::RULES);

        foreach ([Policy::restricted(), Policy::trusted(), Policy::unrestricted()] as $policy) {
            $this->assertSame('3', self::renderWith($policy, '{{ (int) x }}', ['x' => '3.7']));
        }
    }

    // =========================================================================
    // REGRESSION GUARDS — every existing reading of `( … )`
    // =========================================================================

    /**
     * A parenthesised value is a value. `(a) x` must NOT become "cast a to the
     * type `a`" or anything else — the trailing name is a chain continuation and
     * the whole thing is a syntax error today, as it was before.
     */
    public function testAParenthesisedValueIsUnchanged(): void
    {
        $this->assertSame('A', self::renderTpl('{{ (a) }}', ['a' => 'A']));
        $this->assertSame('A', self::renderTpl('{{ ((a)) }}', ['a' => 'A']));
        $this->assertSame('3', self::renderTpl('{{ (a + b) }}', ['a' => 1, 'b' => 2]));
        $this->assertSame('7', self::renderTpl('{{ 1 + (2 * 3) }}'));
        $this->assertSame('xy', self::renderTpl('{{ (a) ~ (b) }}', ['a' => 'x', 'b' => 'y']));
        $this->assertSame('Z', self::renderTpl('{{ (a[0]) }}', ['a' => ['Z']]));
        $this->assertSame('-1', self::renderTpl('{{ -(1) }}'));
    }

    /**
     * The subtlest case: `(a) + b` is arithmetic, and the `+` sits exactly where a
     * cast's operand would be. The operand-opener rule is what keeps them apart.
     */
    public function testGroupedArithmeticIsNotACast(): void
    {
        $this->assertSame('10', self::renderTpl('{{ (a) + b }}', ['a' => 4, 'b' => 6]));
        $this->assertSame('6', self::renderTpl('{{ (a) + b }}', ['a' => '4', 'b' => 2]));
        $this->assertSame('2', self::renderTpl('{{ (a) - 2 }}', ['a' => 4]));
    }

    public function testTernaryOverAGroupedConditionIsUnchanged(): void
    {
        $this->assertSame('B', self::renderTpl('{{ (a) ? b : c }}', ['a' => true, 'b' => 'B', 'c' => 'C']));
        $this->assertSame('C', self::renderTpl('{{ (a) ? b : c }}', ['a' => false, 'b' => 'B', 'c' => 'C']));
    }

    public function testGroupedValueInAConditionIsUnchanged(): void
    {
        $this->assertSame('yes', self::renderTpl('{% if (a) %}yes{% else %}no{% endif %}', ['a' => 1]));
        $this->assertSame('no', self::renderTpl('{% if (a) %}yes{% else %}no{% endif %}', ['a' => 0]));
    }

    public function testGroupedValueAsAFilterArgumentIsUnchanged(): void
    {
        $this->assertSame('3', self::renderTpl('{{ items |> length }}', ['items' => [1, 2, 3]]));
        $this->assertSame('3', self::renderTpl('{{ (items |> length) }}', ['items' => [1, 2, 3]]));
    }

    /**
     * A `(` in a position where no cast can start must reach the old path
     * untouched — an operator's grouping and a trailing group are the two shapes.
     */
    public function testGroupingAfterAnOperatorIsUnchanged(): void
    {
        $this->assertSame('8', self::renderTpl('{{ 2 * (a + 2) }}', ['a' => 2]));
        $this->assertSame('1', self::renderTpl('{{ a |> default((b)) }}', ['a' => null, 'b' => 1]));
    }

    // =========================================================================
    // The glued form — `(int)x` is the same cast as `(int) x`
    // =========================================================================

    /**
     * Whitespace between the `)` and the operand is OPTIONAL: the cast NAME is
     * what decides, and the operand opener is checked only so that `(a) + b`
     * stays arithmetic. Every glued operand shape must match its spaced twin.
     */
    public function testACastMayBeGluedToItsOperand(): void
    {
        $vars = ['x' => '3.7', 'user' => ['age' => '42'], 'items' => ['7', '8']];

        $this->assertSame('3', self::renderTpl('{{ (int)x }}', $vars));
        $this->assertSame('3.7', self::renderTpl('{{ (float)x }}', $vars));
        $this->assertSame('42', self::renderTpl('{{ (int)user:age }}', $vars));
        $this->assertSame('7', self::renderTpl('{{ (int)items[0] }}', $vars));
        $this->assertSame('7', self::renderTpl('{{ (int)$x }}', ['x' => '7']));
        $this->assertSame('5', self::renderTpl('{{ (int)5 }}'));
        $this->assertSame('5', self::renderTpl("{{ (int)'5' }}"));
        $this->assertSame('1', self::renderTpl('{{ (int)true }}'));
    }

    /** A glued nested cast composes exactly as the spaced one does. */
    public function testAGluedCastNests(): void
    {
        $this->assertSame('3', self::renderTpl('{{ (int)(float)x }}', ['x' => '3.9']));
        $this->assertSame('3', self::renderTpl('{{ (int)(string)x }}', ['x' => 3.9]));
    }

    /**
     * `(int)(x)` is a cast of the grouped `x`, NOT a call. Before the glued form
     * existed it compiled to a call on a variable named `int`; a call needs a
     * CALLABLE name before the `(`, and a cast name is not one.
     */
    public function testAGluedGroupIsACastAndNeverACall(): void
    {
        $this->assertSame('3', self::renderTpl('{{ (int)(x) }}', ['x' => '3.7']));
        $this->assertSame('3.7', self::renderTpl('{{ (string)(x) }}', ['x' => 3.7]));
        $this->assertSame('10', self::renderTpl('{{ (int)(a + b) }}', ['a' => '4', 'b' => '6']));
    }

    /** A glued `[` opens a collection literal, not an index into a variable. */
    public function testAGluedCollectionIsACast(): void
    {
        $this->assertSame('1', self::renderTpl('{{ (int)[0] }}'));
        $this->assertSame('1', self::renderTpl('{{ (int)[1] }}'));
    }

    /**
     * The three readings the glued form takes over, pinned so the change is
     * deliberate rather than incidental. Each needs a variable named after a cast
     * type to have meant anything before, and the cast now wins.
     */
    public function testTheGluedFormTakesPrecedenceOverATypeNamedVariable(): void
    {
        $vars = ['int' => 100, 'x' => '3.7'];

        // was: a call on the variable `int`
        $this->assertSame('3', self::renderTpl('{{ (int)(x) }}', $vars));

        // was: an index into the variable `int`
        $this->assertSame('1', self::renderTpl('{{ (int)[0] }}', $vars));

        // was: the variable `int` minus 5 — `'95'`
        $this->assertSame('-5', self::renderTpl('{{ (int)-5 }}', $vars));
    }

    /** Gluing and spacing are the same cast, and whitespace is still allowed. */
    public function testTheSpacedAndGluedFormsAgree(): void
    {
        $vars = ['x' => '3.7'];
        foreach (['(int) x', '(int)x', '(int)  x', "(\tint)x", '( int ) x'] as $form) {
            $this->assertSame('3', self::renderTpl('{{ ' . $form . ' }}', $vars), $form);
        }
    }

    /**
     * The widening is per-NAME. A name outside the six never reaches the cast
     * rule, so `(a)x` stays the invalid juxtaposition it always was — gluing
     * widens WHICH spellings of a cast work, not which names are casts.
     */
    public function testAGluedNameThatIsNotACastTypeIsUnchanged(): void
    {
        $this->assertSame('3', self::renderTpl('{{ (a) }}', ['a' => 3]));
        $this->expectException(ClarityException::class);
        self::renderTpl('{{ (a)x }}', ['a' => 3, 'x' => 7]);
    }

    // =========================================================================
    // The OPERAND-OPENER check — what keeps a type-named variable arithmetic
    // =========================================================================

    /**
     * The name alone does NOT make a cast: the `)` must be followed by something
     * that can OPEN an operand. Without this check the name would win outright
     * and every expression below would become a compile error instead of the
     * arithmetic it has always been.
     *
     * The variable is literally named `int` in every case, because that is the
     * only way the question arises — a name that is not a cast type never reaches
     * the rule at all.
     */
    public function testATypeNamedVariableFollowedByAnOperatorIsNotACast(): void
    {
        $vars = ['int' => 7, 'a' => true, 'b' => 'B', 'c' => 'C'];

        // `+` and `-` are binary operators here, so this is addition/subtraction.
        $this->assertSame('12', self::renderTpl('{{ (int) + 5 }}', $vars));
        $this->assertSame('2', self::renderTpl('{{ (int) - 5 }}', $vars));

        // A ternary over the grouped value.
        $this->assertSame('B', self::renderTpl('{{ (int) ? b : c }}', $vars));

        // A pipe continues the parenthesised value.
        $this->assertSame('7', self::renderTpl('{{ (int) |> string }}', $vars));

        // The remaining operators, so the opener set cannot quietly admit one.
        $this->assertSame('14', self::renderTpl('{{ (int) * 2 }}', $vars));
        $this->assertSame('3.5', self::renderTpl('{{ (int) / 2 }}', $vars));
        $this->assertSame('7z', self::renderTpl('{{ (int) ~ "z" }}', $vars));
        $this->assertSame('1', self::renderTpl('{{ (int) == 7 }}', $vars));
        $this->assertSame('7', self::renderTpl('{{ (int) ?? 1 }}', $vars));
    }

    /**
     * The same rule inside a CONDITION, where a bare `(int)` is an ordinary
     * truthiness test rather than a cast with no operand. Both readings have to
     * survive in the same position: `(int)` alone reads the variable, and
     * `(int) + 5` is arithmetic over it.
     */
    public function testATypeNamedVariableInAConditionIsNotACast(): void
    {
        // `(int)` with nothing after it is the variable itself — 7 is truthy.
        $this->assertSame('Y', self::renderTpl('{% if (int) %}Y{% else %}N{% endif %}', ['int' => 7]));
        $this->assertSame('N', self::renderTpl('{% if (int) %}Y{% else %}N{% endif %}', ['int' => 0]));

        // Arithmetic over it, and the grouped form of the same thing.
        $this->assertSame('Y', self::renderTpl('{% if (int) + 5 %}Y{% else %}N{% endif %}', ['int' => 7]));
        $this->assertSame('12', self::renderTpl('{% set z = (int) + 5 %}{{ z }}', ['int' => 7]));

        // Grouping the value keeps it a value, cast name or not.
        $this->assertSame('12', self::renderTpl('{{ ((int)) + 5 }}', ['int' => 7]));
    }

    /**
     * The type-NAME set is exactly six, and it is closed. The names are read from
     * the GRAMMAR rather than restated here, so adding a seventh cast type cannot
     * pass unnoticed and one cannot silently disappear into the parenthesised
     * path. The expected list is spelled out because changing it is a documented
     * decision, not a refactor.
     */
    public function testTheCastTypeNameSetIsExactlySixAndClosed(): void
    {
        $grammar = (new \ReflectionClass(CastTrait::class))
            ->getReflectionConstant('CAST_TYPES')
            ->getValue();

        $this->assertSame(
            ['int', 'float', 'string', 'bool', 'array', 'object'],
            \array_keys($grammar),
            'the grammar\'s cast-name set changed — update the docs and CHANGELOG with it'
        );

        // Every name in the grammar is a cast, and it WINS over a variable of the
        // same name. `json` makes the result comparable whatever the type.
        $expected = [
            'int'    => '3',
            'float'  => '3.7',
            'string' => '"3.7"',
            'bool'   => 'true',
            'array'  => '["3.7"]',
            'object' => '{"scalar":"3.7"}',
        ];

        foreach ($expected as $name => $json) {
            $this->assertSame(
                $json,
                self::renderTpl("{{ ({$name}) x |> json |> raw }}", ['x' => '3.7', $name => 'shadowed']),
                "'({$name})' must be a cast, even when a variable of that name exists"
            );
        }
    }

    /**
     * The names that deliberately are NOT in the set. Each must read as the
     * VARIABLE it spells, which is what proves the six above is a judgement
     * rather than an accident of enumeration.
     */
    public function testNamesOutsideTheCastSetReadAsVariables(): void
    {
        foreach (['integer', 'boolean', 'double', 'real', 'unset', 'binary', 'str', 'num'] as $notACast) {
            $this->assertSame(
                'V',
                self::renderTpl("{{ ({$notACast}) }}", [$notACast => 'V']),
                "'({$notACast})' must read the variable"
            );
        }
    }
}
