# 🧩 Class: ArrayLoader

**Full name:** [Clarity\Template\ArrayLoader](../../src/Template/ArrayLoader.php)

In-memory template loader backed by a plain PHP array.

Ideal for unit testing, dynamic/generated templates, and small applications
that keep all templates in code rather than on the filesystem.

Cache revision is derived from the source string via hash('fnv1a64', $code).
No file I/O takes place at any point.

```php
$loader = new ArrayLoader([
    'home'         => '<h1>Hello {{ name }}</h1>',
    'layouts/base' => '<!DOCTYPE html><body>{% block content %}{% endblock %}</body>',
]);
$engine->setLoader($loader);
```

## 🚀 Public methods

### __construct() · [source](../../src/Template/ArrayLoader.php#L29)

`public function __construct(array $templates = []): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templates` | array | `[]` | Map of logical name → raw template source. |

**➡️ Return value**

- Type: mixed


---

### exists() · [source](../../src/Template/ArrayLoader.php#L34)

`public function exists(string $name): bool`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: bool


---

### load() · [source](../../src/Template/ArrayLoader.php#L39)

`public function load(string $name): Clarity\Template\TemplateSource`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: [TemplateSource](Clarity_Template_TemplateSource.md)


---

### set() · [source](../../src/Template/ArrayLoader.php#L57)

`public function set(string $name, string $code): static`

Add or replace a template definition.

The cache for the template will be invalidated on the next render because
the fnv1a64 revision of the new code will differ from the stored revision.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |
| `$code` | string | - |  |

**➡️ Return value**

- Type: static



---

[Back to the Index ⤴](README.md)
