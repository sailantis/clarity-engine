<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityEngine;
use Clarity\ClarityException;
use Clarity\ModuleInterface;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestEnvironment;

class ModulesTest extends BaseTestCase
{
    public function testModuleCanRegisterFilterAndService(): void
    {
        $module = new class implements ModuleInterface
        {
            public function register(\Clarity\ClarityEngine $engine): void
            {
                $engine->addFilter('double', fn($v) => $v * 2);
                $engine->addService('svc', new class
                {
                    public function greet(): string
                    {
                        return 'hi';
                    }
                });
            }
        };

        TestEnvironment::engine()->use($module);

        self::tpl('mod_use', '{{ 2 |> double }}');

        $this->assertSame('4', self::render('mod_use'));

        // Service should be registered in the engine registry
        $this->assertTrue(TestEnvironment::engine()->hasService('svc'));
        $svc = TestEnvironment::engine()->getService('svc');
        $this->assertSame('hi', $svc->greet());
    }

    // =========================================================================
    // Module lifecycle
    // =========================================================================

    public function testUseCallsModuleRegister(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());

        $registered = false;
        $module     = new class ($registered) implements ModuleInterface
        {
            public function __construct(private bool &$flag) {}
            public function register(ClarityEngine $e): void
            {
                $this->flag = true;
            }
        };

        $this->assertFalse($registered);
        $engine->use($module);
        $this->assertTrue($registered);
    }

    public function testUseIsFluent(): void
    {
        $engine = new ClarityEngine();
        $module = new class implements ModuleInterface
        {
            public function register(ClarityEngine $e): void {}
        };
        $result = $engine->use($module);
        $this->assertSame($engine, $result);
    }

    public function testModuleCanRegisterFilter(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());

        $module = new class implements ModuleInterface
        {
            public function register(ClarityEngine $e): void
            {
                $e->addFilter('shout', fn(string $v): string => strtoupper($v) . '!!!');
            }
        };
        $engine->use($module);

        self::tpl('mod_filter', '{{ word |> shout }}');
        $result = $engine->renderPartial('mod_filter', ['word' => 'hello']);
        $this->assertSame('HELLO!!!', $result);
    }

    // =========================================================================
    // Custom directives
    // =========================================================================

    public function testAddDirectiveRegistersCustomDirective(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());
        $engine->addDirective('noop', fn(string $r, string $p, int $l, callable $e): string => '/* noop */');
        $engine->addDirective('endnoop', fn(string $r, string $p, int $l, callable $e): string => '/* endnoop */');

        self::tpl('block_noop', '{% noop %}inner{% endnoop %}');
        $result = $engine->renderPartial('block_noop');
        $this->assertSame('inner', $result);
    }

    public function testUnknownDirectiveStillThrows(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/Unknown directive 'totally_unknown'/");
        self::tpl('bad_directive', '{% totally_unknown %}');
        $engine->renderPartial('bad_directive');
    }

    public function testDirectiveHandlerCanProcessExpression(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());

        $engine->addDirective('tag', function (string $rest, string $path, int $line, callable $expr): string {
            $phpTag = $expr($rest);
            return "ob_start(); \$__tag = {$phpTag};";
        });
        $engine->addDirective(
            'endtag',
            fn(string $r, string $p, int $l, callable $e): string =>
                'echo "<" . htmlspecialchars((string)$__tag) . ">" . ob_get_clean() . "</" . htmlspecialchars((string)$__tag) . ">";'
        );

        self::tpl('block_expr', '{% tag tagName %}hello{% endtag %}');
        $result = $engine->renderPartial('block_expr', ['tagName' => 'span']);
        $this->assertSame('<span>hello</span>', $result);
    }

    /**
     * A directive that gates a block on the engine's debug state must read that
     * state through the compiled body's unpacked locals. Only `$__fl`, `$__fn`
     * and `$__sv` exist inside `render()`; a literal `$__debug` local is never
     * defined, so such a block would silently never render (and raise an
     * "Undefined variable" warning whenever the guard is truthy).
     */
    public function testCustomDirectiveCanReadDebugFlagViaService(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());

        $engine->addService('__debug', fn(): bool => $engine->isDebugMode());
        $engine->addDirective(
            'debug_if',
            function (string $rest, string $path, int $line, callable $processExpr): string {
                return 'if (' . $processExpr($rest) . ' && $__sv["__debug"]()) {';
            }
        );
        $engine->addDirective('enddebug_if', fn() => '}');

        self::tpl('mod_debug_if', '{% debug_if count > 3 %}DEBUG{% enddebug_if %}normal');

        // Debug off: the guard is false, so only the trailing literal renders.
        $engine->disableDebug();
        $this->assertSame('normal', $engine->renderPartial('mod_debug_if', ['count' => 9]));

        // Debug on: the guard is true. PHPUnit turns undefined-variable warnings
        // into failures, so a `$__debug`-style reference would fail right here.
        $engine->enableDebug();
        $this->assertSame('DEBUGnormal', $engine->renderPartial('mod_debug_if', ['count' => 9]));

        // Falsy expression stays falsy in debug mode.
        $this->assertSame('normal', $engine->renderPartial('mod_debug_if', ['count' => 1]));
    }

    // =========================================================================
    // Inline filter registration
    // =========================================================================

    public function testAddInlineFilterCompilesAtCompileTime(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());

        $engine->addInlineFilter('bang', [
            'php' => '((string) {1}) . "!"',
        ]);

        self::tpl('inline_bang', '{{ word |> bang }}');
        $result = $engine->renderPartial('inline_bang', ['word' => 'hello']);
        $this->assertSame('hello!', $result);
    }

    public function testAddInlineFilterWithParamsAndDefaults(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());

        $engine->addInlineFilter('repeat_str', [
            'php'      => '\\str_repeat((string) {1}, {2})',
            'params'   => ['times'],
            'defaults' => ['times' => '2'],
        ]);

        self::tpl('inline_repeat', '{{ ch |> repeat_str }}:{{ ch |> repeat_str(4) }}');
        $result = $engine->renderPartial('inline_repeat', ['ch' => 'ab']);
        $this->assertSame('abab:abababab', $result);
    }

    // =========================================================================
    // Filter service (addFilterService)
    // =========================================================================

    public function testAddFilterServiceExposedInTemplate(): void
    {
        $engine = new ClarityEngine();
        $engine->setViewPath(TestEnvironment::viewDir())->setCachePath(TestEnvironment::cacheDir());

        $counter = new class
        {
            public int $n = 0;
            public function next(): int
            {
                return ++$this->n;
            }
        };
        $engine->addService('counter', $counter);
        $engine->addInlineFilter('counted', [
            'php' => '((string) {1}) . "#" . $__sv[\'counter\']->next()',
        ]);

        self::tpl('svc_filter', '{{ a |> counted }}:{{ b |> counted }}');
        $result = $engine->renderPartial('svc_filter', ['a' => 'x', 'b' => 'y']);
        $this->assertSame('x#1:y#2', $result);
    }
}