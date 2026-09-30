# Class: VarChainTrait

**Full name:** [Clarity\Engine\Tokenizer\VarChainTrait](../../src/Engine/Tokenizer/VarChainTrait.php)

Extracted from Clarity\Engine\Tokenizer to keep each file small. See that class for docs.

## Public methods

### varChainToPhp() · <small>[🗎](../../src/Engine/Tokenizer/VarChainTrait.php#L656)</small>

`public function varChainToPhp(string $chain): string`

Convert a Clarity var-chain string to a PHP $__c_va[...] expression.

Supports:
foo           â†’ $__c_va['foo']
foo.bar       â†’ $__c_va['foo']['bar']
items[0]      â†’ $__c_va['items'][0]
items[index]  â†’ $__c_va['items'][$__c_va['index']]
a.b[c.d].e    â†’ $__c_va['a']['b'][$__c_va['c']['d']]['e']

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$chain` | string | - |  |

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
