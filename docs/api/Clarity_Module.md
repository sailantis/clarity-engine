# 🔌 Interface: Module

**Full name:** [Clarity\Module](../../src/Module.php)

Contract for Clarity engine modules.

A module bundles a cohesive set of filters, functions, and block directives
and registers them all in one call via [`ClarityEngine::use()`](Clarity_ClarityEngine.md#use).

Example
-------
```php
$clarity->use(new LocalizationModule([
    'locale'            => 'de_DE',
    'fallback_locale'   => 'en_US',
    'translations_path' => __DIR__ . '/locales',
]));
```

Implementing a module
---------------------
```php
class MyModule implements Module
{
    public function register(ClarityEngine $engine): void
    {
        $engine->addFilter('my_filter', fn($v) => strtoupper($v));
        $engine->addBlock('my_block', fn($rest, $path, $line, $expr) => '// …');
    }
}
```

## 🚀 Public methods

### register() · [source](../../src/Module.php#L45)

`public function register(Clarity\ClarityEngine $engine): void`

Register all filters, functions, services, and block directives that
this module provides into the given engine instance.

This method is called once by [`ClarityEngine::use()`](Clarity_ClarityEngine.md#use) at engine
setup time, before any templates are compiled or rendered.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ClarityEngine](Clarity_ClarityEngine.md) | - | The engine to register into. |

**➡️ Return value**

- Type: void



---

[Back to the Index ⤴](README.md)
