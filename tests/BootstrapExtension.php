<?php
namespace Clarity\Tests;

use PHPUnit\Runner\AfterLastTestHook;
use PHPUnit\Runner\BeforeFirstTestHook;


class BootstrapExtension implements BeforeFirstTestHook, AfterLastTestHook
{
    public function executeBeforeFirstTest(): void
    {
        $testId = 'static';
        $viewDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_test_views_' . $testId;
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clarity_test_cache_' . $testId;
        @mkdir($viewDir, 0755, true);
        @mkdir($cacheDir, 0755, true);

        $engine = new \Clarity\Tests\TestClarityEngine();
        $engine
            ->setViewPath($viewDir)
            ->setCachePath($cacheDir)
            ->setExtension('clarity.html');

        \Clarity\Tests\TestEnvironment::setEngine($engine);
        \Clarity\Tests\TestEnvironment::setRegistry($engine->getRegistry());
        \Clarity\Tests\TestEnvironment::setPaths($viewDir, $cacheDir);
    }

    public function executeAfterLastTest(): void
    {
        $this->removeDir(\Clarity\Tests\TestEnvironment::viewDir());
        $this->removeDir(\Clarity\Tests\TestEnvironment::cacheDir());
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
