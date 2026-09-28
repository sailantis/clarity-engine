<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestEnvironment;

/**
 * `{% for %} … {% else %} … {% endfor %}` — the Twig "empty sequence" branch.
 *
 * PHP has no `for … else`, so the compiler emits the else branch as an `if`
 * on a flag that the loop header sets on its first iteration.  Two properties
 * matter beyond the rendered output:
 *
 *   1. The flag is added LAZILY.  A loop with no `{% else %}` must compile to
 *      exactly what it compiled to before, or every existing template pays for
 *      a feature it does not use.
 *   2. Nesting is decided by if-DEPTH, not by stack order.  A loop opened
 *      inside an `{% if %}` must not steal that if's `{% else %}`.
 */
class ForElseTest extends BaseTestCase
{
    // =========================================================================
    // Rendering
    // =========================================================================

    public function testForeachElseRendersFallbackOnEmptySequence(): void
    {
        self::tpl('fe_empty', '{% for i in items %}{{ i }}{% else %}none{% endfor %}');
        $this->assertSame('12', self::render('fe_empty', ['items' => [1, 2]]));
        $this->assertSame('none', self::render('fe_empty', ['items' => []]));
    }

    public function testRangeLoopElseOnEmptyRange(): void
    {
        self::tpl('fe_range', '{% for i in 1..3 %}{{ i }}{% else %}none{% endfor %}');
        $this->assertSame('123', self::render('fe_range'));

        // 1..0 yields nothing, so the else branch runs
        self::tpl('fe_range_empty', '{% for i in 1..0 %}{{ i }}{% else %}none{% endfor %}');
        $this->assertSame('none', self::render('fe_range_empty'));
    }

    public function testExclusiveRangeElseStopsAtBound(): void
    {
        self::tpl('fe_range_excl', '{% for i in 1...1 %}{{ i }}{% else %}none{% endfor %}');
        $this->assertSame('none', self::render('fe_range_excl'));
    }

    public function testKeyValueLoopElse(): void
    {
        self::tpl('fe_kv', '{% for k, v in map %}{{ k }}={{ v }};{% else %}none{% endfor %}');
        $this->assertSame('x=1;', self::render('fe_kv', ['map' => ['x' => 1]]));
        $this->assertSame('none', self::render('fe_kv', ['map' => []]));
    }

    public function testElseBodyIsNotRenderedWhenLoopIterates(): void
    {
        self::tpl('fe_once', '{% for i in items %}{{ i }}{% else %}LEAKED{% endfor %}');
        $this->assertSame('7', self::render('fe_once', ['items' => [7]]));
    }

    // =========================================================================
    // Nesting: which construct does an `{% else %}` belong to?
    // =========================================================================

    public function testElseInsideLoopBelongsToTheIfNotTheLoop(): void
    {
        // `{% else %}` here is at a DEEPER if-depth than the loop, so it is the
        // conditional's fallback; the loop's own else is the trailing one.
        self::tpl(
            'fe_if_in_for',
            '{% for i in items %}{% if i > 1 %}big{% else %}small{% endif %}{% else %}none{% endfor %}'
        );
        $this->assertSame('smallbig', self::render('fe_if_in_for', ['items' => [1, 2]]));
        $this->assertSame('none', self::render('fe_if_in_for', ['items' => []]));
    }

    public function testElseAfterLoopInsideIfBelongsToTheIf(): void
    {
        // The loop opens inside the if, so it has a HIGHER if-depth than the if.
        // An `{% else %}` at the if's depth must therefore close the if, not the
        // loop — this is the case a naive "innermost construct" rule gets wrong.
        self::tpl(
            'fe_for_in_if',
            '{% if flag %}{% for i in items %}{{ i }}{% endfor %}{% else %}noflag{% endif %}'
        );
        $this->assertSame('7', self::render('fe_for_in_if', ['flag' => true, 'items' => [7]]));
        $this->assertSame('noflag', self::render('fe_for_in_if', ['flag' => false]));
    }

    public function testBothBranchesOnALoopInsideAnIf(): void
    {
        self::tpl(
            'fe_both',
            '{% if flag %}[{% for i in items %}{{ i }}{% else %}empty{% endfor %}]{% else %}noflag{% endif %}'
        );
        $this->assertSame('[12]', self::render('fe_both', ['flag' => true, 'items' => [1, 2]]));
        $this->assertSame('[empty]', self::render('fe_both', ['flag' => true, 'items' => []]));
        $this->assertSame('noflag', self::render('fe_both', ['flag' => false, 'items' => []]));
    }

    public function testNestedLoopsEachWithTheirOwnElse(): void
    {
        self::tpl(
            'fe_nested',
            '{% for a in outer %}{% for b in a %}{{ b }}{% else %}.{% endfor %}|{% else %}none{% endfor %}'
        );
        // [1] iterates ("1"); the empty inner list takes the inner else ("."),
        // so each outer pass emits one segment and the pipe separates them.
        $this->assertSame('1|.|', self::render('fe_nested', ['outer' => [[1], []]]));
        $this->assertSame('none', self::render('fe_nested', ['outer' => []]));
    }

    public function testOuterElseDoesNotSwallowInnerLoop(): void
    {
        self::tpl(
            'fe_outer_else',
            '{% for a in outer %}'
                . '{% for b in a %}{{ b }}{% endfor %}'
                . '{% else %}none{% endfor %}'
        );
        $this->assertSame('12', self::render('fe_outer_else', ['outer' => [[1, 2]]]));
        $this->assertSame('none', self::render('fe_outer_else', ['outer' => []]));
    }

    // =========================================================================
    // Scoping
    // =========================================================================

    public function testLoopVariableIsRestoredInElseBranch(): void
    {
        // Twig hides the loop variable from the else branch, so the name must
        // resolve through the render scope again rather than to the stale PHP
        // local the loop bound.
        self::tpl('fe_scope', '{% for i in items %}{{ i }}{% else %}[{{ i }}]{% endfor %}');
        $this->assertSame('[outer]', self::render('fe_scope', ['items' => [], 'i' => 'outer']));
    }

    public function testNestedLoopVariableIsRestoredInOuterElse(): void
    {
        self::tpl(
            'fe_scope_nested',
            '{% for a in outer %}{% for b in a %}{{ b }}{% endfor %}{% else %}[{{ b }}]{% endfor %}'
        );
        $this->assertSame('[outerb]', self::render('fe_scope_nested', ['outer' => [], 'b' => 'outerb']));
    }

    public function testLoopVariableIsAvailableAgainAfterForElse(): void
    {
        self::tpl('fe_after', '{% for i in items %}{{ i }}{% else %}none{% endfor %}:{{ i }}');
        $this->assertSame('none:outside', self::render('fe_after', ['items' => [], 'i' => 'outside']));
    }

    // =========================================================================
    // Emitted shape: the flag is added lazily
    // =========================================================================

    public function testPlainLoopEmitsNoFlagVariable(): void
    {
        self::tpl('fe_plain', '{% for i in items %}{{ i }}{% endfor %}');
        self::render('fe_plain', ['items' => [1]]);
        $body = $this->compiledBody('fe_plain');

        $this->assertStringNotContainsString(
            '$__c_e',
            $body,
            'a loop without an else must not pay for the iteration flag'
        );
    }

    public function testForElseLoopRecordsIterationOnTheHeaderLine(): void
    {
        self::tpl('fe_flag', '{% for i in items %}{{ i }}{% else %}none{% endfor %}');
        self::render('fe_flag', ['items' => [1]]);
        $body = $this->compiledBody('fe_flag');

        $this->assertStringContainsString(
            '$__c_e0 = false; foreach ($__c_va[\'items\'] as $i): $__c_e0 = true;',
            $body,
            'the flag is reset before the loop and set on its header, so no line is inserted into the map'
        );
        $this->assertStringContainsString('endforeach; if (!$__c_e0):', $body);
        $this->assertStringContainsString('endif;', $body);
    }

    public function testRangeForElseClosesWithEndforNotEndforeach(): void
    {
        self::tpl('fe_range_close', '{% for i in 1..2 %}{{ i }}{% else %}none{% endfor %}');
        self::render('fe_range_close');
        $body = $this->compiledBody('fe_range_close');

        $this->assertStringContainsString('endfor;', $body);
        $this->assertStringNotContainsString('endforeach;', $body);
    }

    public function testTwoSeparateLoopsGetDistinctFlags(): void
    {
        self::tpl(
            'fe_two',
            '{% for i in a %}{{ i }}{% else %}-{% endfor %}'
                . '{% for j in b %}{{ j }}{% else %}+{% endfor %}'
        );
        $this->assertSame('-+', self::render('fe_two', ['a' => [], 'b' => []]));
        $body = $this->compiledBody('fe_two');
        $this->assertStringContainsString('$__c_e0', $body);
        $this->assertStringContainsString('$__c_e1', $body);
    }

    // =========================================================================
    // Compile errors
    // =========================================================================

    public function testSecondElseOnALoopIsRejected(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/only take one/i');
        self::tpl('fe_two_else', '{% for i in items %}{{ i }}{% else %}a{% else %}b{% endfor %}');
        self::render('fe_two_else', ['items' => []]);
    }

    public function testElseifInALoopIsRejected(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/'\\{% elseif %\\}' is not valid in a '\\{% for %\\}'/");
        self::tpl('fe_elseif', '{% for i in items %}{{ i }}{% elseif x %}no{% endfor %}');
        self::render('fe_elseif', ['items' => []]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Return the compiled render body of a template that has been rendered. */
    private function compiledBody(string $view): string
    {
        $cache = new \ReflectionProperty(TestEnvironment::engine(), 'cache');
        $cache->setAccessible(true);
        $className = $cache->getValue(TestEnvironment::engine())->getLoadedClassName($view);
        $this->assertIsString($className, 'the template must be compiled and loaded');

        $src   = (string) file_get_contents((new \ReflectionClass($className))->getFileName());
        $start = strpos($src, 'try {');
        $end   = strpos($src, 'return (string) ob_get_clean();');
        $this->assertNotFalse($start, 'the compiled class must contain the render body');
        $this->assertNotFalse($end, 'the compiled class must contain the render body');

        return substr($src, $start, $end - $start);
    }
}
