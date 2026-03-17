<?php
namespace Clarity\Tests\Engine;

use Clarity\Template\ArrayLoader;
use Clarity\Template\CompositeLoader;
use Clarity\Template\DomainRouterLoader;
use Clarity\Template\FileLoader;
use PHPUnit\Framework\TestCase;

class TemplateLoaderArchitectureTest extends TestCase
{
    public function testResolveNameCacheIsScopedPerLoaderInstance(): void
    {
        $first = new FileLoader('/views/a');
        $second = new FileLoader('/views/b');

        $this->assertSame('/views/a/home.clarity.html', $first->resolveName('home'));
        $this->assertSame('/views/b/home.clarity.html', $second->resolveName('home'));
    }

    public function testResolveNameCacheInvalidatesOnBasePathChange(): void
    {
        $loader = new FileLoader('/views/a');

        $this->assertSame('/views/a/home.clarity.html', $loader->resolveName('home'));

        $loader->setBasePath('/views/b');

        $this->assertSame('/views/b/home.clarity.html', $loader->resolveName('home'));
    }

    public function testResolveNameCacheInvalidatesOnExtensionChange(): void
    {
        $loader = new FileLoader('/views/a');

        $this->assertSame('/views/a/home.clarity.html', $loader->resolveName('home'));

        $loader->setExtension('twig');

        $this->assertSame('/views/a/home.twig', $loader->resolveName('home'));
    }

    public function testDomainRouterLoaderExposesSubLoaders(): void
    {
        $adminLoader = new FileLoader('/views/admin');
        $baseLoader = new FileLoader('/views/base');
        $router = new DomainRouterLoader(['admin' => $adminLoader], $baseLoader);

        $this->assertContains($adminLoader, $router->getSubLoaders());
        $this->assertContains($baseLoader,  $router->getSubLoaders());
    }

    public function testDomainRouterLoaderThrowsOnUnknownDomain(): void
    {
        $router = new DomainRouterLoader(['app' => new FileLoader('/views/app')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/Unknown template domain 'unknown'/");
        $router->load('unknown::page');
    }

    public function testCompositeLoaderTriesLoadersInOrder(): void
    {
        $first = new ArrayLoader(['home' => 'from-first']);
        $second = new ArrayLoader(['home' => 'from-second', 'about' => 'from-second']);
        $composite = new CompositeLoader($first, $second);

        // 'home' is found in first — first loader wins
        $this->assertSame('from-first', $composite->load('home')?->getCode());
        // 'about' is not in first — falls through to second
        $this->assertSame('from-second', $composite->load('about')?->getCode());
        // unknown name — all loaders miss, returns null
        $this->assertNull($composite->load('unknown'));
    }
}