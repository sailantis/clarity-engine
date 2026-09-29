# Class: DirectiveSupportTrait

**Full name:** [Clarity\Engine\Compiler\DirectiveSupportTrait](../../src/Engine/Compiler/DirectiveSupportTrait.php)

Extracted from Clarity\Engine\Compiler to keep each file small. See that class for docs.

## Public methods

### registerVar() · <small>[🗎](../../src/Engine/Compiler/DirectiveSupportTrait.php#L84)</small>

`public function registerVar(string $name, int|null $tplLine = null): mixed`

Register a local variable in the compile-time context.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | The name of the variable to register. |
| `$tplLine` | int\|null | `null` | Line of the directive that requested the<br>registration, when known.  It is only used to<br>point the error at the offending directive<br>rather than at a bare variable name. |

**Return value**

- Type: `mixed`


---

### unregisterVar() · <small>[🗎](../../src/Engine/Compiler/DirectiveSupportTrait.php#L117)</small>

`public function unregisterVar(string $name): mixed`

Unregister a local variable from the compile-time context.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | The name of the variable to unregister. |

**Return value**

- Type: `mixed`


---

### getVars() · <small>[🗎](../../src/Engine/Compiler/DirectiveSupportTrait.php#L128)</small>

`public function getVars(): array`

Get the currently registered local variables.

**Return value**

- Type: `array`
- Description: Map of local variable names to their PHP representations.



---

[Back to the Index ⤴](README.md)
