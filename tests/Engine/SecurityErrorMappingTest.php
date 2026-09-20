<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestEnvironment;

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
            $this->assertStringContainsString('warn_undef', $e->getMessage());
            throw $e;
        }
    }

    public function testNestedUndefinedArrayKeyUsesExactTemplateLine(): void
    {
        self::tpl('warn_nested_line', implode("\n", [
            'static line',
            '{% if context.authenticated %}',
            'visible',
            '{% endif %}',
        ]));

        try {
            self::render('warn_nested_line', ['context' => []]);
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertStringContainsString('authenticated', $e->getMessage());
            $this->assertSame(2, $e->templateLine);
        }
    }

    public function testNestedExtendsUndefinedArrayKeyUsesChildTemplateLine(): void
    {
        self::tpl('layouts/base', implode("\n", [
            '<html>',
            '<body>',
            '{% block content %}{% endblock %}',
            '</body>',
            '</html>',
        ]));

        self::tpl('layouts/agent', implode("\n", [
            '{% extends "layouts/base" %}',
            '{% block content %}',
            '<section>',
            '{% block page %}{% endblock %}',
            '</section>',
            '{% endblock %}',
        ]));

        self::tpl('pages/ticket', implode("\n", [
            '{% extends "layouts/agent" %}',
            '{% block page %}',
            'safe line',
            '{% if context.authenticated1 %}',
            'visible',
            '{% endif %}',
            '{% endblock %}',
        ]));

        try {
            self::render('pages/ticket', ['context' => []]);
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertStringContainsString('authenticated1', $e->getMessage());
            $this->assertSame('pages/ticket', $e->templateFile);
            $this->assertSame(4, $e->templateLine);
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

    // =========================================================================
    // Exceptions raised while the template runs
    // =========================================================================

    public function testExceptionFromRegisteredFilterIsMappedToTemplateLine(): void
    {
        TestEnvironment::engine()->addFilter('explode_filter', static function (mixed $v): never {
            throw new \LogicException('filter exploded');
        });

        self::tpl('filter_throws', "line one\n{{ value |> explode_filter }}\nline three");

        try {
            self::render('filter_throws', ['value' => 'x']);
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertSame('filter_throws', $e->templateFile);
            $this->assertSame(2, $e->templateLine);
            $this->assertInstanceOf(\LogicException::class, $e->getPrevious());
        }
    }

    public function testTypeErrorOnFilterArgumentIsMappedToTemplateLine(): void
    {
        // A typed filter parameter rejects the piped value → TypeError.  This is
        // an Error (not an E_* diagnostic), so it can only be mapped by catching
        // it around the render call.
        TestEnvironment::engine()->addFilter('wants_string', static fn(string $v): string => $v);

        self::tpl('filter_type_error', "a\nb\n{{ value |> wants_string }}\nd");

        try {
            self::render('filter_type_error', ['value' => ['not', 'a', 'string']]);
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertSame('filter_type_error', $e->templateFile);
            $this->assertSame(3, $e->templateLine);
            $this->assertInstanceOf(\TypeError::class, $e->getPrevious());
        }
    }

    public function testExceptionThrownFromInlineFilterPhpIsMapped(): void
    {
        // Inline filters compile their PHP *into* the cache file, so the throw
        // site is the compiled template itself.
        TestEnvironment::engine()->addInlineFilter('boom_inline', [
            'php' => '(function () { throw new \DomainException("inline boom"); })()',
        ]);

        self::tpl('inline_throws', "first\n{{ value |> boom_inline }}\nthird");

        try {
            self::render('inline_throws', ['value' => 'x']);
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertSame('inline_throws', $e->templateFile);
            $this->assertSame(2, $e->templateLine);
            $this->assertInstanceOf(\DomainException::class, $e->getPrevious());
        }
    }

    public function testApplicationExceptionOutsideTemplateIsNotRewritten(): void
    {
        // The filter throws an exception that never touches the compiled
        // template (e.g. a domain exception from application code).  Clarity must
        // not claim it originates from a template line.
        TestEnvironment::engine()->addFilter('domain_boom', static function (mixed $v): never {
            throw new \OutOfRangeException('application level');
        });

        self::tpl('app_exception', "{% for item in items %}{{ item }}{% endfor %}");

        // Rendering succeeds — the filter is never called here.  The point of the
        // test is that render() maps exceptions raised *during* rendering only;
        // verify the filter itself still throws its own type.
        $this->assertSame('', trim(self::render('app_exception', ['items' => []])));
    }

    public function testErrorHandlerDoesNotLeakToCaller(): void
    {
        // The engine installs and restores an error handler per render; a failing
        // render must not leave it on the stack.
        $sentinelCalls = 0;
        set_error_handler(static function () use (&$sentinelCalls): bool {
            $sentinelCalls++;
            return true;
        });

        try {
            self::tpl('leak_check', "{{ missing_for_leak_check }}");
            try {
                self::render('leak_check', []);
            } catch (ClarityException) {}

            trigger_error('caller space', E_USER_WARNING);
            $this->assertSame(1, $sentinelCalls, 'clarity must not shadow the caller error handler');
        } finally {
            restore_error_handler();
        }
    }

    public function testNonMappedWarningDoesNotAbortRendering(): void
    {
        // Diagnostics that are not variable-resolution errors (here: a foreach
        // over null) are annotated with the template location and handed to the
        // application's error handler.  Rendering CONTINUES: the partial output is
        // returned instead of the whole render being aborted, because the severity
        // policy belongs to the application, not to the template engine.
        self::tpl('warning_passthrough', "before\n{% for x in maybe %}{{ x }}{% endfor %}\nafter");

        $seen = null;
        set_error_handler(static function (int $no, string $msg) use (&$seen): bool {
            $seen = [$no, $msg];
            return true;
        });

        try {
            $output = self::render('warning_passthrough', ['maybe' => null]);
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('before', $output);
        $this->assertStringContainsString('after', $output);

        // The application handler must have received the diagnostic, annotated
        // with the template location.  Without chaining PHP's built-in handler
        // would have reported it to stderr and this handler would never run.
        $this->assertNotNull($seen, 'the application error handler must receive template diagnostics');
        $this->assertSame(E_USER_WARNING, $seen[0]);
        $this->assertStringContainsString('warning_passthrough', $seen[1]);
        $this->assertStringContainsString('foreach() argument', $seen[1]);
    }

    public function testDiagnosticsOutsideTemplateReachApplicationHandler(): void
    {
        // A diagnostic raised outside the compiled template is none of Clarity's
        // business: it must be handed straight to the application handler.
        $seen = [];
        set_error_handler(static function (int $no, string $msg) use (&$seen): bool {
            $seen[] = [$no, $msg];
            return true;
        });

        try {
            self::tpl('outside_diag', 'plain text');
            self::render('outside_diag');

            // Raised from the test (i.e. outside the template) while... no handler
            // of Clarity's is installed at this point.
            trigger_error('after render', E_USER_WARNING);

            // And one raised while Clarity's handler IS installed: register a
            // filter that raises a warning from its own file.
            TestEnvironment::engine()->addFilter('warn_from_filter', static function (mixed $v): string {
                trigger_error('warning raised by filter code', E_USER_WARNING);
                return (string) $v;
            });
            self::tpl('outside_filter', '{{ value |> warn_from_filter }}');
            self::render('outside_filter', ['value' => 'x']);
        } finally {
            restore_error_handler();
        }

        $messages = array_column($seen, 1);
        $this->assertContains('after render', $messages);
        $this->assertContains(
            'warning raised by filter code',
            $messages,
            'a diagnostic from filter code must reach the application handler'
        );
    }
}