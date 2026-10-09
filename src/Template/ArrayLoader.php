<?php
namespace Clarity\Template;

/**
 * In-memory template loader backed by a plain PHP array.
 *
 * Suited to unit tests, generated templates, and small applications that define
 * all templates in code rather than on the filesystem.
 *
 * The cache revision is the fnv1a64 hash of the source, computed via
 * hash('fnv1a64', $code). The loader performs no file I/O.
 *
 * ```php
 * $loader = new ArrayLoader([
 *     'home'         => '<h1>Hello {{ name }}</h1>',
 *     'layouts.base' => '<!DOCTYPE html><body>{% block content %}{% endblock %}</body>',
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

    /**
     * @inheritDoc
     */
    public function load(string $name): ?TemplateSource
    {
        if (!isset($this->templates[$name])) {
            return null;
        }
        $code = $this->templates[$name];
        return new TemplateSource(
            revision: hash('fnv1a64', $code),
            codeLoader: static fn(): string => $code,
        );
    }

    /**
     * @inheritDoc
     */
    public function getSubLoaders(): array
    {
        return [];
    }

    /**
     * Add or replace a template definition.
     *
     * The revision is computed from the source on each load, so the next render
     * recompiles the template if its source has changed.
     */
    public function set(string $name, string $code): static
    {
        $this->templates[$name] = $code;
        return $this;
    }
}
