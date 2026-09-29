<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestEnvironment;

/**
 * Lambda parameter scoping across NESTING.
 *
 * A lambda compiles to a real PHP closure, so its parameters are PHP locals.
 * A nested lambda (`map(rows, r => map(r.vals, v => v ~ r.name))`) therefore has
 * to CAPTURE the enclosing lambda's parameter: the inner body reads `$r`, and
 * PHP only binds that from an explicit `use ($r)`.
 *
 * The compiler tracks a STACK of lambda parameter frames. A root name matching
 * a parameter of any enclosing frame is emitted as the bare `$name` instead of
 * `$__c_va['name']`, and the name is added to the inner closure's `use` clause
 * when the compiled body actually references it.
 */
class NestedLambdaTest extends BaseTestCase
{
    /**
     * @dataProvider nestedLambdaCases
     */
    public function testNestedLambdaReferencesOuterParameter(
        string $view,
        string $src,
        array $vars,
        string $expected
    ): void {
        self::tpl($view, $src);
        $this->assertSame($expected, self::render($view, $vars));
    }

    public static function nestedLambdaCases(): array
    {
        return [
            // The original report: scalar outer param, used inside the inner body.
            'scalar outer param' => [
                'nl_scalar',
                '{{ map(items, x => map(words, w => w ~ x)) |> json |> raw }}',
                ['items' => ['A', 'B'], 'words' => ['p', 'q']],
                '[["pA","qA"],["pB","qB"]]',
            ],

            // Outer param used with an ACCESS (key read) inside the inner body.
            'outer param with key read' => [
                'nl_keyread',
                '{{ map(rows, r => map(r:vals, v => v ~ r:name)) |> json |> raw }}',
                [
                    'rows' => [
                        ['name' => 'N1', 'vals' => ['a', 'b']],
                        ['name' => 'N2', 'vals' => ['c']],
                    ]
                ],
                '[["aN1","bN1"],["cN2"]]',
            ],

            // Outer param fed into a filter inside the inner body.
            'outer param through a pipe' => [
                'nl_pipe',
                '{{ map(items, x => map(words, w => (w ~ x) |> upper)) |> json |> raw }}',
                ['items' => ['a'], 'words' => ['p']],
                '[["PA"]]',
            ],

            // Inner body reaching the registry AND the outer param: both captured.
            'outer param plus registry' => [
                'nl_registry',
                '{{ map(items, x => map(words, w => (w ~ x) |> length)) |> json |> raw }}',
                ['items' => ['ab'], 'words' => ['p']],
                '[[3]]',
            ],

            // A scope variable inside the inner body still resolves via $__c_va.
            'scope var in inner body' => [
                'nl_scope',
                '{{ map(items, x => map(words, w => w ~ suffix)) |> json |> raw }}',
                ['items' => ['A'], 'words' => ['p'], 'suffix' => '!'],
                '[["p!"]]',
            ],

            // Three levels of nesting.
            'three levels' => [
                'nl_three',
                '{{ map(a, p => map(b, q => map(c, r => p ~ q ~ r))) |> json |> raw }}',
                ['a' => ['1'], 'b' => ['2'], 'c' => ['3']],
                '[[["123"]]]',
            ],

            // reduce nested in map: the inner two-parameter lambda's `c`/`i` are
            // its own, while `g` comes from the enclosing map lambda.
            'reduce inside map' => [
                'nl_reduce_in_map',
                '{{ map(groups, g => reduce(g:nums, c, i => c + i + g:bump, 0)) |> json |> raw }}',
                [
                    'groups' => [
                        ['bump' => 10, 'nums' => [1, 2]],
                        ['bump' => 1, 'nums' => [3]],
                    ]
                ],
                '[23,4]',
            ],

            // Shadowing: the inner lambda declares `x` itself, so it must use its
            // OWN parameter — not capture the outer one.
            'inner param shadows outer' => [
                'nl_shadow',
                '{{ map(items, x => map(words, x => x ~ "!")) |> json |> raw }}',
                ['items' => ['IGNORED'], 'words' => ['p', 'q']],
                '[["p!","q!"]]',
            ],
        ];
    }

    public function testOuterParameterIsCapturedInTheInnerClosure(): void
    {
        self::tpl('nl_capture', '{{ map(items, x => map(words, w => w ~ x)) |> json |> raw }}');
        self::render('nl_capture', ['items' => ['A'], 'words' => ['p']]);

        $compiled = $this->compiledSource('nl_capture');

        // The inner closure must capture the outer parameter.
        $this->assertStringContainsString('use ($__c_va, $x): mixed', $compiled);
    }

    public function testOuterParameterIsNotCapturedWhenUnused(): void
    {
        // The inner body never mentions `x`, so the inner closure must not
        // capture it — otherwise every nested lambda would drag in names it
        // does not need.
        self::tpl('nl_no_capture', '{{ map(items, x => map(words, w => w ~ "!")) |> json |> raw }}');
        self::render('nl_no_capture', ['items' => ['A'], 'words' => ['p']]);

        $compiled = $this->compiledSource('nl_no_capture');

        $this->assertStringContainsString('use ($__c_va): mixed', $compiled);
        $this->assertStringNotContainsString('use ($__c_va, $x)', $compiled);
    }

    public function testShadowedInnerParameterIsNotCapturedFromTheOuterLambda(): void
    {
        // Both lambdas declare `x`. The inner closure must NOT capture the outer
        // `x`, because its own parameter shadows it.
        self::tpl('nl_shadow_capture', '{{ map(items, x => map(words, x => x ~ "!")) |> json |> raw }}');
        self::render('nl_shadow_capture', ['items' => ['A'], 'words' => ['p']]);

        $compiled = $this->compiledSource('nl_shadow_capture');

        $this->assertStringNotContainsString(
            'use ($__c_va, $x)',
            $compiled,
            'a shadowing inner parameter must not be captured from the outer lambda'
        );
    }

    public function testShortOuterParameterNameDoesNotOverCapture(): void
    {
        // A one-letter outer parameter must not be captured merely because the
        // body contains a longer name starting with that letter: `$c` must match
        // `$c`, never a scope read `$__c_va['count']`.
        self::tpl(
            'nl_no_overcapture',
            '{{ map(items, c => map(words, w => w ~ count)) |> json |> raw }}'
        );
        self::render('nl_no_overcapture', ['items' => ['A'], 'words' => ['p'], 'count' => 7]);

        $compiled = $this->compiledSource('nl_no_overcapture');

        // The inner body references the scope variable `count`, not `$c`, so the
        // only capture it needs is $__c_va.
        $this->assertStringContainsString('static function(mixed $w) use ($__c_va): mixed', $compiled);
    }

    public function testLambdaFrameStackIsCleanedUpAfterACompileError(): void
    {
        // Frames are pushed/popped around the body compile with `finally`, so an
        // error thrown inside a nested lambda must not leave a frame behind — a
        // stale frame would make a LATER compile on the same tokenizer resolve
        // roots as lambda parameters.
        $tokenizer = new \Clarity\Engine\Tokenizer();
        $tokenizer->setRegistry(TestEnvironment::registry());

        try {
            // The inner lambda declares TWO parameters; `map` accepts only one, so
            // this raises from INSIDE the outer lambda's body compile — after the
            // outer frame has been pushed.
            $tokenizer->buildFilterCall('map(x => map(y, a, b => y))', '$__c_va');
            $this->fail('expected a compile error for the two-parameter inner lambda');
        } catch (ClarityException) {}

        // The lambda-frame stack lives on the tokenizer facade itself, as the
        // private `lambdaFrames` property (shared by the behaviour traits).
        $frames = new \ReflectionProperty($tokenizer, 'lambdaFrames');
        $frames->setAccessible(true);

        $this->assertSame(
            [],
            $frames->getValue($tokenizer),
            'no lambda frame may survive a compile error'
        );
    }
}