<?php
namespace Clarity\Template;

/**
 * TemplateLoader that dispatches to different loaders based on a domain prefix in the template name.
 *
 * The template name is expected to be in the format "domain::localName".  The loader looks up the
 * domain in its map and forwards the load request to the corresponding loader with the localName.
 *
 * If no "::" is present, the entire name is treated as localName and passed to a fallback loader if configured.
 *
 * ```php
 * $loader = new DomainRouterLoader([
 *     'app' => new FilesystemLoader('/path/to/app/templates'),
 *     'lib' => new FilesystemLoader('/path/to/lib/templates'),
 * ], fallback: new FilesystemLoader('/path/to/default/templates'));
 * $engine->setLoader($loader);
 *
 * // Resolves to /path/to/app/templates/home.html
 * echo $engine->render('app::home');
 *
 * // Resolves to /path/to/lib/templates/widget.html
 * echo $engine->render('lib::widget');
 *
 * // Resolves to /path/to/default/templates/other.html
 * echo $engine->render('other');
 * ```
 */
final class DomainRouterLoader implements TemplateLoader
{
    /** @var array<string, TemplateLoader> */
    private array $domainLoaders;

    private ?TemplateLoader $fallback;

    public function __construct(array $domainLoaders, ?TemplateLoader $fallback = null)
    {
        $this->domainLoaders = $domainLoaders;
        $this->fallback = $fallback;
    }

    /**
     * Add or replace a domain loader at runtime.
     *
     * @param string $domain Domain prefix to route (e.g. "app").
     * @param TemplateLoader $loader Loader to handle templates for this domain.
     */
    public function addDomainLoader(string $domain, TemplateLoader $loader): void
    {
        $this->domainLoaders[$domain] = $loader;
    }

    /**
     * Set or replace the fallback loader for templates without a domain prefix.
     *
     * @param TemplateLoader|null $loader Loader to handle templates without a domain, or null to disable.
     */
    public function setFallbackLoader(?TemplateLoader $loader): void
    {
        $this->fallback = $loader;
    }

    /**
     * Get the currently configured domain loaders.
     *
     * @return array<string, TemplateLoader> Associative array of domain => loader mappings.
     */
    public function getDomainLoaders(): array
    {
        return $this->domainLoaders;
    }

    /**
     * Get the currently configured fallback loader.
     *
     * @return TemplateLoader|null The fallback loader, or null if none is set.
     */
    public function getFallbackLoader(): ?TemplateLoader
    {
        return $this->fallback;
    }

    /**
     * @inheritDoc
     */
    public function load(string $name): ?TemplateSource
    {
        $domain = \strstr($name, '::', true);

        if ($domain !== false) {
            $loader = $this->domainLoaders[$domain] ?? null;
            if ($loader === null) {
                throw new \RuntimeException("Unknown template domain '$domain'");
            }
            $localName = \substr($name, \strlen($domain) + 2);
        } else {
            $loader = $this->fallback;
            $localName = $name;
        }

        return $loader?->load($localName);
    }

    /**
     * @inheritDoc
     */
    public function getSubLoaders(): array
    {
        $loaders = \array_values($this->domainLoaders);
        if ($this->fallback !== null) {
            $loaders[] = $this->fallback;
        }
        return $loaders;
    }

}
