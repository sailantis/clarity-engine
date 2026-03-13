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
        if (empty($domainLoaders)) {
            throw new \InvalidArgumentException('At least one domain loader must be provided');
        }
        $this->domainLoaders = $domainLoaders;
        $this->fallback = $fallback;
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
