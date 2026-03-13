<?php
namespace Clarity\Template;

/**
 * Single-template loader that wraps one hardcoded template string.
 *
 * Useful for rendering one dynamically-built or user-supplied template
 * without touching the filesystem.
 *
 * ```php
 * $loader = new StringLoader('dynamic', '<p>{{ message }}</p>');
 * $engine->setLoader($loader);
 * echo $engine->render('dynamic', ['message' => 'Hello!']);
 * ```
 */
final class StringLoader implements TemplateLoader
{
    private string $code;
    private string $revision;

    /**
     * @param string $name Logical template name used to reference this template.
     * @param string $code Raw template source.
     */
    public function __construct(
        private readonly string $name,
        string $code,
    ) {
        $this->code = $code;
        $this->revision = hash('fnv1a64', $code);
    }

    /**
     * @inheritDoc
     */
    public function load(string $name): ?TemplateSource
    {
        if ($name !== $this->name) {
            return null;
        }
        $code = $this->code;
        return new TemplateSource(
            revision: $this->revision,
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
     * Replace the template source.
     *
     * The revision changes automatically so the next render triggers recompilation.
     */
    public function update(string $code): static
    {
        $this->code = $code;
        $this->revision = hash('fnv1a64', $code);
        return $this;
    }
}
