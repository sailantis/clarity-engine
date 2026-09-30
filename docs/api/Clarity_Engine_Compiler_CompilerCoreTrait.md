# Class: CompilerCoreTrait

**Full name:** [Clarity\Engine\Compiler\CompilerCoreTrait](../../src/Engine/Compiler/CompilerCoreTrait.php)

Extracted from Clarity\Engine\Compiler to keep each file small. See that class for docs.

## Public methods

### compile() · <small>[🗎](../../src/Engine/Compiler/CompilerCoreTrait.php#L27)</small>

`public function compile(string $templateName, Clarity\Template\TemplateLoader $loader): Clarity\Engine\CompiledTemplate`

Compile a template and return a CompiledTemplate value object.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - | Logical template name (e.g. 'home', 'admin::dashboard'). |
| `$loader` | [TemplateLoader](Clarity_Template_TemplateLoader.md) | - | Loader used to fetch source for this template and its<br>dependencies (extends parents, includes). |

**Return value**

- Type: [CompiledTemplate](Clarity_Engine_CompiledTemplate.md)

**Throws**

- [ClarityException](Clarity_ClarityException.md)  On compilation errors.



---

[Back to the Index ⤴](README.md)
