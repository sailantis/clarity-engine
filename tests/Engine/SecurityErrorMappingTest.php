<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Tests\BaseTestCase;

class SecurityErrorMappingTest extends BaseTestCase
{
    public function testUnknownFunctionCallThrows(): void
    {
        $this->expectException(ClarityException::class);
        self::tpl('bad_fn', '{{ phpinfo() }}');
        self::render('bad_fn');
    }

    public function testPhpWarningIsMappedToClarityException(): void
    {
        $this->expectException(ClarityException::class);
        // Trigger a PHP notice (undefined array key) inside the template
        // render body so the engine's error handler maps it to a ClarityException.
        self::tpl('warn_undef', '{{ missing }}');
        try {
            self::render('warn_undef');
        } catch (ClarityException $e) {
            $this->assertStringContainsString('warn_undef.clarity.html', $e->getMessage());
            throw $e;
        }
    }

    // =========================================================================
    // Compile-time function-call prevention
    // =========================================================================

    public function testFunctionCallInOutputTagThrowsAtCompileTime(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/unregistered function/');
        self::tpl('sec_output', "{{ system('id') }}");
        self::render('sec_output');
    }

    public function testFunctionCallInSetDirectiveThrowsAtCompileTime(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/unregistered function/');
        self::tpl('sec_set', "{% set x = system('id') %}{{ x }}");
        self::render('sec_set');
    }

    public function testFunctionCallInIfConditionThrowsAtCompileTime(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/unregistered function/');
        self::tpl('sec_if', "{% if system('id') %}yes{% endif %}");
        self::render('sec_if');
    }

    public function testFunctionCallInRangeBoundThrowsAtCompileTime(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/unregistered function/');
        self::tpl('sec_range', "{% for i in system ('id')...10 %}{{ i }}{% endfor %}");
        self::render('sec_range');
    }

    public function testFunctionCallInFilterArgumentThrowsAtCompileTime(): void
    {
        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/unregistered function/');
        self::tpl('sec_filter_arg', "{{ name |> substr(system('id'), 1) }}");
        self::render('sec_filter_arg');
    }

    // =========================================================================
    // Error / exception mapping
    // =========================================================================

    public function testClarityExceptionCarriesTemplateLine(): void
    {
        self::tpl('broken', '{{ name |> nonExistentFilter }}');

        $obLevel = ob_get_level();
        try {
            self::render('broken', ['name' => 'x']);
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertInstanceOf(ClarityException::class, $e);
        } finally {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
        }
    }

    public function testSyntaxErrorInExpressionIsMappedToClarityException(): void
    {
        $this->tpl('syntax_err', "static line\n{{ message + }}\nstatic line");

        $obLevel = ob_get_level();
        try {
            self::render('syntax_err', ['message' => 'hello']);
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertInstanceOf(ClarityException::class, $e);
            $this->assertStringContainsString('syntax_err', $e->templateFile);
            $this->assertStringContainsString('syntax', strtolower($e->getMessage()));
            $this->assertInstanceOf(\ParseError::class, $e->getPrevious());
            $this->assertSame(2, $e->templateLine);
        } finally {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
        }
    }
}
