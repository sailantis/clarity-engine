# 🧩 Class: LocaleStack

**Full name:** [Clarity\Localization\LocaleStack](../../src/Localization/LocaleStack.php)

A simple stack-based locale manager.

Templates use `{% with_locale "fr_FR" %}` to temporarily push a locale onto
the stack. All locale-aware filters in scope read the current top of the
stack, so nested blocks work as expected.

The stack is a plain PHP object shared by value (reference semantics) between
the module's filter closures and the compiled template's `$this->__fl` map,
so push/pop calls are immediately visible to all filters in the same render.

Example:
```clarity
{% with_locale "fr_FR" %}
    {{ price |> currency("EUR") }}
{% endwith_locale %}
```
Compiled to:
```php
$this->__fl['__locale']->push("fr_FR");
// … rendered body …
$this->__fl['__locale']->pop();
```

## 🚀 Public methods

### __construct() · [source](../../src/Localization/LocaleStack.php#L41)

`public function __construct(string $defaultLocale = ''): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$defaultLocale` | string | `''` | Locale returned when the stack is empty.<br>Defaults to PHP's Locale::getDefault(), or<br>'en_US' if that is unset. |

**➡️ Return value**

- Type: mixed


---

### push() · [source](../../src/Localization/LocaleStack.php#L54)

`public function push(string|null $locale): void`

Push a locale onto the stack.

Passing null or an empty string is a no-op so that template variables
that may be null do not corrupt the stack.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | - |  |

**➡️ Return value**

- Type: void


---

### pop() · [source](../../src/Localization/LocaleStack.php#L66)

`public function pop(): void`

Pop the top locale from the stack.

Calling this when the stack is empty is a no-op.

**➡️ Return value**

- Type: void


---

### current() · [source](../../src/Localization/LocaleStack.php#L75)

`public function current(): string`

Return the currently active locale (top of the stack), or the default
locale when the stack is empty.

**➡️ Return value**

- Type: string


---

### setDefault() · [source](../../src/Localization/LocaleStack.php#L85)

`public function setDefault(string $locale): void`

Change the default locale used when the stack is empty.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string | - |  |

**➡️ Return value**

- Type: void



---

[Back to the Index ⤴](README.md)
