<?php
namespace Clarity\Tests;

use Clarity\ClarityEngine;
use Clarity\Engine\Policy;
use Clarity\Engine\Registry;

class TestClarityEngine extends ClarityEngine
{
    public function getRegistry(): Registry
    {
        return $this->registry;
    }

    /**
     * A fresh engine that compiles with an explicit policy, for tests that need
     * one capability rather than the whole set.
     */
    public static function withPolicy(Policy|array $policy, array $config = []): self
    {
        return new self(\array_merge([
            'viewPath'  => TestEnvironment::viewDir(),
            'cachePath' => TestEnvironment::cacheDir(),
            'extension' => 'clarity.html',
            'policy'    => $policy,
        ], $config));
    }
}
