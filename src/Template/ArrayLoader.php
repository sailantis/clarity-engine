<?php
namespace Clarity\Template;

/**
 * In-memory template loader backed by a plain PHP array.
 *
 * Ideal for unit testing, dynamic/generated templates, and small applications
 * that keep all templates in code rather than on the filesystem.
 *
 * Cache revision is derived from the source string via hash('fnv1a64', $code).
 * No file I/O takes place at any point.
 *
 * ```php
 * $loader = new ArrayLoader([
 *     'home'         => '<h1>Hello {{ name }}</h1>',
 *     'layouts/base' => '<!DOCTYPE html><body>{% block content %}{% endblock %}</body>',
 * ]);
 * $engine->setLoader($loader);
 * ```
 */
final class ArrayLoader implements TemplateLoader
{
    /** @var array<string, string> logical name → raw template source */
    private array $templates;

    /**
     * @param array<string, string> $templates Map of logical name → raw template source.
     */
    public function __construct(array $templates = [])
    {
        $this->templates = $templates;
    }

    public function exists(string $name): bool
    {
        return isset($this->templates[$name]);
    }

    public function load(string $name): TemplateSource
    {
        if (!isset($this->templates[$name])) {
            throw new \RuntimeException("Template not found: {$name}");
        }
        $code = $this->templates[$name];
        return new TemplateSource(
            revision: hash('fnv1a64', $code),
            codeLoader: static fn(): string => $code,
        );
    }

    /**
     * Add or replace a template definition.
     *
     * The cache for the template will be invalidated on the next render because
     * the fnv1a64 revision of the new code will differ from the stored revision.
     */
    public function set(string $name, string $code): static
    {
        $this->templates[$name] = $code;
        return $this;
    }
}
