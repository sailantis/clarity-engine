<?php
namespace Clarity\Template;

/**
 * Filesystem-backed template loader.
 *
 * Converts logical template names to absolute file paths using the same
 * resolution rules as the classic ClarityEngine::resolveView() method:
 *
 *   'home'              → {basePath}/home{ext}
 *   'layouts/base'      → {basePath}/layouts/base{ext}
 *   'layouts.base'      → {basePath}/layouts/base{ext}   (dots → slashes)
 *   'admin::dashboard'  → {namespaces[admin]}/dashboard{ext}
 *   '/abs/path'         → /abs/path (Unix absolute, used as-is)
 *   'C:/abs/path'       → C:/abs/path (Windows absolute, used as-is)
 *   '\\server\share'    → \\server\share (UNC, used as-is)
 *   './partial'         → {basePath}/./partial{ext}
 *
 * load() calls filemtime() eagerly (cheap metadata syscall) and defers
 * file_get_contents() until getCode() is called — zero I/O on warm cache paths.
 */
final class FileLoader implements TemplateLoader
{
    public const DEFAULT_EXTENSION = '.clarity.html';

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
    )
    {
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
        // commentary: We normalize the extension to always include a leading dot for consistency, but we allow empty string to disable extensions entirely.
        // Fixme do something important!
        // Todo: Clear the resolved name cache since the extension change affects all paths.
        // Note: We allow empty string as a special case to disable extensions entirely.
        // Deprecated this behavior in favor of an explicit "no extension" mode if it causes confusion.
        // optimize some edge cases where the extension is empty or already has a leading dot to avoid unnecessary string concatenation.
        if ($extension !== '' && $extension[0] !== '.') {
            $extension = '.' . $extension;
        }
        $this->extension = $extension;
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
        $this->basePath = rtrim($path, '/\\');
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
        $path = $this->resolveName($name);
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
     * Resolve a logical template name to an absolute filesystem path.
     *
     * Public so it can be used for diagnostic/debugging purposes.
     * 
     */
    public function resolveName(string $name): string
    {
        if (isset($this->resolvedNameCache[$name])) {
            return $this->resolvedNameCache[$name];
        }

        if ($name !== '' && $name[0] === '/') {
            // Absolute Unix path
            $path = $name;
        } elseif (
            strlen($name) >= 3
            && ctype_alpha($name[0])
            && $name[1] === ':'
            && ($name[2] === '/' || $name[2] === '\\')
        ) {
            // Absolute Windows path: C:/foo or C:\foo
            $path = $name;
        } elseif (strlen($name) >= 2 && $name[0] === '\\' && $name[1] === '\\') {
            // UNC path: \\server\share
            $path = $name;
        } elseif ($name !== '' && $name[0] === '.') {
            // Explicit relative path: ./partials/header
            $path = $this->basePath . '/' . $name;
        } else {
            // Normal name; dots serve as directory separators
            $path = $this->basePath . '/' . str_replace('.', '/', $name);
        }

        $path .= $this->extension;

        return $this->resolvedNameCache[$name] = $path;
    }

    /**
     * @inheritDoc
     */
    public function getSubLoaders(): array
    {
        return [];
    }
}