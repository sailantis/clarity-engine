# 🧩 Class: StringLoader

**Full name:** [Clarity\Template\StringLoader](../../src/Template/StringLoader.php)

Single-template loader that wraps one hardcoded template string.

Useful for rendering one dynamically-built or user-supplied template
without touching the filesystem.

```php
$loader = new StringLoader('dynamic', '<p>{{ message }}</p>');
$engine->setLoader($loader);
echo $engine->render('dynamic', ['message' => 'Hello!']);
```

## 🚀 Public methods

### __construct() · [source](../../src/Template/StringLoader.php#L25)

`public function __construct(string $name, string $code): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Logical template name used to reference this template. |
| `$code` | string | - | Raw template source. |

**➡️ Return value**

- Type: mixed


---

### exists() · [source](../../src/Template/StringLoader.php#L33)

`public function exists(string $name): bool`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: bool


---

### load() · [source](../../src/Template/StringLoader.php#L38)

`public function load(string $name): Clarity\Template\TemplateSource`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: [TemplateSource](Clarity_Template_TemplateSource.md)


---

### update() · [source](../../src/Template/StringLoader.php#L55)

`public function update(string $code): static`

Replace the template source.

The revision changes automatically so the next render triggers recompilation.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$code` | string | - |  |

**➡️ Return value**

- Type: static



---

[Back to the Index ⤴](README.md)
