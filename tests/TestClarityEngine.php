<?php
namespace Clarity\Tests;

use Clarity\ClarityEngine;
use Clarity\Engine\Registry;

class TestClarityEngine extends ClarityEngine
{
    public function getRegistry(): Registry
    {
        return $this->registry;
    }
}

