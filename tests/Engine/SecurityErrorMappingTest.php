<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Tests\BaseTestCase;
use Clarity\Tests\TestClarityEngine;
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
            $this->assertStringContainsString('missing', $e->getMessage());
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
            $this->assertSame('pages/ticket', $e->templateName);
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

        // The filter itself must be REGISTERED, so the guard under test is the
        // one that compiles its ARGUMENTS. An unregistered filter is now
        // rejected before its arguments are looked at — a separate guard, and
        // one that would otherwise let this test pass without exercising
        // argument validation at all.
        self::tpl('sec_filter_arg', "{{ name |> replace(system('id'), 'x') }}");
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
            $this->assertStringContainsString('syntax_err', $e->templateName);
            $this->assertStringContainsString('syntax', strtolower($e->getMessage()));
            $this->assertInstanceOf(\ParseError::class, $e->getPrevious());
            $this->assertSame(2, $e->templateLine);
        } finally {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
        }
    }

    public function testUnregisteredFilterIsMappedToTemplateLine(): void
    {
        self::tpl('filter_missing', "static line\n{{ value |> nosuchfilter_at_all }}\nstatic line");

        try {
            self::render('filter_missing', ['value' => 'x']);
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            // The tokenizer raises this without a location (it is constructed
            // without a template); the compiler attaches one. Before it did, the
            // message pointed at the engine frame that threw.
            $this->assertSame('filter_missing', $e->templateName);
            $this->assertSame(2, $e->templateLine);
            $this->assertStringContainsString('filter_missing', $e->getFile());
        }
    }

    public function testErrorInsideParentBlockIsMappedToParentTemplate(): void
    {
        self::tpl('errmap_layout', "[{% block title %}\n{{ title |> nosuchfilter_at_all }}\n{% endblock %}]");
        self::tpl(
            'errmap_child',
            '{% extends "errmap_layout" %}{% block title %}{% parent %}{% endblock %}'
        );

        try {
            self::render('errmap_child', ['title' => 'x']);
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            // `{% parent %}` compiles the PARENT's fragment, so the failure is the
            // parent's — it must name the parent template and line, not the child
            // that spliced it in, and not the engine frame.
            $this->assertSame('errmap_layout', $e->templateName);
            $this->assertSame(2, $e->templateLine);
            $this->assertStringContainsString('errmap_layout', $e->getFile());
        }
    }

    public function testUnclosedOutputTagKeepsTheScannersOwnLine(): void
    {
        self::tpl('unclosed_out', "line one\nline two\n{{ value \n");

        try {
            self::render('unclosed_out', ['value' => 'x']);
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            // The scanner knows which line the tag OPENED on and carries it. The
            // compiler must fill in only the missing name, leaving that line alone.
            $this->assertSame('unclosed_out', $e->templateName);
            $this->assertSame(3, $e->templateLine);
            $this->assertStringContainsString('unclosed_out', $e->getFile());
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
            $this->assertSame('filter_throws', $e->templateName);
            $this->assertSame(2, $e->templateLine);
            $this->assertInstanceOf(\LogicException::class, $e->getPrevious());
        }
    }

    public function testTypeErrorOnFilterArgumentIsMappedToTemplateLine(): void
    {
        // A typed filter parameter rejects the piped value â†’ TypeError.  This is
        // an Error (not an E_* diagnostic), so it can only be mapped by catching
        // it around the render call.
        TestEnvironment::engine()->addFilter('wants_string', static fn(string $v): string => $v);

        self::tpl('filter_type_error', "a\nb\n{{ value |> wants_string }}\nd");

        try {
            self::render('filter_type_error', ['value' => ['not', 'a', 'string']]);
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertSame('filter_type_error', $e->templateName);
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
            $this->assertSame('inline_throws', $e->templateName);
            $this->assertSame(2, $e->templateLine);
            $this->assertInstanceOf(\DomainException::class, $e->getPrevious());
        }
    }

    /**
     * A PHP diagnostic (the most common template error) is mapped by the render
     * error handler, which reads the compiled class's metadata directly.  Both
     * the file and its physical path must come out of that — the path travels
     * with the source from the loader, so it is in the class even though the
     * loader is not consulted here.
     */
    public function testMappedDiagnosticCarriesThePhysicalTemplatePath(): void
    {
        self::tpl('diag_path', "static\n{% if context.authenticated %}\nvisible\n{% endif %}");

        try {
            self::render('diag_path', ['context' => []]);
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertSame('diag_path', $e->templateName);
            $this->assertSame(2, $e->templateLine);
            $this->assertSame(
                str_replace('\\', '/', self::normalizedSourcePath('diag_path')),
                str_replace('\\', '/', $e->templatePath)
            );
            $this->assertSame($e->templatePath, $e->getFile());
        }
    }

    /**
     * An error inside an INCLUDED template must carry the included file's own
     * path, not the host's.  This is what the parallel `$sourcePaths` list on the
     * compiled class buys: the source map names the included template, and the
     * path is looked up at that same index.
     */
    public function testMappedDiagnosticInAnIncludedTemplateUsesTheIncludedPath(): void
    {
        self::tpl('inc_path_host', "host\n{% include \"inc_path_part\" %}\nhost end");
        self::tpl('inc_path_part', "part one\n{% if context.authenticated %}\nvisible\n{% endif %}");

        try {
            self::render('inc_path_host', ['context' => []]);
            $this->fail('Expected ClarityException was not thrown');
        } catch (ClarityException $e) {
            $this->assertSame('inc_path_part', $e->templateName);
            $this->assertSame(2, $e->templateLine);
            $this->assertStringEndsWith(
                'inc_path_part.clarity.html',
                str_replace('\\', '/', $e->templatePath)
            );
            $this->assertStringNotContainsString('host', $e->templatePath);
        }
    }

    /**
     * The path is baked into the compiled class, so it survives even when the
     * loader that supplied it has been swapped out — the whole reason it is
     * carried with the source rather than re-resolved at error time.
     */
    public function testCompiledClassCarriesSourcePathsIncludingForNonFileLoaders(): void
    {
        $engine = new TestClarityEngine([
            'viewPath'  => TestEnvironment::viewDir(),
            'cachePath' => TestEnvironment::cacheDir(),
            'extension' => 'clarity.html',
            'policy'    => \Clarity\Engine\Policy::restricted(),
        ]);
        $engine->setLoader(new \Clarity\Template\ArrayLoader([
            'array_paths' => '{{ x }}',
        ]));
        $this->assertSame('one', $engine->renderPartial('array_paths', ['x' => 'one']));

        $cache = new \ReflectionProperty($engine, 'cache');
        $cache->setAccessible(true);
        $className = $cache->getValue($engine)->getLoadedClassName('array_paths');

        // A non-file loader has no path to report, so the parallel entry is ''
        // rather than absent — the list stays index-aligned with sourceFiles.
        $this->assertSame(['array_paths'], $className::$sourceFiles);
        $this->assertSame([''], $className::$sourcePaths);
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

    /**
     * A deprecation raised by a template is an ordinary template diagnostic: it
     * must reach the application handler.  Excluding E_DEPRECATED from the
     * handler mask would keep Clarity's handler from ever being entered, so PHP
     * would report it against the compiled cache file — an internal path — and
     * the application would neither see nor be able to suppress it.
     */
    public function testDeprecationInsideTemplateReachesApplicationHandler(): void
    {
        $seen = [];
        set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$seen): bool {
            $seen[] = [$no, $msg, $file, $line];
            return true;
        });

        try {
            // `upper` hands null to mb_strtoupper(): a deprecation in weak mode,
            // and the case the CHANGELOG advertises as replacing a silent ''.
            $engine = TestClarityEngine::withPolicy(
                \Clarity\Engine\Policy::default()->denyRule('strictTypes')
            );
            self::tpl('dep_from_filter', "TOP\n{{ null |> upper }}\nBOTTOM");

            ob_start();
            try {
                $output = $engine->renderPartial('dep_from_filter');
            } finally {
                $stray = ob_get_clean();
            }
        } finally {
            restore_error_handler();
        }

        $this->assertSame("TOP\n\nBOTTOM", $output, 'rendering continues past a deprecation');
        $this->assertSame('', trim((string) $stray), 'the diagnostic must not leak to the client');

        $deprecations = array_values(array_filter(
            $seen,
            static fn(array $e): bool => in_array($e[0], [E_DEPRECATED, E_USER_DEPRECATED], true)
        ));
        $this->assertCount(1, $deprecations, 'the template deprecation must reach the application handler once');

        [$no, $msg, $file, $line] = $deprecations[0];
        $this->assertSame(E_USER_DEPRECATED, $no, 'a deprecation must stay a deprecation');
        $this->assertStringContainsString('mb_strtoupper()', $msg);
        $this->assertStringContainsString('in dep_from_filter:2', $msg, 'the message carries the template location');
        $this->assertStringEndsWith(
            'dep_from_filter.clarity.html',
            str_replace('\\', '/', $file),
            'the reported file is the template, never the compiled cache file'
        );
        $this->assertSame(2, $line, 'the reported line is the template line');
    }

    /**
     * The level is a faithful translation, not a collapse to a warning: a
     * handler that promotes warnings to exceptions must not abort the render
     * over a notice.
     */
    public function testNoticeLevelSurvivesTranslation(): void
    {
        $seen = [];
        set_error_handler(static function (int $no, string $msg) use (&$seen): bool {
            $seen[] = [$no, $msg];
            return true;
        });

        try {
            $engine = TestClarityEngine::withPolicy(\Clarity\Engine\Policy::unrestricted());
            self::tpl('notice_in_body', "{% php trigger_error('a template notice', E_USER_NOTICE); %}");

            $output = $engine->renderPartial('notice_in_body');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('', $output);
        $this->assertSame(
            [E_USER_NOTICE],
            array_column($seen, 0),
            'a template notice must arrive as E_USER_NOTICE, not E_USER_WARNING'
        );
        $this->assertStringContainsString('a template notice', $seen[0][1]);
    }

    /**
     * A diagnostic that originated in the template is reported at the template's
     * file and line, not the compiled cache file's: the cache path is an
     * implementation detail and must not leak into logs or the response.
     */
    public function testForwardedDiagnosticIsReportedAtTheTemplateLocation(): void
    {
        $seen = [];
        set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$seen): bool {
            $seen[] = [$no, $msg, $file, $line];
            return true;
        });

        try {
            // `items` is present but not iterable, so the loop diagnostic is
            // forwarded rather than mapped to a ClarityException.
            self::tpl('forward_loc', "one\n{% for item in items %}{{ item }}{% endfor %}\ntwo");
            $output = self::render('forward_loc', ['items' => null]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame("one\ntwo", $output);
        $this->assertCount(1, $seen);

        [$no, $msg, $file, $line] = $seen[0];
        $this->assertSame(E_USER_WARNING, $no);
        $this->assertStringContainsString('in forward_loc:2', $msg);
        $this->assertStringNotContainsString(
            'cache',
            str_replace('\\', '/', $file),
            'the compiled cache path must not be reported'
        );
        $this->assertStringEndsWith('forward_loc.clarity.html', str_replace('\\', '/', $file));
        $this->assertSame(2, $line);
    }
}