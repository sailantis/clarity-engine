# 🧩 Class: YamlParser

**Full name:** [Clarity\Localization\YamlParser](../../src/Localization/YamlParser.php)

Minimal YAML parser for flat and nested translation files.

Supports the subset of YAML that translation catalogs typically use:
  - Key: value pairs (flat and nested, nested flattened to dot notation)
  - Quoted strings: single-quoted ('' escape) and double-quoted (\n, \t, …)
  - Block scalars: literal (|) and folded (>), with strip (|-) and (>-)
  - Inline comments: # after unquoted values
  - YAML document comments: # on their own line
  - Boolean / null literals returned as empty string (null, ~, true, false)

Does NOT support: anchors (&), aliases (*), sequences as mapping values,
multi-document streams (---), or other advanced YAML features.

This parser is intentionally simple and optimized for translation files.
Replace with a full YAML library (e.g. symfony/yaml) when you need full spec
compliance — the TranslationLoader only calls parse() so the swap is trivial.

## 🚀 Public methods

### parse() · [source](../../src/Localization/YamlParser.php#L43)

`public static function parse(string $yaml): array`

Parse a YAML string and return a flat key → string map.

Nested mappings are flattened using dot notation.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$yaml` | string | - |  |

**➡️ Return value**

- Type: array



---

[Back to the Index ⤴](README.md)
