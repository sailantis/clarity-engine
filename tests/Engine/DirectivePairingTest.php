<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityEngine;
use Clarity\ClarityException;
use Clarity\Engine\Directive;
use Clarity\Engine\Registry;
use Clarity\Template\ArrayLoader;
use Clarity\Template\TemplateLocation;
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
     * @param array<string,string>    $templates
     * @param array<string,callable>  $directives keyword → handler
     * @param array<string,Directive> $roles      keyword → Directive declaration
     */
    private function engine(array $templates, array $directives = [], array $roles = []): ClarityEngine
    {
        $engine = new ClarityEngine();
        $engine->setLoader(new ArrayLoader($templates));
        $engine->setCachePath($this->cacheDir);

        foreach ($directives as $keyword => $handler) {
            $engine->addDirective($keyword, $handler, $roles[$keyword] ?? null);
        }

        return $engine;
    }

    /**
     * The canonical construct used throughout: an opener that buffers, a branch
     * tag, and a closer that echoes the buffer.
     *
     * @return array{array<string,callable>, array<string,Directive>}
     */
    private function cacheConstruct(): array
    {
        $directives = [
            'cache'         => static fn(string $rest, TemplateLocation $at, callable $e): string
                => 'ob_start(); /* open ' . $e(\trim($rest)) . ' */',
            'cacheelse'     => static fn(): string => 'echo "|";',
            'endcache'      => static fn(): string => 'echo ob_get_clean();',
            'cache_control' => static fn(): string => '/* ctrl */',
            'noop'          => static fn(): string => '/* noop */',
        ];
        $roles = [
            'cache'         => Directive::opens('endcache', 'cacheelse'),
            'endcache'      => Directive::closes('cache'),
            'cacheelse'     => Directive::branches('cache'),
            'cache_control' => Directive::inside('cache'),
        ];

        return [$directives, $roles];
    }

    private function render(string $template, array $vars = []): string
    {
        [$directives, $roles] = $this->cacheConstruct();

        return $this->engine(['t' => $template], $directives, $roles)
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
        [$directives, $roles] = $this->cacheConstruct();
        $directives['outer']    = static fn(): string => '/* outer */';
        $directives['endouter'] = static fn(): string => '/* endouter */';
        $roles['outer']         = Directive::opens('endouter');

        $engine = $this->engine(
            ['t' => '{% outer %}{% cache k %}b{% endouter %}{% endcache %}'],
            $directives,
            $roles
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
        [$directives, $roles] = $this->cacheConstruct();
        $directives['outer'] = static fn(): string => '/* outer */';
        $roles['outer']      = Directive::opens('endouter');
        $directives['endouter'] = static fn(): string => '/* endouter */';

        $engine = $this->engine(['t' => '{% outer %}{% cacheelse %}{% endouter %}'], $directives, $roles);

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
    // Containment-only directives (Directive::inside)
    // =========================================================================

    public function testContainedLeafRendersInsideItsOwner(): void
    {
        self::assertSame(
            'A b Z',
            $this->render('A {% cache k %}b{% cache_control %}{% endcache %} Z', ['k' => 'k1'])
        );
    }

    /**
     * Containment is lexical, not per-segment: after a branch tag the construct is
     * still open, so the leaf is still inside it.
     */
    public function testContainedLeafRendersInsideABranchSegment(): void
    {
        self::assertSame(
            'A b|c Z',
            $this->render('A {% cache k %}b{% cacheelse %}c{% cache_control %}{% endcache %} Z', ['k' => 'k1'])
        );
    }

    /**
     * An include is inlined into the same render body, so a contained tag within
     * it really runs inside the open construct — unlike a close, containment may
     * cross the unit boundary.
     */
    public function testContainedLeafRendersFromAnInclude(): void
    {
        [$directives, $roles] = $this->cacheConstruct();

        $engine = $this->engine(
            ['t' => '{% cache k %}b{% include "inner" %}{% endcache %}', 'inner' => '{% cache_control %}'],
            $directives,
            $roles
        );

        self::assertSame('b', $engine->renderPartial('t', ['k' => 'k1']));
    }

    public function testContainedLeafOutsideItsOwnerIsRejected(): void
    {
        $this->assertRejects(
            'A {% cache_control %} Z',
            "'{% cache_control %}' is only valid inside '{% cache %}'"
        );
    }

    public function testContainedLeafInTheWrongConstructIsRejected(): void
    {
        [$directives, $roles] = $this->cacheConstruct();
        $directives['outer']    = static fn(): string => '/* outer */';
        $directives['endouter'] = static fn(): string => '/* endouter */';
        $roles['outer']         = Directive::opens('endouter');

        $engine = $this->engine(
            ['t' => '{% outer %}{% cache_control %}{% endouter %}'],
            $directives,
            $roles
        );

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/is only valid inside '\\{% cache %\\}'/");
        $engine->renderPartial('t');
    }

    // =========================================================================
    // Template-unit boundaries
    // =========================================================================

    public function testCloseAcrossIncludeBoundaryIsRejected(): void
    {
        [$directives, $roles] = $this->cacheConstruct();

        // The INNER template's own body closes the host's construct; the inner
        // unit's depth snapshot is what catches it, because an include is inlined
        // into the same render body.
        $engine = $this->engine(
            ['t' => '{% cache k %}{% include "inner" %}', 'inner' => 'x{% endcache %}'],
            $directives,
            $roles
        );

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/may not span an include or a macro/');
        $engine->renderPartial('t', ['k' => 'k1']);
    }

    public function testConstructLeftOpenInIncludeNamesTheInclude(): void
    {
        [$directives, $roles] = $this->cacheConstruct();

        $engine = $this->engine(
            ['t' => '{% include "inner" %}', 'inner' => 'x {% cache k %}y'],
            $directives,
            $roles
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
        $engine->addDirective('cache', static fn(): string => '', Directive::opens('endif'));
    }

    public function testBuiltinKeywordCannotBeAnOwner(): void
    {
        $engine = new ClarityEngine();

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/cannot belong to the built-in keyword/');
        $engine->addDirective('endcache', static fn(): string => '', Directive::closes('for'));
    }

    public function testSelfOwnershipIsRejected(): void
    {
        $engine = new ClarityEngine();

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/cannot belong to itself/');
        $engine->addDirective('cache', static fn(): string => '', Directive::closes('cache'));
    }

    public function testSelfBranchIsRejected(): void
    {
        $engine = new ClarityEngine();

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/cannot belong to itself/');
        $engine->addDirective('cacheelse', static fn(): string => '', Directive::branches('cacheelse'));
    }

    public function testSelfClosingOpenerIsRejected(): void
    {
        $engine = new ClarityEngine();

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/cannot close itself/');
        $engine->addDirective('cache', static fn(): string => '', Directive::opens('cache'));
    }

    public function testBranchCannotAlsoBeTheCloseTag(): void
    {
        $engine = new ClarityEngine();

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches('/both the closing tag and a branch tag/');
        $engine->addDirective('cache', static fn(): string => '', Directive::opens('endcache', 'endcache'));
    }

    public function testMissingMemberHandlerIsCaughtBeforeCompiling(): void
    {
        [$directives, $roles] = $this->cacheConstruct();
        unset($directives['endcache'], $roles['endcache']);

        $engine = $this->engine(['t' => 'hello'], $directives, $roles);

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/no handler is registered for 'endcache'/");
        $engine->renderPartial('t');
    }

    public function testOrphanedOwnerClaimIsCaughtBeforeCompiling(): void
    {
        $engine = $this->engine(
            ['t' => 'hello'],
            ['endfoo' => static fn(): string => ''],
            ['endfoo' => Directive::closes('foo')]
        );

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/no such directive is registered/");
        $engine->renderPartial('t');
    }

    public function testClaimTheOwnerDoesNotHonourIsCaught(): void
    {
        [$directives, $roles] = $this->cacheConstruct();
        $directives['endother'] = static fn(): string => '';
        $roles['endother']      = Directive::closes('cache');

        $engine = $this->engine(['t' => 'hello'], $directives, $roles);

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/does not declare it as a close or branch/");
        $engine->renderPartial('t');
    }

    /**
     * The member's claim is deliberately redundant with the opener — that is what
     * makes "declared it a branch, called it a closer" a caught error rather than a
     * silent override.
     */
    public function testClaimedRoleMustMatchTheOwnersDeclaration(): void
    {
        [$directives, $roles] = $this->cacheConstruct();
        $roles['endcache'] = Directive::branches('cache');

        $engine = $this->engine(['t' => 'hello'], $directives, $roles);

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/claims to be a branch tag of 'cache'/");
        $engine->renderPartial('t');
    }

    public function testContainmentOwnerMustBeAnOpener(): void
    {
        $engine = $this->engine(
            ['t' => 'hello'],
            ['noop' => static fn(): string => '', 'leaf' => static fn(): string => ''],
            ['leaf' => Directive::inside('noop')]
        );

        $this->expectException(ClarityException::class);
        $this->expectExceptionMessageMatches("/not a registered opener/");
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

        $engine->addDirective('endcache', static fn(): string => 'echo ob_get_clean();', Directive::closes('cache'));
        $engine->addDirective('cache', static fn(): string => 'ob_start();', Directive::opens('endcache'));

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
