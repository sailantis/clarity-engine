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

What a template may reach is decided by a **policy**: a set of rules plus
two allowlists, resolved entirely at compile time. The default,
`Policy::restricted()`, lets a template reach nothing but its own scope and the
filters and functions the host registered.

Rules that expose PHP features are explicit trust decisions:

- **`Policy::unrestricted()`** grants every one of them, which is equivalent to
  executing arbitrary PHP. It must only be enabled for templates written by
  trusted authors.
- **Individual rules differ in scope.** `rawPhp` allows template-authored
  PHP; `methodCalls` allows calls on objects passed by the host. Grant only what
  trusted templates need.
- **Custom filters and functions** (`addFilter()`, `addFunction()`) run whatever
  the host application registers. Validate and escape their input there.
- **Allowlists narrow PHP-function fallbacks; they do not enable PHP access.**
  Registered filters and functions remain governed by registration.

If a template can escape the default policy without a relevant rule, that
is a vulnerability. Please report it privately.

### Changing a policy recompiles what it affects

Every compiled template records a **digest** of its policy. The loader recompiles
when that policy changes, including when an allowlist entry is added or removed,
so it cannot reuse a compiled class under a different policy.
