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

### __construct() · [source](../../src/Engine/Compiler.php#L73)

`public function __construct(): mixed`

**➡️ Return value**

- Type: mixed


---

### setFilterRegistry() · [source](../../src/Engine/Compiler.php#L78)

`public function setFilterRegistry(Clarity\Engine\FunctionRegistry $registry): static`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$registry` | [FunctionRegistry](Clarity_Engine_FunctionRegistry.md) | - |  |

**➡️ Return value**

- Type: static


---

### setBasePath() · [source](../../src/Engine/Compiler.php#L88)

`public function setBasePath(string $path): static`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - |  |

**➡️ Return value**

- Type: static


---

### setExtension() · [source](../../src/Engine/Compiler.php#L94)

`public function setExtension(string $ext): static`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ext` | string | - |  |

**➡️ Return value**

- Type: static


---

### setNamespaces() · [source](../../src/Engine/Compiler.php#L100)

`public function setNamespaces(array $namespaces): static`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$namespaces` | array | - |  |

**➡️ Return value**

- Type: static


---

### compile() · [source](../../src/Engine/Compiler.php#L116)

`public function compile(string $sourcePath): Clarity\Engine\CompiledTemplate`

Compile a template file and return a CompiledTemplate value object.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$sourcePath` | string | - | Absolute path to the .clarity.html file. |

**➡️ Return value**

- Type: [CompiledTemplate](Clarity_Engine_CompiledTemplate.md)

**⚠️ Throws**

- [ClarityException](Clarity_ClarityException.md)  On compilation errors.



---

[Back to the Index ⤴](README.md)
