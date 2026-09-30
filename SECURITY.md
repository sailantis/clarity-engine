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

## The policy model

What a template may reach is decided by a **policy**: a set of capabilities plus
two allowlists, resolved entirely at compile time. The default,
`Policy::sandboxed()`, lets a template reach nothing but its own scope and the
filters and functions the host registered.

The grants that reach PHP — `rawPhp`, `phpVariables`, `methodCalls`,
`newExpressions`, `staticCalls`, `superglobals` — are deliberate escape hatches,
and none of them is a bug:

- **`Policy::open()`** grants every one of them, which is equivalent to
  executing arbitrary PHP. It must only be enabled for templates written by
  trusted authors.
- **A single capability** is the same kind of decision at a smaller scale.
  `methodCalls` lets a template call a method on an object the host passed in;
  `newExpressions` lets it construct anything it can name. Treat each grant as a
  security decision, not a convenience.
- **Custom filters and functions** (`addFilter()`, `addFunction()`) run whatever
  the host application registers. Validate and escape their input there.
- **A non-empty `functions`/`filters` allowlist** is a grant of exactly the names
  it lists, and nothing else.

If you believe you can escape the default policy **without** granting a
capability, that is a vulnerability and we want to hear about it.

### Changing a policy recompiles what it affects

Every compiled template records a **digest** of the policy it was built under,
and the loader recompiles when the digest differs from the current policy —
including when a single allowlist entry is added or removed. A compiled class can
therefore never be served under a policy other than the one it was built with.
