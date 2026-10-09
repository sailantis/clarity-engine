# Class: PairedDirectiveTrait

**Full name:** [Clarity\Engine\Compiler\PairedDirectiveTrait](../../src/Engine/Compiler/PairedDirectiveTrait.php)

Compile-time validation of PAIRED custom directives.

A custom directive is unpaired by default and compiles exactly as before.  An
opener that declares member tags ([`Registry::addDirective()`](Clarity_Engine_Registry.md#adddirective)) turns them
into a construct whose structure the compiler can check:

  {% cache %}      opener   — pushes the construct
    {% cacheelse %} branch   — optional, at most once between open and close
  {% endcache %}   close     — pops the construct

Each check rejects output that would be wrong but may still be valid PHP. For
example, a missing close leaks an output buffer into the next render, and a
stray close can end the engine's own buffer:

  - a close/branch tag with no construct open
  - a close/branch tag whose construct is not the innermost open one
  - a construct left open at the end of the render body
  - an inner `{% if %}` / `{% for %}` left open across the close (crossed pair)
  - a close that crosses an include/macro boundary
  - a branch tag appearing more than its declared maximum

Crossing detection is depth-based. The opener records the built-in nesting
counters ({@see \Compiler::$ifDepth}, {@see \Compiler::$forStack}, and the
compile-unit stack), and the close compares them. A close that skips over a
still-open built-in block is therefore rejected. The check only reads these
counters and does not change how built-in for-else blocks are compiled.

A construct may not span a template unit boundary.  An include is inlined into
the same render body, so an opener in the host and its close in the include
would otherwise pair silently; the unit depth is snapshotted for that reason.



---

[Back to the Index ⤴](README.md)
