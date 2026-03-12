<?php
namespace Clarity\Tests\Engine;

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

    public function testResolveNameCacheInvalidatesOnNamespaceChange(): void
    {
        $loader = new FileLoader('/views/base', '.clarity.html', ['admin' => '/views/admin/v1']);

        $this->assertSame('/views/admin/v1/dashboard.clarity.html', $loader->resolveName('admin::dashboard'));

        $loader->addNamespace('admin', '/views/admin/v2');

        $this->assertSame('/views/admin/v2/dashboard.clarity.html', $loader->resolveName('admin::dashboard'));
    }
}
