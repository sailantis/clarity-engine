# Class: Compiler

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
4. Class wrapping + source-map and dependency metadata

Output format
-------------
Each compiled template becomes exactly one PHP class:

  class __Clarity_<slug>_<hash> {
      public static array $dependencies = ['name' => revision, ...];
      public static string $sourceMap   = 'lineDelta,fileIdx,tplDelta;...';
      public function __construct(private array $__fl, private array $__fn) }
      public function render(array $__va): string { ... }
  }

$dependencies and $sourceMap are read via reflection for cache invalidation
and error mapping — no file I/O needed on warm paths (OPcache serves them).

The source map is stored in the compact packed form of [`SourceMap`](Clarity_Engine_SourceMap.md):
as nested var_export() arrays it cost ~2.3x the render body it annotates,
while the packed string is ~9% of that.

Nothing in the emitted code is a doc comment.  Annotations are written as
`//` line comments instead, because OPcache keeps doc comments
(opcache.save_comments) but discards line comments: a docblock is retained
in shared memory for every cached template, while a line comment costs
nothing once the file is cached.  The metadata is reflected, not documented,
so the annotation form is free to choose.

Buffer safety
-------------
render() opens one output buffer and must hand back the buffer LEVEL it
received.  A bare `ob_end_clean()` in the catch block unwinds only the
innermost buffer, so a template that opened one of its own (e.g. a custom
directive doing `ob_start()`) and then threw would strand that buffer -- and
the partial output inside it -- above the caller's.  The catch therefore
drains in a loop down to the level captured immediately AFTER `ob_start()`,
which releases clarity's buffer and everything the template stacked on top of
it, while never reaching the caller's own buffers.

There is deliberately NO finally block.  On the happy path the terminal
`return ob_get_clean()` has already closed clarity's buffer, so a finally
clause would only ever observe its own start level and unwind nothing; the
only finally that could do work is an unconditional unwind, which would
discard the caller's buffer when a template illegally closed clarity's.

## Public Constants

- **COMPILER_VERSION** = `7`

## Public methods

### __construct() · <small>[🗎](../../src/Engine/Compiler.php#L165)</small>

`public function __construct(): mixed`

**Return value**

- Type: mixed


---

### setRegistry() · <small>[🗎](../../src/Engine/Compiler.php#L170)</small>

`public function setRegistry(Clarity\Engine\Registry $registry): static`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$registry` | [Registry](Clarity_Engine_Registry.md) | - |  |

**Return value**

- Type: static


---

### setExtension() · <small>[🗎](../../src/Engine/Compiler.php#L181)</small>

`public function setExtension(string $extension): static`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$extension` | string | - |  |

**Return value**

- Type: static


---

### setDebugMode() · <small>[🗎](../../src/Engine/Compiler.php#L187)</small>

`public function setDebugMode(bool $debug): static`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$debug` | bool | - |  |

**Return value**

- Type: static


---

### compile() · <small>[🗎](../../src/Engine/Compiler.php#L216)</small>

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
