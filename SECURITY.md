# Security Policy

## Supported versions

The latest release on the `main` branch receives security fixes.

## Reporting a vulnerability

Please **do not** open a public issue for a security problem. Instead, report it
privately via GitHub's
[security advisories](https://github.com/sailantis/clarity-engine/security/advisories/new),
or email the maintainers.

Include:

- a description of the issue,
- a minimal template that reproduces it,
- the Clarity version and PHP version, and
- any output that shows the impact.

We will acknowledge the report and keep you informed while we work on a fix.

## The sandbox model

Clarity is **sandboxed by default**: templates cannot call arbitrary PHP, reach
superglobals, or invoke methods on objects. Two deliberate escape hatches exist,
and neither is a bug:

- **PHP mode** (`$engine->setSandboxMode(false)`) grants templates the full power
  of PHP — arbitrary function calls, method calls, and `{% php %}` blocks. It is
  **equivalent to executing arbitrary PHP** and must only be enabled for
  templates written by trusted authors.
- **Custom filters and functions** (`addFilter()`, `addFunction()`) run whatever
  the host application registers. Validate and escape their input there.

If you believe you can escape the sandbox **while it is enabled**, that is a
vulnerability and we want to hear about it.

### Changing the sandbox mode requires a cache flush

Compiled templates record the mode they were compiled under and are recompiled
automatically when it changes. Changing the `deniedFunctions` list, however,
does **not** invalidate already-compiled templates, because the emitted code
embeds the function names. Call `flushCache()` after changing it.
