<?php
namespace Clarity\Tests;

use Clarity\Engine\Policy;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class BaseTestCase extends PHPUnitTestCase
{
    /** Write a template file and return the view name relative to viewDir. */
    protected static function tpl(string $name, string $content): string
    {
        $path = TestEnvironment::viewDir() . DIRECTORY_SEPARATOR . $name . '.clarity.html';
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (is_file($path)) {
            if (file_get_contents($path) === $content) {
                return $name;
            }
        }
        file_put_contents($path, $content);
        return $name;
    }

    protected static function render(string $view, array $vars = []): string
    {
        return TestEnvironment::engine()->renderPartial($view, $vars);
    }

    /**
     * The same engine, with `strictTypes` denied: what a template did before that
     * rule defaulted on. Used to pin weak-mode behaviour deliberately, rather than
     * by accident of the default.
     */
    protected static function weakEngine(): TestClarityEngine
    {
        return TestClarityEngine::withPolicy(Policy::default()->denyRule('strictTypes'));
    }

    // instance wrapper
    protected function renderPartial(string $view, array $vars = []): string
    {
        return static::render($view, $vars);
    }

    /** Return source path using forward slashes so MD5-based cache keys match. */
    protected static function normalizedSourcePath(string $view): string
    {
        return TestEnvironment::viewDir() . '/' . $view . '.clarity.html';
    }

    /**
     * Return the compiled PHP source of a template that has already been
     * rendered (compiled and loaded).  Useful for asserting on emitted code.
     */
    protected function compiledSource(string $view): string
    {
        $engine = TestEnvironment::engine();
        $cache  = new \ReflectionProperty($engine, 'cache');
        $cache->setAccessible(true);
        $className = $cache->getValue($engine)->getLoadedClassName($view);

        $this->assertIsString($className, 'the template must be compiled and loaded');

        return (string) file_get_contents((new \ReflectionClass($className))->getFileName());
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
