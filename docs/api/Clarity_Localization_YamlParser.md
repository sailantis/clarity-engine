# Class: YamlParser

**Full name:** [Clarity\Localization\YamlParser](../../src/Localization/YamlParser.php)

Minimal YAML parser for flat and nested translation files.

Supports the subset of YAML that translation catalogs typically use:
  - Key: value pairs (flat and nested, nested flattened to dot notation)
  - Quoted strings: single-quoted ('' escape) and double-quoted (\n, \t, …)
  - Block scalars: literal (|) and folded (>), with strip (|-) and (>-)
  - Inline comments: # after unquoted values
  - YAML document comments: # on their own line
  - Null literals (`null`, `~`, and empty values) returned as empty strings
  - Booleans (`true`, `false`) returned as their literal text

Does NOT support: anchors (&), aliases (*), sequences as mapping values,
multi-document streams (---), or other advanced YAML features.

The parser is kept small for translation files. For full YAML support, replace it
with a library such as symfony/yaml. `FileTranslationLoader` is the only caller of
parse(), so only that call site needs to change.

## Public methods

### parse() · <small>[🗎](../../src/Localization/YamlParser.php#L46)</small>

`public static function parse(string $yaml): array`

Parse a YAML string and return a flat key → string map.

Nested mappings are flattened using dot notation. Lines that are not
supported mapping entries are skipped without an error.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$yaml` | string | - |  |

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
