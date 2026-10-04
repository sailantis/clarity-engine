<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityEngine;
use Clarity\ClarityException;
use Clarity\Engine\Registry;
use Clarity\Template\ArrayLoader;
use PHPUnit\Framework\TestCase;

/**
 * Compile-time coupling of paired custom directives.
 *
 * A custom directive is unpaired by default, so nothing here changes until an
 * opener declares member tags.  The failure mode being guarded against is not a
 * crash but CORRUPTED, usually still-parseable output: an unclosed
 * `{% cache %}` leaks an output buffer into the next render in the same request,
 * and a stray `{% endcache %}` silently swallows the engine's own render buffer.
 *
 * The tests therefore assert on the exception, not on the compiled PHP — the
 * engine must refuse to emit code it cannot prove is balanced.
 */
class DirectivePairingTest extends TestCase
{
    private string $cacheDir;
    private int $baseLevel;

    protected function setUp(): void
    {
        $this->cacheDir  = \sys_get_temp_dir() . '/clarity_pairing_' . \bin2hex(\random_bytes(6));
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

    /**
     * An engine with the `cache` construct registered, plus an unrelated unpaired
     * directive.  Overridable so individual tests can vary the registration.
     *
     * @param array<string,string>   $templates
     * @param array<string,callable> $directives keyword → handler
     * @param array<string,array>    $pairings   keyword → pairing argument
     */
    private function engine(array $templates, array $directives = [], array $pairings = []): ClarityEngine
    {
        $engine = new ClarityEngine();
        $engine->setLoader(new ArrayLoader($templates));
        $engine->setCachePath($this->cacheDir);

        foreach ($directives as $keyword => $handler) {
            $engine->addDirective($keyword, $handler, $pairings[$keyword] ?? null);
        }

        return $engine;
    }

    /**
     * The canonical construct used throughout: an opener that buffers, a branch
     * tag, and a closer that echoes the buffer.
     *
     * @return array{array<string,callable>, array<string,array>}
     */
    private function cacheConstruct(): array
    {
        $directives = [
            'cache'     => static fn(string $rest, string $p, int $l, callable $e): string
                => 'ob_start(); /* open ' . $e(\trim($rest)) . ' */',
            'cacheelse' => static fn(): string => 'echo "|";',
            'endcache'  => static fn(): string => 'echo ob_get_clean();',
            'noop'      => static fn(): string => '/* noop */',
        ];
        $pairings = [
            'cache'     => ['endcache' => 'required', 'cacheelse' => 'allowed'],
            'endcache'  => ['cache' => 'owner'],
            'cacheelse' => ['cache' => 'owner'],
        ];

        return [$directives, $pairings];
    }

    private function render(string $template, array $vars = []): string
    {
        [$directives, $pairings] = $this->cacheConstruct();

        return $this->engine(['t' => $template], $directives, $pairings)
            ->renderPartial('t', $vars);
    }

    private function assertRejects(string $template, string $messageContains, array $vars = []): string
    {
        try {
            $result = $this->render($template, $vars);
        } catch (ClarityException $e) {
            self::assertStringContainsString($messageContains, $e->getMessage());
            self::assertNotSame(0, $e->templateLine, 'the error must name the template line');

            return $e->getMessage();
        }

        self::fail("Expected a ClarityException containing '{$messageContains}', got: {$result}");
    }

    // =========================================================================
    // The happy path and backward compatibility
    // =========================================================================

    public function testBalancedConstructRenders(): void
    {
        self::assertSame('A body Z', $this->render('A {% cache k %}body{% endcache %} Z', ['k' => 'k1']));
    }

    public function testNestedConstructsRender(): void
    {
        self::assertSame('xy', $this->render('{% cache a %}{% cache b %}x{% endcache %}y{% endcache %}', ['a' => 'a', 'b' => 'b']));
    }

    public function testBranchTagRenders(): void
    {
        self::assertSame('A b|e Z', $this->render('A {% cache k %}b{% cacheelse %}e{% endcache %} Z', ['k' => 'k1']));
    }

    public function testConstructBalancedInsideBuiltinBlocks(): void
    {
        self::assertSame('xx', $this->render('{% for i in 1..2 %}{% cache k %}x{% endcache %}{% endfor %}', ['k' => 'k1', 'i' => 0]));
    }

    public function testBuiltinBranchInsideConstructStillWorks(): void
    {
        self::assertSame(
            'yes',
            $this->render('{% cache k %}{% if x %}yes{% else %}no{% endif %}{% endcache %}', ['k' => 'k1', 'x' => true])
        );
    }

    public function testUnpairedDirectiveNeedsNoMetadata(): void
    {
        self::assertSame('A Z', $this->render('A {% noop %}Z'));
    }

    /** A close/branch tag registered with NO opener declaration stays an ordinary leaf. */
    public function testUndeclaredCloseBehavesAsLeafDirective(): void
    {
        $engine = $this->engine(
            ['t' => 'A {% endcache %} Z'],
            ['endcache' => static fn(): string => '/* stray-but-unvalidated */']
        );

        self::assertSame('A  Z', $engine->renderPartial('t'));
    }

    // =========================================================================
    // Missing / stray / crossed
    // =========================================================================

    public function testMissingCloseIsRejectedAtTheOpenerLine(): void
    {
        $message = $this->assertRejects('A {% cache k %}body', "Unclosed '{% cache %}' tag");

        self::assertStringContainsString("add '{% endcache %}'", $message);

        try {
            $this->render("line1\nline2 {% cache k %}body", ['k' => 'k1']);
        } catch (ClarityException $e) {
            self::assertSame(2, $e->templateLine, 'the unclosed opener must be reported at its own line');
        }
    }

    public function testStrayCloseIsRejected(): void
    {
        $this->assertRejects('A {% endcache %} body', "Unexpected '{% endcache %}'");
    }

    public function testCloseOfOtherConstructIsRejected(): void
    {
        [$directives, $pairings] = $this->cacheConstruct();
        $directives['outer']    = static fn(): string => '/* outer */';
        $directives['endouter'] = static fn(): string => '/* endouter */';
        $pairings['outer']      = ['endouter' => 'required'];

        $engine = $this->engine(
            ['t' => '{% outer %}{% cache k %}b{% endouter %}{% endcache %}'],
            $directives,
            $pairings
        );

        try {
            $engine->renderPartial('t', ['k' => 'k1']);
        } catch (ClarityException $e) {
            self::assertStringContainsString("closes '{% outer %}'", $e->getMessage());
            self::assertStringContainsString("'{% cache %}'", $e->getMessage());
            self::assertStringContainsString('is still open', $e->getMessage());

            return;
        }

        self::fail('A close of the outer construct while the inner one is open must be rejected.');
    }

    public function testCloseWhileInnerIfIsOpenIsRejected(): void
    {
        $this->assertRejects(
            '{% cache k %}{% if x %}inner{% endcache %}{% endif %}',
            "an '{% if %}' opened inside it is still open"
        );
    }

    public function testCloseWhileInnerForIsOpenIsRejected(): void
    {
        $this->assertRejects(
            '{% cache k %}{% for i in 1..2 %}x{% endcache %}{% endfor %}',
            "a '{% for %}' opened inside it is still open"
        );
    }

    // =========================================================================
    // Branch tags and built-in branch guard
    // =========================================================================

    public function testBranchOutsideConstructIsRejected(): void
    {
        $this->assertRejects('{% cacheelse %}', "no '{% cache %}' is open");
    }

    public function testBranchInWrongConstructIsRejected(): void
    {
        [$directives, $pairings] = $this->cacheConstruct();
        $directives['outer'] = static fn(): string => '/* outer */';
        $pairings['outer']   = ['endouter' => 'required'];
        $directives['endouter'] = static fn(): string => '/* endouter */';

        $engine = $this->engine(['t' => '{% outer %}{% cacheelse %}{% endouter %}'], $directives, $pairings);

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/belongs to '\\{% cache %\\}'/");
        $engine->renderPartial('t');
    }

    public function testBranchTwiceIsRejected(): void
    {
        $this->assertRejects(
            '{% cache k %}b{% cacheelse %}e{% cacheelse %}f{% endcache %}',
            "may only appear once"
        );
    }

    public function testBuiltinElseDirectlyInsideConstructIsRejected(): void
    {
        $message = $this->assertRejects('{% cache k %}b{% else %}e{% endcache %}', "'{% else %}' is not valid inside");

        self::assertStringContainsString('branch tags', $message);
    }

    public function testStrayEndifInsideConstructIsRejected(): void
    {
        $this->assertRejects('{% cache k %}{% endif %}{% endcache %}', "'{% endif %}' is not valid inside");
    }

    // =========================================================================
    // Template-unit boundaries
    // =========================================================================

    public function testCloseAcrossIncludeBoundaryIsRejected(): void
    {
        [$directives, $pairings] = $this->cacheConstruct();

        // The INNER template's own body closes the host's construct; the inner
        // unit's depth snapshot is what catches it, because an include is inlined
        // into the same render body.
        $engine = $this->engine(
            ['t' => '{% cache k %}{% include "inner" %}', 'inner' => 'x{% endcache %}'],
            $directives,
            $pairings
        );

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/may not span an include or a macro/');
        $engine->renderPartial('t', ['k' => 'k1']);
    }

    public function testConstructLeftOpenInIncludeNamesTheInclude(): void
    {
        [$directives, $pairings] = $this->cacheConstruct();

        $engine = $this->engine(
            ['t' => '{% include "inner" %}', 'inner' => 'x {% cache k %}y'],
            $directives,
            $pairings
        );

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/Unclosed '\\{% cache %\\}' tag/");
        $engine->renderPartial('t', ['k' => 'k1']);
    }

    // =========================================================================
    // Registration and consistency
    // =========================================================================

    public function testBuiltinKeywordCannotBeRegistered(): void
    {
        $engine = new ClarityEngine();

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/built-in keyword/');
        $engine->addDirective('if', static fn(): string => '');
    }

    public function testBuiltinKeywordCannotBeDeclaredAsMember(): void
    {
        $engine = new ClarityEngine();

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/cannot declare the built-in keyword/');
        $engine->addDirective('cache', static fn(): string => '', ['endif' => 'required']);
    }

    public function testDeclarationNeedsExactlyOneRequired(): void
    {
        $engine = new ClarityEngine();

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/exactly one 'required'/");
        $engine->addDirective('cache', static fn(): string => '', ['cacheelse' => 'allowed']);
    }

    public function testUnknownRoleIsRejected(): void
    {
        $engine = new ClarityEngine();

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/Unknown pairing role/');
        $engine->addDirective('cache', static fn(): string => '', ['endcahce' => 'requird']);
    }

    public function testMixedRolesInOneRegistrationAreRejected(): void
    {
        $engine = new ClarityEngine();

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/only one owner/');
        $engine->addDirective('cache', static fn(): string => '', ['cache' => 'owner', 'endcache' => 'required']);
    }

    public function testSelfOwnershipIsRejected(): void
    {
        $engine = new ClarityEngine();

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/cannot own itself/');
        $engine->addDirective('cache', static fn(): string => '', ['cache' => 'owner']);
    }

    public function testMissingMemberHandlerIsCaughtBeforeCompiling(): void
    {
        [$directives, $pairings] = $this->cacheConstruct();
        unset($directives['endcache'], $pairings['endcache']);

        $engine = $this->engine(['t' => 'hello'], $directives, $pairings);

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/no handler is registered for 'endcache'/");
        $engine->renderPartial('t');
    }

    public function testOrphanedOwnerAssertionIsCaughtBeforeCompiling(): void
    {
        $engine = $this->engine(
            ['t' => 'hello'],
            ['endfoo' => static fn(): string => ''],
            ['endfoo' => ['foo' => 'owner']]
        );

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/no such directive is registered/");
        $engine->renderPartial('t');
    }

    public function testOwnerAssertionTheOwnerDoesNotHonourIsCaught(): void
    {
        [$directives, $pairings] = $this->cacheConstruct();
        $directives['endother'] = static fn(): string => '';
        $pairings['endother']   = ['cache' => 'owner'];

        $engine = $this->engine(['t' => 'hello'], $directives, $pairings);

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/does not declare it as a close or branch/");
        $engine->renderPartial('t');
    }

    /**
     * Registration order must not matter: a close registered BEFORE its opener is
     * still recognised, because the reverse index is rebuilt at compile start.
     */
    public function testMemberRegisteredBeforeItsOpenerIsRecognised(): void
    {
        $engine = new ClarityEngine();
        $engine->setLoader(new ArrayLoader(['t' => 'A {% cache k %}b{% endcache %}']));
        $engine->setCachePath($this->cacheDir);

        $engine->addDirective('endcache', static fn(): string => 'echo ob_get_clean();', ['cache' => 'owner']);
        $engine->addDirective('cache', static fn(): string => 'ob_start();', ['endcache' => 'required']);

        self::assertSame('A b', $engine->renderPartial('t', ['k' => 'k1']));
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . \DIRECTORY_SEPARATOR . $entry;
            \is_dir($path) ? $this->removeDir($path) : @\unlink($path);
        }
        @\rmdir($dir);
    }
}
