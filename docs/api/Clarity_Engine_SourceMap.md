# Class: SourceMap

**Full name:** [Clarity\Engine\SourceMap](../../src/Engine/SourceMap.php)

Compact wire format for the compiled source map.

The compiler emits the source map into the compiled cache file as a PHP
literal.  The natural representation — `list<array{int,int,int}>` via
var_export() — is very expensive for what it holds: every range costs ~65
bytes of PHP to express three small integers (~236 B of retained memory),
which made the metadata ~2.3x the size of the render body it annotates.

This class encodes the same information as ONE delimited string:

  "lineDelta,fileIndex,tplLineDelta;lineDelta,fileIndex,tplLineDelta;..."

Line numbers are delta-encoded because the map is appended in ascending line
order, so the deltas stay in single digits however long the template is; the
file index is left absolute since it is already tiny.

Measured on a 1000-range map (see temp/probe-meta-representation.php):

  var_export nested arrays   65,348 B source   236,536 B memory
  packed string              10,450 B source    12,288 B memory   (-95%)

The decode cost (~0.1 ms per 500 ranges) is paid only on the error path,
which is the only place the map is read.

Invariant: decode(encode($map)) === $map for any map the compiler produces
(ascending phpLine starts, integer file indices and template lines).

## Public methods

### encode() · <small>[🗎](../../src/Engine/SourceMap.php#L46)</small>

`public static function encode(array $map): string`

Encode a source map as the compact string form.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$map` | array | - | list of [phpLineStart, fileIndex, templateLine] |

**Return value**

- Type: string
- Description: Empty string for an empty map.


---

### decode() · <small>[🗎](../../src/Engine/SourceMap.php#L78)</small>

`public static function decode(string $packed): array`

Decode the compact string form back into the list-of-ranges shape.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$packed` | string | - | String produced by `encode()`. |

**Return value**

- Type: array
- Description: list of [phpLineStart, fileIndex, templateLine]


---

### normalise() · <small>[🗎](../../src/Engine/SourceMap.php#L119)</small>

`public static function normalise(mixed $packed): array`

Normalise a compiled class's `$sourceMap` property to the list-of-ranges
shape, whatever form it is in.

Current classes hold the packed string (`packedLiteral()`); a class
emitted by an older compiler holds the raw nested array. This is read on
the ERROR path, so it must never throw — a TypeError raised while
formatting another exception would replace the real error. Anything
unrecognised degrades to an empty map, i.e. "no line mapping", which is
the safe answer (an absent line number beats a wrong one).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$packed` | mixed | - | Value of a compiled class's $sourceMap property. |

**Return value**

- Type: array
- Description: list of [phpLineStart, fileIndex, templateLine]


---

### packedLiteral() · <small>[🗎](../../src/Engine/SourceMap.php#L146)</small>

`public static function packedLiteral(array $map): string`

Build the PHP literal for a compiled class's `$sourceMap` property.

The packed form is a single-quoted string.  It cannot contain a quote or
a backslash (it is only digits and separators), but addcslashes() is used
anyway so the invariant holds even if the separators are ever changed.

The returned literal always fits on one line, whatever the map size,
which keeps compiled files readable and avoids pathological line counts.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$map` | array | - | Source map to emit. |

**Return value**

- Type: string
- Description: PHP expression, e.g. "'1,0,1;3,0,3'"



---

[Back to the Index ⤴](README.md)
