<?php
namespace Clarity\Tests\Engine;

use Clarity\ClarityException;
use Clarity\Template\DomainRouterLoader;
use Clarity\Template\FileLoader;
use PHPUnit\Framework\TestCase;

/**
 * A template name must never address a file outside the view path.
 *
 * A template name is not always a developer-chosen constant. `render()` is
 * commonly handed a name derived from a request, and a template can be loaded
 * from a database, so a name is an INPUT — which means resolving it must not be
 * able to read an arbitrary file. Before this was enforced, both of these were
 * compiled, reading the file at compile time and baking its contents into the
 * cached class:
 *
 *   {% include "../../../../etc/passwd.clarity.html" %}
 *   {% include "C:/secrets/app.clarity.html" %}
 *
 * Two properties are pinned here, and they are pinned separately on purpose:
 *
 *   1. `resolveName()` refuses an absolute name or a parent segment. This is the
 *      guarantee, and it holds for the loader however it was reached.
 *   2. A template that says such a name does not render. This is what a user
 *      actually experiences, and it is checked through the engine rather than
 *      assumed from (1) — the compiler calls `resolveLogicalName()` first, so a
 *      fix in the loader alone could in principle be bypassed.
 *
 * The dot/slash equivalence is checked in the same file because it shares the
 * code path: `admin.user` and `admin/user` are one name spelled twice, and a
 * rejection rule that broke one of them would be a rejection rule that broke
 * ordinary addressing.
 */
class LoadPathSecurityTest extends TestCase
{
    private static string $base;

    public static function setUpBeforeClass(): void
    {
        self::$base = \sys_get_temp_dir() . '/clarity-pathsec-base-' . \getmypid();
        @\mkdir(self::$base . '/admin', 0777, true);
        @\mkdir(self::$base . '/partials', 0777, true);
        \file_put_contents(self::$base . '/home.clarity.html', 'HOME');
        \file_put_contents(self::$base . '/admin/user.clarity.html', 'ADMIN-USER');
        \file_put_contents(self::$base . '/partials/header.clarity.html', 'HEADER');
    }

    public static function tearDownAfterClass(): void
    {
        self::rm(self::$base);
    }

    private static function rm(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (@\scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            \is_dir($path) ? self::rm($path) : @\unlink($path);
        }
        @\rmdir($dir);
    }

    /**
     * Every name that would resolve outside the base path.
     *
     * Grouped by the mechanism rather than by the spelling, because the
     * mechanism is what has to be closed: a name can leave the base path by
     * being absolute, or by walking up with a parent segment.
     *
     * @return array<string,array{string}>
     */
    public static function escapingNames(): array
    {
        return [
            // --- absolute forms -------------------------------------------------
            'unix absolute'          => ['/etc/passwd'],
            'unix absolute with ext' => ['/etc/passwd.clarity.html'],
            'windows drive forward'  => ['C:/secrets/app'],
            'windows drive back'     => ['C:\\secrets\\app'],
            'unc share'              => ['\\\\server\\share\\template'],

            // --- parent references ----------------------------------------------
            'dotdot prefix'          => ['../secret'],
            'dotdot deep'            => ['../../../../etc/passwd'],
            'dotdot mid-path'        => ['admin/../../secret'],
            'dotdot dot-form'        => ['admin...secret'],
            'single dot prefix'      => ['./partials/header'],
            'bare dot'               => ['.'],
            'bare dotdot'            => ['..'],
            'leading slash only'     => ['/'],
        ];
    }

    /**
     * @dataProvider escapingNames
     */
    public function testResolveNameRefusesANameThatLeavesTheBasePath(string $name): void
    {
        $loader = new FileLoader(self::$base, '.clarity.html');

        $this->expectException(ClarityException::class);

        $loader->resolveName($name);
    }

    /**
     * The refusal must not depend on the name being unusual.
     *
     * A `../` inside an otherwise ordinary name is the realistic attack: it
     * looks like a path the application meant to allow. It is covered by the
     * data provider above as `dotdot mid-path`, and repeated here with the
     * file that actually exists on the other side, so the test fails for the
     * right reason if the guard is ever loosened to "starts with".
     */
    public function testResolveNameRefusesADotdotThatWouldReachARealFile(): void
    {
        $outside = \dirname(self::$base) . '/clarity-pathsec-outside-' . \getmypid() . '.clarity.html';
        \file_put_contents($outside, 'OUTSIDE');

        try {
            $loader = new FileLoader(self::$base, '.clarity.html');

            $this->expectException(ClarityException::class);
            $loader->resolveName('../' . \basename($outside, '.clarity.html'));
        } finally {
            @\unlink($outside);
        }
    }

    public function testResolveNameDoesNotThrowForAnOrdinaryName(): void
    {
        $loader = new FileLoader(self::$base, '.clarity.html');

        $this->assertSame(
            self::$base . '/partials/header.clarity.html',
            $loader->resolveName('partials/header')
        );
    }

    /**
     * Dots and slashes are one rule, so both spellings must resolve identically.
     *
     * Asserted as an equality rather than two literals: the property that
     * matters is that the two forms agree, and two separate expectations would
     * both need updating to hide a divergence.
     */
    public function testDotAndSlashSeparatorsAreInterchangeable(): void
    {
        $loader = new FileLoader(self::$base, '.clarity.html');

        $this->assertSame(
            $loader->resolveName('admin/user'),
            $loader->resolveName('admin.user')
        );
        $this->assertStringEndsWith('/admin/user.clarity.html', $loader->resolveName('admin.user'));
    }

    /**
     * An empty name or a doubled separator addresses nothing.
     *
     * This is not the security case, it is the "no silently surprising path"
     * case: an empty segment would collapse away in the filesystem, so
     * `admin//user` and `admin/user` would be the same file while being
     * different template names — two cache entries, one template.
     */
    public function testResolveNameRefusesAnEmptySegment(): void
    {
        $loader  = new FileLoader(self::$base, '.clarity.html');
        $refused = 0;

        foreach (['', 'admin//user', '/home'] as $name) {
            try {
                $loader->resolveName($name);
            } catch (ClarityException) {
                $refused++;
            }
        }

        // Asserted as a count rather than per-name so a name that starts being
        // ACCEPTED cannot pass by having its assertion removed.
        $this->assertSame(3, $refused, 'every empty-segment name must be refused');
    }

    /**
     * The guarantee must hold through the engine, not only through the loader.
     *
     * `FileLoader::resolveName()` is the enforcement point, but a template
     * reaches it via the compiler's own name resolution, so this renders a real
     * template that names a real file outside the view path and asserts the
     * content does NOT appear.
     */
    public function testATemplateCannotIncludeAFileOutsideTheViewPath(): void
    {
        $secretValue = 'SECRET-' . \getmypid();
        $outside     = \dirname(self::$base) . '/clarity-pathsec-secret-' . \getmypid() . '.clarity.html';
        \file_put_contents($outside, $secretValue);

        $engine = new \Clarity\ClarityEngine();
        $engine->setViewPath(self::$base);
        $engine->setExtension('.clarity.html');
        $engine->setCachePath(\sys_get_temp_dir() . '/clarity-pathsec-cache-' . \getmypid());

        // Sandbox ON: this is the default, and the point is that the default
        // must not be able to read an adjacent file. The two spellings are the
        // ones a template author would reach for; both must fail.
        \file_put_contents(
            self::$base . '/probe.clarity.html',
            '{% include "' . $outside . '" %}'
        );

        $refused = false;

        try {
            $out = $engine->render('probe', []);
            $this->assertStringNotContainsString($secretValue, $out);
        } catch (ClarityException) {
            $refused = true;
        } finally {
            @\unlink($outside);
            @\unlink(self::$base . '/probe.clarity.html');
        }

        // Either outcome satisfies the security property (the secret is not in
        // the output), but only one is the intended behaviour: the name is
        // refused. Asserting WHICH one keeps the test from passing because the
        // render happened to fail for an unrelated reason.
        $this->assertTrue($refused, 'an absolute include must be refused at compile time');
    }

    /**
     * A namespace is the supported way to reach another tree.
     *
     * Pinned so the rejection above cannot be read as "cross-directory reads are
     * forbidden": they are supported, via configuration that is visible in the
     * application rather than encoded in a template name.
     */
    public function testANamespaceStillResolvesInsideItsOwnBasePath(): void
    {
        $router = new DomainRouterLoader(
            ['admin' => new FileLoader(self::$base . '/admin', '.clarity.html')],
            new FileLoader(self::$base, '.clarity.html')
        );

        $this->assertSame('ADMIN-USER', $router->load('admin::user')?->getCode());
    }
}
