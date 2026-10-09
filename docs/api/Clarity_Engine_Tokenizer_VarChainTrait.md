# Class: VarChainTrait

**Full name:** [Clarity\Engine\Tokenizer\VarChainTrait](../../src/Engine/Tokenizer/VarChainTrait.php)

Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.

## Public methods

### varChainToPhp() · <small>[🗎](../../src/Engine/Tokenizer/VarChainTrait.php#L703)</small>

`public function varChainToPhp(string $chain): string`

Convert a Clarity var-chain string to PHP.

The root is a `$__c_va[...]` lookup, or a PHP local in open mode (see
`rootPhp()`). The examples show the sandbox-mode output:

  foo           → $__c_va['foo']
  foo.bar       → $__c_va['foo']['bar']
  items[0]      → $__c_va['items'][0]
  items[index]  → $__c_va['items'][$__c_va['index']]
  a.b[c.d].e    → $__c_va['a']['b'][$__c_va['c']['d']]['e']

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$chain` | string | - |  |

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
