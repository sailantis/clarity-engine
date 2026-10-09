<?php
namespace Clarity\Template;

use Clarity\ClarityException;

/**
 * Filesystem-backed template loader.
 *
 * Converts logical template names to file paths under the configured base path.
 * Dots, slashes, and backslashes are interchangeable as directory separators. A name
 * cannot resolve outside the base path by its spelling (symbolic links inside the base
 * are not checked):
 *
 *   'home'               → {basePath}/home{ext}
 *   'layouts/base'       → {basePath}/layouts/base{ext}
 *   'layouts.base'       → {basePath}/layouts/base{ext}   (same thing)
 *   'admin.user.profile' → {basePath}/admin/user/profile{ext}
 *
 * Names are validated, not merely sanitized, because a name can come from outside
 * the application (for example a request parameter). Names that would resolve outside
 * the base path are rejected with a ClarityException, in two forms:
 *
 *   - **Absolute paths** (leading `/`, a Windows drive, or a UNC share). A template
 *     name locates a template; it is not a file read.
 *   - **Parent references** (any name containing `..`, e.g. `../secret` or `a/../../b`).
 *     Templates are addressed downward from the base path. To read another tree,
 *     register it as a namespace with `addNamespace()`.
 *
 * Empty segments (such as `a//b`) and control characters are also rejected.
 *
 * `load()` calls filemtime() eagerly, a cheap metadata lookup, and defers
 * file_get_contents() until getCode(). When the compiled cache is fresh, getCode()
 * is never called, so no template content is read.
 */

final class FileLoader implements TemplateLoader
{
    public const DEFAULT_EXTENSION = '.clarity.html';

    /**
     * Shared message fragment for names that would resolve outside the base path,
     * used by both the absolute-path and parent-reference errors.
     */
    public const OUTSIDE_BASE_MESSAGE = 'resolves outside the view path';

    private string $basePath;

    private string $extension;

    /** @var array<string,string> logical name → resolved path */
    private array $resolvedNameCache = [];

    /**
     * @param string  $basePath  Base directory for template resolution.
     * @param ?string $extension File extension with or without leading dot.
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
     * @param string $extension Extension with or without a leading dot. An empty
     *                          string disables extension appending.
     * @return $this
     */
    public function setExtension(string $extension): static
    {
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
            path: $path,
        );
    }

    /**
     * Resolve a logical template name to a path under the base path.
     *
     * Public for diagnostic use.
     *
     * @throws ClarityException When the name is absolute, has an empty segment (including
     *                          one produced by a `..` reference), or contains a control character.
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
     * Dots, slashes, and backslashes separate segments, so `admin.user`, `admin/user`
     * and `admin\user` are equivalent. Each segment is checked, so unexpected forms are
     * rejected rather than passed through.
     *
     * A `..` produces empty segments and fails the empty-segment check. The error
     * message names the parent reference in that case.
     *
     * @throws ClarityException When the name is absolute, has an empty segment, or contains a control character.
     */
    private static function normalizeTemplateName(string $name): string
    {
        // A leading `/` or `\`, or a drive prefix (`C:`), denotes an absolute
        // location. The drive prefix is refused even without a following separator,
        // so `C:foo` is rejected too.
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

        $segments  = \explode('/', \strtr($name, './\\', '///'));
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
