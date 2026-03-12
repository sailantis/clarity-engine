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
    private string $basePath;
    private string $extension;
    /** @var array<string,string> namespace → base directory */
    private array $namespaces;
    /** @var array<string,string> logical name → resolved absolute path */
    private array $resolvedNameCache = [];

    /**
     * @param string               $basePath   Base directory for template resolution.
     * @param string               $extension  File extension with or without leading dot.
     * @param array<string,string> $namespaces Namespace alias → base path map.
     */
    public function __construct(
        string $basePath,
        string $extension = '.clarity.html',
        array $namespaces = [],
    ) {
        $this->basePath = rtrim($basePath, '/\\');
        $this->extension = ($extension !== '' && $extension[0] !== '.') ? '.' . $extension : $extension;
        $this->namespaces = $namespaces;
    }

    /**
     * Set the view file extension for this instance.
     *
     * @param string $ext Extension with or without a leading dot.
     * @return $this
     */
    public function setExtension(string $ext): static
    {
        if ($ext !== '' && $ext[0] !== '.') {
            $ext = '.' . $ext;
        }
        $this->extension = $ext;
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
     * Add a namespace for view resolution.
     *
     * Views can be referenced using the syntax "namespace::view.name".
     *
     * @param string $name Namespace name to register.
     * @param string $path Filesystem path corresponding to the namespace.
     * @return $this
     */
    public function addNamespace(string $name, string $path): static
    {
        $this->namespaces[$name] = \rtrim($path, '/\\');
        $this->resolvedNameCache = [];
        return $this;
    }

    /**
     * Get the currently registered view namespaces.
     *
     * @return array Associative array of namespace => path mappings.
     */
    public function getNamespaces(): array
    {
        return $this->namespaces;
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

    public function exists(string $name): bool
    {
        return is_file($this->resolveName($name));
    }

    public function load(string $name): TemplateSource
    {
        $path = $this->resolveName($name);
        $mtime = @filemtime($path);

        if ($mtime === false) {
            throw new \RuntimeException("Template not found: {$name} (resolved to: {$path})");
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
     */
    public function resolveName(string $name): string
    {
        if (isset($this->resolvedNameCache[$name])) {
            return $this->resolvedNameCache[$name];
        }

        $addExtension = $this->extension !== '' && !str_ends_with($name, $this->extension);

        $ns = strstr($name, '::', true);
        if ($ns !== false) {
            if (!isset($this->namespaces[$ns])) {
                throw new \RuntimeException("Unknown view namespace: {$ns}");
            }
            $part = substr($name, strlen($ns) + 2);
            $path = rtrim($this->namespaces[$ns], '/\\') . '/' . str_replace('.', '/', $part);
        } elseif ($name !== '' && $name[0] === '/') {
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

        if ($addExtension) {
            $path .= $this->extension;
        }

        return $this->resolvedNameCache[$name] = $path;
    }
}
