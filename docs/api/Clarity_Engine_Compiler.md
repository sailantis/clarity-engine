# Class: Compiler

**Full name:** [Clarity\Engine\Compiler](../../src/Engine/Compiler.php)

Compiles a single Clarity template source file into a PHP class.

Architecture
------------
This class holds the public API, the constants and the per-compilation state;
the behaviour is composed from the traits in `Clarity\Engine\Compiler\`
(inheritance, control flow, macros, raw-PHP blocks, source map, code builder,
…).  See CONTRIBUTING.md for the trait map.

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
      public function __construct(private array $__c_fn, private array $__c_sv) }
      public function render(array $__c_va): string { ... }
  }

Every PHP variable the engine binds into the render frame carries the `__c_`
prefix ("c" for Clarity).  The prefix IS the reservation rule: an engine
internal is covered by choosing to spell it `__c_…`, so a newly added
internal cannot silently collide with a template variable whose author never
heard of it.  See Compiler::INTERNAL_PREFIX.

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

- **COMPILER_VERSION** = `20`
- **INTERNAL_PREFIX** = `'__c_'`

## Public methods

### __construct() · <small>[🗎](../../src/Engine/Compiler.php#L260)</small>

`public function __construct(): mixed`

**Return value**

- Type: `mixed`


---

### setRegistry() · <small>[🗎](../../src/Engine/Compiler.php#L267)</small>

`public function setRegistry(Clarity\Engine\Registry $registry): static`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$registry` | [Registry](Clarity_Engine_Registry.md) | - |  |

**Return value**

- Type: `static`


---

### setExtension() · <small>[🗎](../../src/Engine/Compiler.php#L278)</small>

`public function setExtension(string $extension): static`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$extension` | string | - |  |

**Return value**

- Type: `static`


---

### setDebugMode() · <small>[🗎](../../src/Engine/Compiler.php#L284)</small>

`public function setDebugMode(bool $debug): static`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$debug` | bool | - |  |

**Return value**

- Type: `static`


---

### setPolicy() · <small>[🗎](../../src/Engine/Compiler.php#L308)</small>

`public function setPolicy(Clarity\Engine\Policy $policy): static`

Set what compiled templates are allowed to reach.

The tokenizer is given the same object rather than a copy of the flag it
used to receive, so a capability can never be granted in one half of the
compiler and denied in the other.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$policy` | [Policy](Clarity_Engine_Policy.md) | - |  |

**Return value**

- Type: `static`


---

### getPolicy() · <small>[🗎](../../src/Engine/Compiler.php#L315)</small>

`public function getPolicy(): Clarity\Engine\Policy`

**Return value**

- Type: [Policy](Clarity_Engine_Policy.md)


---

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

### registerVar() · <small>[🗎](../../src/Engine/Compiler/DirectiveSupportTrait.php#L77)</small>

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

### unregisterVar() · <small>[🗎](../../src/Engine/Compiler/DirectiveSupportTrait.php#L110)</small>

`public function unregisterVar(string $name): mixed`

Unregister a local variable from the compile-time context.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | The name of the variable to unregister. |

**Return value**

- Type: `mixed`


---

### getVars() · <small>[🗎](../../src/Engine/Compiler/DirectiveSupportTrait.php#L121)</small>

`public function getVars(): array`

Get the currently registered local variables.

**Return value**

- Type: `array`
- Description: Map of local variable names to their PHP representations.



---

[Back to the Index ⤴](README.md)
