<?php

namespace Clarity\Tests;

use Clarity\Engine\Registry;

class TestEnvironment
{
    private static ?TestClarityEngine $engine = null;
    private static ?Registry $registry = null;
    private static ?string $viewDir = null;
    private static ?string $cacheDir = null;

    public static function setEngine(TestClarityEngine $engine): void
    {
        self::$engine = $engine;
    }

    public static function engine(): TestClarityEngine
    {
        return self::$engine;
    }

    public static function setRegistry(Registry $registry): void
    {
        self::$registry = $registry;
    }

    public static function registry(): Registry
    {
        return self::$registry;
    }

    public static function setPaths(string $viewDir, string $cacheDir): void
    {
        self::$viewDir = $viewDir;
        self::$cacheDir = $cacheDir;
    }

    public static function viewDir(): string
    {
        return self::$viewDir;
    }

    public static function cacheDir(): string
    {
        return self::$cacheDir;
    }
}
