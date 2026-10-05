<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityEngine;
use Clarity\ClarityException;
use Clarity\Engine\Directive;
use Clarity\Template\ArrayLoader;
use Clarity\Template\FileLoader;
use Clarity\Template\TemplateLocation;
use PHPUnit\Framework\TestCase;

/**
 * The argument-list overload of the directive `$processExpr` callable.
 *
 * A handler receives the raw text after its keyword, so a multi-argument tag such
 * as `{% cache "user_" ~ id, ttl: 300, tags: ["user"] %}` would otherwise force
 * every directive author to re-implement the comma split and the named-argument
 * rule that filters already own.  `$processExpr($rest, true)` returns the
 * compiled list instead — `[positional, named]` — and the single-expression form
 * is unchanged.
 *
 * The second half of the file covers the location contract around a handler: a
 * `ClarityException` thrown inside a handler (or by the argument parser) must
 * name the template, its line, and the physical file when the loader has one.
 */
class DirectiveArgumentsTest extends TestCase
{
    private string $cacheDir;
    private int $baseLevel;

    protected function setUp(): void
    {
        $this->cacheDir  = \sys_get_temp_dir() . '/clarity_directive_args_' . \bin2hex(\random_bytes(6));
        $this->baseLevel = \ob_get_level();
        \mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        while (\ob_get_level() > $this->baseLevel) {
            if (!@\ob_end_clean()) {
                break;
            }
        }
        $this->removeDir($this->cacheDir);
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            \is_dir($path) ? $this->removeDir($path) : @\unlink($path);
        }
        @\rmdir($dir);
    }

    /**
     * @param array<string,string>   $templates
     * @param array<string,callable> $directives
     * @param bool                   $paired     Register `keyword`/`endkeyword` as a
     *                                           paired construct (default) or as an
     *                                           ordinary unpaired directive.
     */
    private function engine(array $templates, array $directives = [], bool $paired = true): ClarityEngine
    {
        $engine = new ClarityEngine();
        $engine->setLoader(new ArrayLoader($templates));
        $engine->setCachePath($this->cacheDir);

        foreach ($directives as $keyword => $handler) {
            $engine->addDirective($keyword, $handler, $paired ? Directive::opens('end' . $keyword) : null);
            if ($paired) {
                $engine->addDirective('end' . $keyword, static fn(): string => '');
            }
        }

        return $engine;
    }

    /**
     * The argument-list tests use an UNPAIRED directive, so the list itself is
     * the only thing that can fail; the location tests below opt into a construct.
     */
    private function listEngine(array $templates, array $directives): ClarityEngine
    {
        return $this->engine($templates, $directives, false);
    }

    // =========================================================================
    // The argument list
    // =========================================================================

    public function testArgumentListSplitsPositionalAndNamed(): void
    {
        $captured = null;
        $engine   = $this->listEngine(
            ['t' => '{% probe "user_" ~ id, ttl: 300, tags: ["user"] %}'],
            ['probe' => function (string $rest, TemplateLocation $at, callable $processExpr) use (&$captured): string {
                [$captured['positional'], $captured['named']] = $processExpr($rest, true);

                return '';
            }]
        );

        $engine->renderPartial('t', ['id' => 7]);

        self::assertSame(['"user_" . $__c_va[\'id\']'], $captured['positional']);
        self::assertSame(['ttl' => '300', 'tags' => '["user"]'], $captured['named']);
    }

    public function testNamedOnlyListKeyedByName(): void
    {
        $captured = null;
        $engine   = $this->listEngine(
            ['t' => '{% probe key: "k_" ~ id, ttl: 60 %}'],
            ['probe' => function (string $rest, TemplateLocation $at, callable $processExpr) use (&$captured): string {
                [$captured['positional'], $captured['named']] = $processExpr($rest, true);

                return '';
            }]
        );

        $engine->renderPartial('t', ['id' => 7]);

        self::assertSame([], $captured['positional']);
        self::assertSame(['key' => '"k_" . $__c_va[\'id\']', 'ttl' => '60'], $captured['named']);
    }

    public function testCommasInsideLiteralsDoNotSplit(): void
    {
        $captured = null;
        $engine   = $this->listEngine(
            ['t' => '{% probe "a,b", tags: ["a", "b"], meta: {a: 1, b: 2} %}'],
            ['probe' => function (string $rest, TemplateLocation $at, callable $processExpr) use (&$captured): string {
                [$captured['positional'], $captured['named']] = $processExpr($rest, true);

                return '';
            }]
        );

        $engine->renderPartial('t');

        self::assertSame(['"a,b"'], $captured['positional']);
        self::assertSame(['tags' => '["a", "b"]', 'meta' => "['a' => 1, 'b' => 2]"], $captured['named']);
    }

    public function testSingleExpressionFormIsUnchanged(): void
    {
        $captured = null;
        $engine   = $this->listEngine(
            ['t' => '{% probe 1 + 2 %}'],
            ['probe' => function (string $rest, TemplateLocation $at, callable $processExpr) use (&$captured): string {
                $captured = $processExpr($rest);

                return '';
            }]
        );

        $engine->renderPartial('t');

        // A string, not a list: the single-expression contract still holds.
        self::assertSame('1 + 2', $captured);
    }

    public function testEmptyArgumentListIsAnEmptyPair(): void
    {
        $captured = null;
        $engine   = $this->listEngine(
            ['t' => '{% probe %}'],
            ['probe' => function (string $rest, TemplateLocation $at, callable $processExpr) use (&$captured): string {
                $captured = $processExpr($rest, true);

                return '';
            }]
        );

        $engine->renderPartial('t');

        self::assertSame([[], []], $captured);
    }

    // =========================================================================
    // Parser failures are located
    // =========================================================================

    public function testPositionalAfterNamedIsRejected(): void
    {
        $this->assertListRejects(
            "line1\n{% probe ttl: 300, \"oops\" %}",
            'Positional argument after named argument'
        );
    }

    public function testDuplicateNamedArgumentIsRejected(): void
    {
        $this->assertListRejects(
            "line1\n{% probe ttl: 300, ttl: 60 %}",
            "Duplicate named argument 'ttl'"
        );
    }

    public function testEmptyArgumentIsRejected(): void
    {
        $this->assertListRejects("line1\n{% probe a,,b %}", 'Empty argument');
    }

    public function testMalformedArgumentIsLocated(): void
    {
        // A `[` segment that never closes is rejected by the tokenizer itself, so
        // the failure happens inside the argument compilation.
        $this->assertListRejects('{% probe [ %}', "Unterminated '[' segment");
    }

    public function testListParserErrorNamesTheTemplateAndLine(): void
    {
        $engine = $this->listEngine(
            ['t' => "line1\n{% probe ttl: 300, ttl: 60 %}"],
            ['probe' => static fn(string $rest, TemplateLocation $at, callable $processExpr): string
                => '/* ' . \count($processExpr($rest, true)[0]) . ' */']
        );

        try {
            $engine->renderPartial('t');
        } catch (ClarityException $e) {
            self::assertSame('t', $e->templateName);
            self::assertSame(2, $e->templateLine);

            return;
        }

        self::fail('Expected a located ClarityException from the argument parser.');
    }

    // =========================================================================
    // A handler's own exception is located
    // =========================================================================

    public function testHandlerThrownClarityExceptionNamesTheTemplateAndLine(): void
    {
        $engine = $this->engine(
            ['t' => "line1\n{% probe %}"],
            ['probe' => static function (): string {
                throw new ClarityException('handler boom');
            }]
        );

        try {
            $engine->renderPartial('t');
        } catch (ClarityException $e) {
            self::assertSame('handler boom', $e->getMessage());
            self::assertSame('t', $e->templateName);
            self::assertSame(2, $e->templateLine);
            // getFile()/getLine() follow the location, so an uncaught fatal and an
            // IDE's "open frame" both land on the template.
            self::assertSame('t:2', $e->getFile() . ':' . $e->getLine());

            return;
        }

        self::fail('Expected the handler exception to be located against the template.');
    }

    public function testHandlerThrownClarityExceptionKeepsAnExplicitLocation(): void
    {
        $engine = $this->engine(
            ['t' => "line1\n{% probe %}"],
            ['probe' => static function (string $rest, TemplateLocation $at): string {
                throw new ClarityException('explicit', 'custom-name', 99);
            }]
        );

        try {
            $engine->renderPartial('t');
        } catch (ClarityException $e) {
            self::assertSame('custom-name', $e->templateName);
            self::assertSame(99, $e->templateLine);

            return;
        }

        self::fail('Expected a ClarityException.');
    }

    public function testHandlerThrownClarityExceptionNamesThePhysicalPathWithFileLoader(): void
    {
        $views = \sys_get_temp_dir() . '/clarity_directive_views_' . \bin2hex(\random_bytes(6));
        \mkdir($views, 0755, true);
        \file_put_contents($views . '/page.clarity.html', "line1\n{% probe %}\n");

        $engine = new ClarityEngine();
        $engine->setCachePath($this->cacheDir);
        $engine->setLoader(new FileLoader($views, 'clarity.html'));
        $engine->addDirective('probe', static function (): string {
            throw new ClarityException('file loader boom');
        }, Directive::opens('endprobe'));
        $engine->addDirective('endprobe', static fn(): string => '');

        try {
            $engine->renderPartial('page');
            self::fail('Expected a ClarityException.');
        } catch (ClarityException $e) {
            self::assertSame('page', $e->templateName);
            self::assertSame(2, $e->templateLine);
            self::assertNotSame('', $e->templatePath, 'a file-backed loader must contribute the path');
            self::assertFileExists($e->templatePath);
        } finally {
            @\unlink($views . '/page.clarity.html');
            @\rmdir($views);
        }
    }

    // =========================================================================
    // The handler is handed the whole location
    // =========================================================================

    public function testHandlerReceivesATemplateLocation(): void
    {
        $seen   = null;
        $engine = $this->listEngine(
            ['t' => "line1\n{% probe %}"],
            ['probe' => function (string $rest, TemplateLocation $at) use (&$seen): string {
                $seen = $at;

                return '';
            }]
        );

        $engine->renderPartial('t');

        self::assertInstanceOf(TemplateLocation::class, $seen);
        self::assertSame('t', $seen->name);
        self::assertSame(2, $seen->line);
        self::assertSame('', $seen->path, 'an array loader has no file to name');
    }

    public function testLocationCarriesThePhysicalPathWithFileLoader(): void
    {
        $views = \sys_get_temp_dir() . '/clarity_directive_views_' . \bin2hex(\random_bytes(6));
        \mkdir($views, 0755, true);
        \file_put_contents($views . '/page.clarity.html', "line1\n{% probe %}{% endprobe %}\n");

        $seen   = null;
        $engine = new ClarityEngine();
        $engine->setCachePath($this->cacheDir);
        $engine->setLoader(new FileLoader($views, 'clarity.html'));
        $engine->addDirective('probe', static function (string $rest, TemplateLocation $at) use (&$seen): string {
            $seen = $at;

            return '';
        }, Directive::opens('endprobe'));
        $engine->addDirective('endprobe', static fn(): string => '');

        try {
            $engine->renderPartial('page');
            self::assertSame('page', $seen->name);
            self::assertSame(2, $seen->line);
            self::assertStringEndsWith('page.clarity.html', $seen->path);
        } finally {
            @\unlink($views . '/page.clarity.html');
            @\rmdir($views);
        }
    }

    /**
     * The entire point of handing the location over: a handler that throws with it
     * produces a COMPLETE exception where it stands, so there is nothing left for
     * the engine to fill in — and therefore no second, nested exception. Asserting
     * `assertSame` on the instance is what pins "not re-wrapped".
     */
    public function testThrowingWithTheLocationIsCompleteAndNotRewrapped(): void
    {
        $views = \sys_get_temp_dir() . '/clarity_directive_views_' . \bin2hex(\random_bytes(6));
        \mkdir($views, 0755, true);
        \file_put_contents($views . '/page.clarity.html', "line1\n{% probe %}{% endprobe %}\n");

        $thrown = null;
        $engine = new ClarityEngine();
        $engine->setCachePath($this->cacheDir);
        $engine->setLoader(new FileLoader($views, 'clarity.html'));
        $engine->addDirective(
            'probe',
            static function (string $rest, TemplateLocation $at) use (&$thrown): string {
                throw $thrown = new ClarityException('handler boom', $at);
            },
            Directive::opens('endprobe')
        );
        $engine->addDirective('endprobe', static fn(): string => '');

        try {
            $engine->renderPartial('page');
            self::fail('Expected a ClarityException.');
        } catch (ClarityException $e) {
            self::assertSame($thrown, $e, 'a complete exception must be rethrown, not re-wrapped');
            self::assertSame('handler boom', $e->getMessage());
            self::assertSame('page', $e->templateName);
            self::assertSame(2, $e->templateLine);
            self::assertNotSame('', $e->templatePath, 'the path must survive, not be lost to the name');
            self::assertFileExists($e->templatePath);
            self::assertSame($e->templatePath . ':2', $e->getFile() . ':' . $e->getLine());
        } finally {
            @\unlink($views . '/page.clarity.html');
            @\rmdir($views);
        }
    }

    public function testThrowingWithTheLocationIsCompleteWithoutAPath(): void
    {
        $thrown = null;
        $engine = $this->engine(
            ['t' => "line1\n{% probe %}"],
            ['probe' => static function (string $rest, TemplateLocation $at) use (&$thrown): string {
                throw $thrown = new ClarityException('boom', $at);
            }]
        );

        try {
            $engine->renderPartial('t');
            self::fail('Expected a ClarityException.');
        } catch (ClarityException $e) {
            self::assertSame($thrown, $e);
            self::assertSame('t', $e->templateName);
            self::assertSame(2, $e->templateLine);
            self::assertSame('', $e->templatePath);
            self::assertSame('t:2', $e->getFile() . ':' . $e->getLine());
        }
    }

    public function testNonClarityExceptionIsNotHijacked(): void
    {
        $engine = $this->engine(
            ['t' => '{% probe %}'],
            ['probe' => static function (): string {
                throw new \LogicException('author logic bug');
            }]
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('author logic bug');
        $engine->renderPartial('t');
    }

    // =========================================================================
    // The pairing errors ride the same wrapper
    // =========================================================================

    public function testStrayMemberTagIsLocated(): void
    {
        $this->assertRejects('{% endprobe %}', 'Unexpected');
    }

    public function testUnclosedConstructNamesItsDeclaredCloseKeyword(): void
    {
        $message = $this->assertRejects("line1\n{% probe %}body", "Unclosed '{% probe %}' tag");
        self::assertStringContainsString("add '{% endprobe %}'", $message);
    }

    // =========================================================================

    /**
     * Register an unpaired `probe` directive that compiles the argument list, and
     * assert that rendering rejects with a message that carries the template line.
     */
    private function assertListRejects(string $template, string $messageContains): string
    {
        $engine = $this->listEngine(['t' => $template], [
            'probe' => static fn(string $rest, TemplateLocation $at, callable $processExpr): string
                => '/* ' . \count($processExpr($rest, true)[0]) . ' */',
        ]);

        return $this->assertRenderRejects($engine, $template, $messageContains);
    }

    /**
     * Register a paired `probe`/`endprobe` construct and assert the same.
     */
    private function assertRejects(string $template, string $messageContains): string
    {
        $engine = $this->engine(['t' => $template], [
            'probe' => static fn(string $rest, TemplateLocation $at, callable $processExpr): string
                => '/* ' . \count($processExpr($rest, true)[0]) . ' */',
        ]);

        return $this->assertRenderRejects($engine, $template, $messageContains);
    }

    private function assertRenderRejects(ClarityEngine $engine, string $template, string $messageContains): string
    {
        try {
            $engine->renderPartial('t');
        } catch (ClarityException $e) {
            self::assertStringContainsString($messageContains, $e->getMessage());
            self::assertNotSame(0, $e->templateLine, 'the error must name the template line');
            self::assertSame('t', $e->templateName);

            return $e->getMessage();
        }

        self::fail("Expected a ClarityException containing '{$messageContains}'.");
    }
}
