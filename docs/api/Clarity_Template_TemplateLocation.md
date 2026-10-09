# Class: TemplateLocation

**Full name:** [Clarity\Template\TemplateLocation](../../src/Template/TemplateLocation.php)

Where a template construct sits: the logical name it was compiled under, the
line within it, and the physical file that name resolved to.

A custom directive handler receives one of these as its second argument. It can
pass this to a [`ClarityException`](Clarity_ClarityException.md) so the error names both the
template and the file:

```php
$engine->addDirective('cache', function (string $rest, TemplateLocation $at, callable $processExpr): string {
    if ($rest === '') {
        throw new ClarityException('cache needs a key', $at);
    }
    return "\$__c_sv['cache']->begin({$processExpr($rest)});";
});
```

The handler cannot derive the path itself, because only the active loader knows
the physical file.

`$path` is `''` when the active loader has no file to name ([`ArrayLoader`](Clarity_Template_ArrayLoader.md),
[`StringLoader`](Clarity_Template_StringLoader.md), a database loader). In that case the logical name is all
that is available, as with [`ClarityException`](Clarity_ClarityException.md) alone.

## Public Properties

- `public readonly` string `$name` · <small>[🗎](../../src/Template/TemplateLocation.php)</small>
- `public readonly` int `$line` · <small>[🗎](../../src/Template/TemplateLocation.php)</small>
- `public readonly` string `$path` · <small>[🗎](../../src/Template/TemplateLocation.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Template/TemplateLocation.php#L37)</small>

`public function __construct(string $name, int $line, string $path = ''): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Logical name being compiled: the root template, an included<br>template, or `<owner>#macro#<macro>` for a macro body. |
| `$line` | int | - | 1-based line within that template. |
| `$path` | string | `''` | Physical file the name resolved to, or `''` when the<br>active loader named none. |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
