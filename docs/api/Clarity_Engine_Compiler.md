# 🧩 Class: Compiler

**Full name:** [Clarity\Engine\Compiler](../../src/Engine/Compiler.php)

Compiles a single Clarity template source file into a PHP class.

The compilation pipeline
------------------------
1. Dependency resolution ({% extends %}, {% include %})
   - extends/block is resolved statically: the parent layout is merged with
     child block overrides before any code is generated.
   - include embeds the included file's compiled render body inline.
2. Segmentation via Tokenizer
3. Code generation: each segment is turned into PHP
4. Class wrapping + docblock with source-map and dependency metadata

Output format
-------------
Each compiled template becomes exactly one PHP class:

  class __Clarity_<slug>_<hash> {
      public static array $dependencies = ['/abs/path' => mtime, ...];
      public static array $sourceMap    = [phpLine => tplLine, ...];
      public function __construct(private array $__fl, private array $__fn) }
      public function render(array $vars): string { ... }
  }

$dependencies and $sourceMap are read via reflection for cache invalidation
and error mapping — no file I/O needed on warm paths (OPcache serves them).

## 🚀 Public methods

### __construct() · [source](../../src/Engine/Compiler.php#L108)

`public function __construct(): mixed`

**➡️ Return value**

- Type: mixed


---

### setRegistry() · [source](../../src/Engine/Compiler.php#L113)

`public function setRegistry(Clarity\Engine\Registry $registry): static`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$registry` | [Registry](Clarity_Engine_Registry.md) | - |  |

**➡️ Return value**

- Type: static


---

### setExtension() · [source](../../src/Engine/Compiler.php#L124)

`public function setExtension(string $extension): static`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$extension` | string | - |  |

**➡️ Return value**

- Type: static


---

### setDebugMode() · [source](../../src/Engine/Compiler.php#L130)

`public function setDebugMode(bool $debug): static`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$debug` | bool | - |  |

**➡️ Return value**

- Type: static


---

### compile() · [source](../../src/Engine/Compiler.php#L159)

`public function compile(string $templateName, Clarity\Template\TemplateLoader $loader): Clarity\Engine\CompiledTemplate`

Compile a template and return a CompiledTemplate value object.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$templateName` | string | - | Logical template name (e.g. 'home', 'admin::dashboard'). |
| `$loader` | [TemplateLoader](Clarity_Template_TemplateLoader.md) | - | Loader used to fetch source for this template and its<br>dependencies (extends parents, includes). |

**➡️ Return value**

- Type: [CompiledTemplate](Clarity_Engine_CompiledTemplate.md)

**⚠️ Throws**

- [ClarityException](Clarity_ClarityException.md)  On compilation errors.



---

[Back to the Index ⤴](README.md)
