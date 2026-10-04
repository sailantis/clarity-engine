# Class: TemplateLocation

**Full name:** [Clarity\Template\TemplateLocation](../../src/Template/TemplateLocation.php)

Where a template construct sits: the logical name it was compiled under, the
line within it, and the physical file that name resolved to.

A custom directive handler receives one of these as its second argument, so it
can raise a [`ClarityException`](Clarity_ClarityException.md) that is COMPLETE — naming the
template AND the file an editor can open — instead of only the logical name:

```php
$engine->addDirective('cache', function (string $rest, TemplateLocation $at, callable $processExpr): string {
    if ($rest === '') {
        throw new ClarityException('cache needs a key', $at);
    }
    return "\$__c_sv['cache']->begin({$processExpr($rest)});";
});
```

The handler cannot derive this itself: the active loader is the only authority
on the physical path, and the compiler is the only layer holding it. Carrying
the path here is what keeps a handler's exception from being re-wrapped with
information the handler was never given.

`$path` is `''` when the active loader has no file to name ([`ArrayLoader`](Clarity_Template_ArrayLoader.md),
[`StringLoader`](Clarity_Template_StringLoader.md), a database loader). The logical name is then all there is,
exactly as in [`ClarityException`](Clarity_ClarityException.md).

## Public Properties

- `public readonly` string `$name` · <small>[🗎](../../src/Template/TemplateLocation.php)</small>
- `public readonly` int `$line` · <small>[🗎](../../src/Template/TemplateLocation.php)</small>
- `public readonly` string `$path` · <small>[🗎](../../src/Template/TemplateLocation.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Template/TemplateLocation.php#L39)</small>

`public function __construct(string $name, int $line, string $path = ''): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Logical template name being compiled (`host`, `part`, or<br>`<owner>#macro#<macro>` for a macro body). |
| `$line` | int | - | 1-based line within that template. |
| `$path` | string | `''` | Physical file the name resolved to, or `''` when the<br>active loader named none. |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
