<?php
namespace Clarity\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class BaseTestCase extends PHPUnitTestCase
{
    /** Write a template file and return the view name relative to viewDir. */
    protected static function tpl(string $name, string $content): string
    {
        $path = TestEnvironment::viewDir() . DIRECTORY_SEPARATOR . $name . '.clarity.html';
        $dir = dirname($path);
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
