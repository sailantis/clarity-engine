# Clarity Template Engine - Documentation Guide

This directory contains detailed guides for using Clarity, a fast, secure template engine for PHP.

## Documentation Overview

### For Beginners

Start here to get up and running:

1. **[Getting Started](00-getting-started.md)** — Installation, setup, and your first template
2. **[Template Syntax](01-template-syntax.md)** — Variables, directives, expressions, and operators

### Core Features

Learn the essential features:

3. **[Filters and Functions](02-filters-and-functions.md)** — Transform data with filters and use built-in functions
4. **[Layout Inheritance](03-layout-inheritance.md)** — Create reusable layouts with extends and blocks

### Advanced Topics

Deep dives into specialized features:

5. **[Advanced Topics](04-advanced-topics.md)** — Namespaces, caching, auto-escaping, error handling, Unicode
6. **[The Policy API](09-policy-api.md)** — What a template may reach: capabilities, allowlists, presets
7. **[Modules](07-modules.md)** — Module system, LocaleService, TranslationModule, IntlFormatModule
8. **[Best Practices](05-best-practices.md)** — Organization, naming, security, testing, and debugging
9. **[Troubleshooting](06-troubleshooting.md)** — Common errors and how to fix them
10. **[Benchmark](08-benchmark.md)** — How Clarity compares with other template engines, across rendering shapes

## Additional Resources

- **[Main README](../README.md)** — Quick reference and overview
- **[API Documentation](api/README.md)** — Auto-generated API reference
- **[Examples](examples/README.md)** — Runnable template examples
- **[Changelog](../CHANGELOG.md)** — Release history
- **[Contributing](../CONTRIBUTING.md)** — Development workflow
- **[Security Policy](../SECURITY.md)** — Reporting vulnerabilities

## Suggested Reading Order

**For Template Authors:**

1. Getting Started → Template Syntax → Filters → Layout Inheritance
2. Best Practices (security, organization)
3. Browse Examples for patterns

**For PHP Developers Integrating Clarity:**

1. Getting Started (setup & configuration)
2. Template Syntax → PHP Mode (modes, raw PHP, how the scope reaches templates)
3. Filters and Functions (registration, PHP functions as filters)
4. Advanced Topics (caching, namespaces, error handling)
5. API Documentation for method references

## Contributing

Found an error or have suggestions? Please open an issue or pull request on the main repository.
