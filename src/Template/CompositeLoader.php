<?php
namespace Clarity\Template;

/**
 * TemplateLoader that tries multiple loaders in sequence until one returns a result.
 *
 * Suited to layering several template sources, e.g. an ArrayLoader for dynamic templates
 * on top of a FileLoader for static templates.
 *
 * ```php
 * $loader = new CompositeLoader(
 *     new ArrayLoader(['dynamic' => '<p>{{ message }}</p>']),
 *     new FileLoader('/path/to/static/templates'),
 * );
 * $engine->setLoader($loader);
 *
 * // Resolves to the ArrayLoader template
 * echo $engine->render('dynamic', ['message' => 'Hello!']);
 *
 * // Resolves to /path/to/static/templates/home.clarity.html
 * echo $engine->render('home');
 * ```
 */
class CompositeLoader implements TemplateLoader
{
    /** @var TemplateLoader[] */
    private array $loaders;

    public function __construct(TemplateLoader ...$loaders)
    {
        $this->loaders = $loaders;
    }

    /**
     * @inheritDoc
     */
    public function load(string $name): ?TemplateSource
    {
        foreach ($this->loaders as $loader) {
            $source = $loader->load($name);
            if ($source !== null) {
                return $source;
            }
        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getSubLoaders(): array
    {
        return $this->loaders;
    }

}
