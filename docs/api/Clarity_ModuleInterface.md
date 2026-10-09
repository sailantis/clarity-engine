# Interface: ModuleInterface

**Full name:** [Clarity\ModuleInterface](../../src/ModuleInterface.php)

Contract for Clarity engine modules.

A module bundles related filters, functions, and directives and registers them
in one call via [`ClarityEngine::addModule()`](Clarity_ClarityEngine.md#addmodule).

Example
-------
```php
$clarity->addModule(new IntlFormatModule([
    'locale'            => 'ja_JP',
]));
```

Implementing a module
---------------------
```php
class MyModule implements ModuleInterface
{
    public function register(ClarityEngine $engine): void
    {
        $engine->addFilter('my_filter', fn($v) => strtoupper($v));
        $engine->addDirective('my_directive', fn($rest, $at, $expr) => '// …');
    }
}
```

## Public methods

### register() · <small>[🗎](../../src/ModuleInterface.php#L44)</small>

`public function register(Clarity\ClarityEngine $engine): void`

Register all filters, functions, services, and directives that
this module provides into the given engine instance.

[`ClarityEngine::addModule()`](Clarity_ClarityEngine.md#addmodule) calls this method immediately. Call
addModule() before rendering templates so the module's filters and
directives are available.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ClarityEngine](Clarity_ClarityEngine.md) | - | The engine to register into. |

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
