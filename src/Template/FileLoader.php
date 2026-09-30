<?php
namespace Clarity\Template;

use Clarity\ClarityException;

/**
 * Filesystem-backed template loader.
 *
 * Converts logical template names to file paths UNDER the configured base path.
 * Dots and slashes are interchangeable as directory separators, and the result
 * can never leave the base path:
 *
 *   'home'               → {basePath}/home{ext}
 *   'layouts/base'       → {basePath}/layouts/base{ext}
 *   'layouts.base'       → {basePath}/layouts/base{ext}   (same thing)
 *   'admin.user.profile' → {basePath}/admin/user/profile{ext}
 *   'admin::dashboard'   → {namespaces[admin]}/dashboard{ext}
 *
 * Names are validated rather than merely sanitized, because a template name can
 * originate OUTSIDE the application: `render()` is often handed a name derived
 * from a request, and a template may be stored in a database. Two classes of
 * name are therefore rejected outright, with a ClarityException:
 *
 *   - **Absolute paths** (leading `/`, a Windows drive, or a UNC share). A
 *     template name locates a template; it is not a general-purpose file read.
 *     This was previously accepted for convenience, which made a template
 *     name — and a host that forwards user input into one — an arbitrary file
 *     reader.
 *   - **Parent references** (any `.` or `..` segment, e.g. `../secret` or
 *     `a/../../b`). A template is addressed from the base path downward.
 *     Reaching a sibling tree is what namespaces (`addNamespace()`) are for,
 *     and an explicit namespace is visible in the configuration rather than
 *     buried in a template.
 *
 * `load()` calls filemtime() eagerly (cheap metadata syscall) and defers
 * file_get_contents() until getCode() is called — zero I/O on warm cache paths.
 */
final class FileLoader implements TemplateLoader
{
    public const DEFAULT_EXTENSION = '.clarity.html';

    /**
     * Shared phrase for the message shown when a name leaves the base path.
     *
     * A constant so the wording cannot drift between the absolute-path and
     * parent-segment branches, which are the same failure from an author's
     * point of view — the name pointed somewhere the templates are not.
     */
    public const OUTSIDE_BASE_MESSAGE = 'resolves outside the view path';

    private string $basePath;

    private string $extension;

    /** @var array<string,string> logical name → resolved absolute path */
    private array $resolvedNameCache = [];

    /**
     * @param string               $basePath   Base directory for template resolution.
     * @param ?string               $extension  File extension with or without leading dot.
     */
    public function __construct(
        string $basePath,
        ?string $extension = null,
    ) {
        $this->basePath = rtrim($basePath, '/\\');
        if ($extension === null) {
            $extension = self::DEFAULT_EXTENSION;
        } elseif ($extension !== '' && $extension[0] !== '.') {
            $extension = '.' . $extension;
        }
        $this->extension = $extension;
    }

    /**
     * Set the view file extension for this instance.
     *
     * @param string $extension Extension with or without a leading dot.
     * @return $this
     */
    public function setExtension(string $extension): static
    {
        // The extension is normalized to always include a leading dot. An empty
        // string disables extension appending entirely.
        if ($extension !== '' && $extension[0] !== '.') {
            $extension = '.' . $extension;
        }
        $this->extension = $extension;
        // A changed extension changes every resolved path, so drop the cache.
        $this->resolvedNameCache = [];
        return $this;
    }

    /**
     * Get the effective file extension used when resolving templates.
     *
     * @return string Extension including leading dot or empty string.
     */
    public function getExtension(): string
    {
        return $this->extension;
    }

    /**
     * Set the base path for resolving relative template names.
     *
     * @param string $path Base directory for templates.
     * @return $this
     */
    public function setBasePath(string $path): static
    {
        $this->basePath          = rtrim($path, '/\\');
        $this->resolvedNameCache = [];
        return $this;
    }

    /**
     * Get the currently configured base path for template resolution.
     *
     * @return string Base directory for templates.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * @inheritDoc
     */
    public function load(string $name): ?TemplateSource
    {
        $path  = $this->resolveName($name);
        $mtime = @filemtime($path);

        if ($mtime === false) {
            return null;
        }

        return new TemplateSource(
            revision: $mtime,
            codeLoader: static function () use ($path, $name): string {
                $code = @file_get_contents($path);
                if ($code === false) {
                    throw new \RuntimeException("Failed to read template: {$name} ({$path})");
                }
                return $code;
            },
        );
    }

    /**
     * Resolve a logical template name to a path under the base path.
     *
     * Public so it can be used for diagnostic/debugging purposes.
     *
     * @throws ClarityException When the name is absolute or contains a `.`/`..`
     *                          segment, i.e. when it would resolve outside the
     *                          base path.
     */
    public function resolveName(string $name): string
    {
        if (isset($this->resolvedNameCache[$name])) {
            return $this->resolvedNameCache[$name];
        }

        $normalized = self::normalizeTemplateName($name);

        return $this->resolvedNameCache[$name] = $this->basePath . '/' . $normalized . $this->extension;
    }

    /**
     * Reduce a template name to a safe `dir/dir/file` path.
     *
     * Dots and backslashes are read as separators, so `admin.user`, `admin/user`
     * and `admin\\user` are the same name. The rejections below are what
     * guarantee the result cannot leave the base path — the check is on the
     * OUTCOME, not on a list of dangerous spellings, so a spelling nobody
     * anticipated is refused rather than trusted.
     *
     * Because `.` IS a separator, `..` splits into two EMPTY segments rather
     * than arriving as a `..` segment. That is why the empty-segment branch
     * names the parent-reference cause: it is the same input, and a message
     * about an "empty segment" would send an author hunting for a typo in a
     * separator they never doubled.
     *
     * @throws ClarityException When the name is absolute, empty, or contains an
     *                          empty or parent segment.
     */
    private static function normalizeTemplateName(string $name): string
    {
        // A leading `/`, a drive letter (`C:`) or a leading `\\` denotes an
        // absolute location. Note the drive check must tolerate both separators
        // (`C:\x` and `C:/x`); a bare `C:foo` is a drive-RELATIVE path, and the
        // `:` fails the allowed-character check below.
        $isAbsolute = ($name !== '' && ($name[0] === '/' || $name[0] === '\\'))
            || (\strlen($name) >= 2 && \ctype_alpha($name[0]) && $name[1] === ':');

        if ($isAbsolute) {
            throw new ClarityException(\sprintf(
                "Template name '%s' is not valid: an absolute name %s. "
                    . 'A template is addressed from the view path downward; to read another tree, '
                    . 'register it as a namespace with addNamespace().',
                $name,
                self::OUTSIDE_BASE_MESSAGE
            ));
        }

        $segments  = \explode('/', \str_replace(['.', '\\'], '/', $name));
        $hasParent = \str_contains($name, '..');

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new ClarityException($hasParent
                    ? \sprintf(
                        "Template name '%s' is not valid: a '..' segment %s by addressing "
                            . 'a parent directory. To read another tree, register it as a namespace '
                            . 'with addNamespace().',
                        $name,
                        self::OUTSIDE_BASE_MESSAGE
                    )
                    : \sprintf(
                        "Template name '%s' is not valid: it has an empty path segment "
                            . '(check for a leading or doubled separator).',
                        $name
                    ));
            }

            if ($segment === '.') {
                throw new ClarityException(\sprintf(
                    "Template name '%s' is not valid: '.' is a path separator, so it cannot be a segment.",
                    $name
                ));
            }

            if (\preg_match('/[\x00-\x1F]/', $segment) === 1) {
                throw new ClarityException(\sprintf(
                    "Template name '%s' contains a control character.",
                    $name
                ));
            }
        }

        return \implode('/', $segments);
    }

    /**
     * @inheritDoc
     */
    public function getSubLoaders(): array
    {
        return [];
    }
}
