#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';

//use phpDocumentor\Reflection\DocBlock\Tags\Deprecated as DeprecatedTag;
use phpDocumentor\Reflection\DocBlock\Tags\Param as ParamTag;
use phpDocumentor\Reflection\DocBlock\Tags\Return_ as ReturnTag;
use phpDocumentor\Reflection\DocBlock\Tags\Throws as ThrowsTag;
use phpDocumentor\Reflection\DocBlock\Tags\Example as ExampleTag;
use phpDocumentor\Reflection\DocBlockFactory;

$srcDir = 'src';
$docsDir = 'docs/api';
$projectRoot = dirname(__DIR__);

echo "🔍 Scanning $srcDir...\n";

$docFactory = DocBlockFactory::createInstance();

// Find all classes and interfaces
$allClasses = [];
$namespacedClasses = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        if (preg_match_all('/(?:class|interface)\s+([A-Za-z0-9_]+)/', $content, $matches)) {
            $namespace = '';
            if (preg_match('/namespace\s+([^\s;{]+)/', $content, $ns)) {
                $namespace = trim($ns[1]) . '\\';
            }
            foreach ($matches[1] as $className) {
                $fqcn = $namespace . $className;
                // include both classes and interfaces
                if (class_exists($fqcn) || interface_exists($fqcn)) {
                    $ns = substr($namespace, 0, -1);
                    $allClasses[$fqcn] = [
                        'short' => $className,
                        'namespace' => $ns,
                    ];
                    $namespacedClasses[$ns][] = $fqcn;
                }
            }
        }
    }
}

echo "📝 Generating docs for " . count($allClasses) . " types (classes & interfaces)...\n";

// Build class registry: maps FQCN and short name -> metadata for link generation.
// All src links are relative from docs/api/ (two levels up to project root).
$classRegistry = [];
$shortNameCounts = [];
foreach ($allClasses as $classMeta) {
    $shortName = $classMeta['short'];
    $shortNameCounts[$shortName] = ($shortNameCounts[$shortName] ?? 0) + 1;
}

foreach ($allClasses as $fqcn => $classMeta) {
    $shortName = $classMeta['short'];
    $ref = new ReflectionClass($fqcn);
    $absFile = $ref->getFileName();
    $relFromRoot = str_replace('\\', '/', substr($absFile, strlen($projectRoot) + 1));
    $srcLink = '../../' . $relFromRoot;
    $docFile = makeDocFileName($fqcn);
    $classRegistry[$fqcn] = [
        'short' => $shortName,
        'srcLink' => $srcLink . '#L' . $ref->getStartLine(),
        'srcFile' => $srcLink,
        'docLink' => $docFile,
    ];
    // Also index by short name only if unique
    if (($shortNameCounts[$shortName] ?? 0) === 1) {
        $classRegistry[$shortName] = &$classRegistry[$fqcn];
    }
}

// Generate index
if (!is_dir($docsDir)) {
    mkdir($docsDir, 0755, true);
}
foreach (glob($docsDir . '/*.md') as $oldDocFile) {
    @unlink($oldDocFile);
}
$indexContent = "# Merlin MVC API\n\n## Classes & Interfaces overview\n\n";
$sep = '';
foreach ($namespacedClasses as $namespace => $classes) {
    $indexContent .= $sep;
    $sep = "\n";
    $indexContent .= "### `{$namespace}`\n\n";
    foreach ($classes as $fqcn) {
        $class = $allClasses[$fqcn]['short'];
        $indexContent .= "- [{$class}](" . makeDocFileName($fqcn) . ") `{$fqcn}`\n";
    }
}
file_put_contents("$docsDir/README.md", $indexContent . "\n");

// Individual class docs
foreach ($allClasses as $class => $classMeta) {
    $reflector = new ReflectionClass($class);
    $md = generateClassDoc($reflector, $docFactory, $classRegistry);
    $md .= "\n\n---\n\n[Back to the Index ⤴](README.md)\n";
    $filename = makeDocFileName($class);
    file_put_contents("$docsDir/$filename", $md);
    echo "  ✓ {$filename}\n";
}

echo "✅ API docs ready: $docsDir/\n";

/* ---------------- Functions ---------------- */

function generateClassDoc(ReflectionClass $reflector, $docFactory, array $classRegistry): string
{
    $shortName = $reflector->getShortName();
    $fqcn = $reflector->getName();

    $srcInfo = $classRegistry[$fqcn] ?? null;
    $classLink = $srcInfo ? "[{$fqcn}]({$srcInfo['srcFile']})" : "`{$fqcn}`";
    $typeLabel = $reflector->isInterface() ? '🔌 Interface' : '🧩 Class';
    $md = "# {$typeLabel}: {$shortName}\n\n";
    $md .= "**Full name:** {$classLink}\n\n";

    // Class DocComment
    $docData = resolveEffectiveClassDoc($reflector, $docFactory);
    if ($docData !== null) {
        $summary = trim($docData['summary']);
        $desc = trim($docData['description']);
        if ($summary)
            $md .= resolveInlineTags($summary, $classRegistry) . "\n\n";
        if ($desc)
            $md .= resolveInlineTags($desc, $classRegistry) . "\n\n";
        if (hasResolvedTag($docData, 'deprecated')) {
            $tag = current(getResolvedTags($docData, 'deprecated'));
            $md .= "**🛑 Deprecated**: " . safeTagToString($tag) . "\n\n";
        }
        if (hasResolvedTag($docData, 'example')) {
            $md .= renderExampleTags(getResolvedTags($docData, 'example'));
        }
    }

    // Constants (hide protected)
    $constants = array_filter(
        $reflector->getReflectionConstants(),
        fn(ReflectionClassConstant $constant) => $constant->isPublic()
    );
    if (!empty($constants)) {
        $md .= "## 📌 Public Constants\n\n";
        foreach ($constants as $constant) {
            $name = $constant->getName();
            $value = $constant->getValue();
            $md .= "- **{$name}** = `" . short_var_export($value) . "`\n";
        }
        $md .= "\n";
    }

    // Properties (hide protected)
    $props = array_filter(
        $reflector->getProperties(),
        fn(ReflectionProperty $prop) => $prop->isPublic()
    );
    if (!empty($props)) {
        $md .= "## 🔐 Public Properties\n\n";
        foreach ($props as $prop) {
            $vis = getVisibility($prop);
            $static = $prop->isStatic() ? ' static' : '';
            $readonly = (method_exists($prop, 'isReadOnly') && $prop->isReadOnly()) ? ' readonly' : '';
            $typeStr = formatReflectionType($prop->getType());
            $linkedType = linkType($typeStr, $classRegistry, 'doc');
            $propSrcLink = ($srcInfo && method_exists($prop, 'getStartLine'))
                ? ($srcInfo['srcFile'] . '#L' . $prop->getStartLine())
                : ($srcInfo ? $srcInfo['srcFile'] : null);
            $srcRef = $propSrcLink ? " · [source]($propSrcLink)" : '';
            $md .= "- `{$vis}{$static}{$readonly}` {$linkedType} `\${$prop->getName()}`{$srcRef}\n";
        }
        $md .= "\n";
    }

    // Public methods
    $md .= "## 🚀 Public methods\n\n";
    $sep = "";
    foreach ($reflector->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->class !== $reflector->name)
            continue;
        $md .= $sep;
        $sep = "\n---\n\n";
        $md .= generateMethodDoc($method, $docFactory, $classRegistry);
    }

    return $md;
}

function generateMethodDoc(ReflectionMethod $method, $docFactory, array $classRegistry): string
{
    $classSrcInfo = $classRegistry[$method->class] ?? null;
    $methodSrcLink = $classSrcInfo ? ($classSrcInfo['srcFile'] . '#L' . $method->getStartLine()) : null;
    $srcBadge = $methodSrcLink ? " · [source]({$methodSrcLink})" : '';
    $md = "### {$method->getName()}(){$srcBadge}\n\n";

    // Build linked signature
    $vis = getVisibility($method);
    $static = $method->isStatic() ? ' static' : '';
    $linkedParams = [];
    foreach ($method->getParameters() as $p) {
        $linkedParams[] = formatParameter($p);
    }
    $returnTypeStr = formatReflectionType($method->getReturnType());
    $linkedReturn = linkType($returnTypeStr, $classRegistry, 'doc', true);

    // Signature: wrap keywords/name in backticks; types rendered as inline links
    $md .= "`{$vis}{$static} function {$method->getName()}(";
    $md .= implode(', ', $linkedParams);
    $md .= "): $returnTypeStr`\n\n";

    // DocBlock
    $docData = resolveEffectiveMethodDoc($method, $docFactory);
    if ($docData !== null) {
        $summary = trim($docData['summary']);
        $desc = trim($docData['description']);
        if ($summary)
            $md .= resolveInlineTags($summary, $classRegistry) . "\n\n";
        if ($desc)
            $md .= resolveInlineTags($desc, $classRegistry) . "\n\n";
        if (hasResolvedTag($docData, 'deprecated')) {
            $tag = current(getResolvedTags($docData, 'deprecated'));
            $md .= "**🛑 Deprecated**: " . safeTagToString($tag) . "\n\n";
        }
    }

    // Parameters table
    if ($method->getNumberOfParameters() > 0) {
        $md .= "**🧭 Parameters**\n\n";
        $md .= "| Name | Type | Default | Description |\n";
        $md .= "|---|---|---|---|\n";
        $paramTags = $docData !== null ? getResolvedTags($docData, 'param') : [];
        $paramTagMap = mapParamTags($paramTags);
        foreach ($method->getParameters() as $p) {
            $name = '$' . $p->getName();
            $typeStr = formatReflectionType($p->getType());
            // Escape union | separators for table cells, without touching link syntax
            $linkedTypeForTable = escapeTablePipes(linkType($typeStr, $classRegistry, 'doc'));
            $default = $p->isDefaultValueAvailable() ? formatDefaultValue($p->getDefaultValue()) : null;
            $desc = $paramTagMap[$p->getName()] ?? $paramTagMap[$p->getPosition()] ?? '';
            $desc = $desc ? str_replace("\n", "<br>", resolveInlineTags(trim($desc), $classRegistry)) : '';
            if (isset($default)) {
                $default = "`{$default}`";
            } else {
                $default = '-';
            }
            $md .= "| `{$name}` | {$linkedTypeForTable} | {$default} | {$desc} |\n";
        }
        $md .= "\n";
    }

    // Return value
    $returnDesc = '';
    if ($docData !== null && hasResolvedTag($docData, 'return')) {
        $ret = current(getResolvedTags($docData, 'return'));
        if ($ret instanceof ReturnTag) {
            $returnDesc = (string) $ret->getDescription();
        } else {
            $returnDesc = safeTagToString($ret);
        }
    }
    $md .= "**➡️ Return value**\n\n";
    $md .= "- Type: " . $linkedReturn . "\n";
    if ($returnDesc) {
        $md .= "- Description: " . str_replace("\n", "<br>", resolveInlineTags($returnDesc, $classRegistry)) . "\n";
    }
    $md .= "\n";

    // Throws
    if ($docData !== null && hasResolvedTag($docData, 'throws')) {
        $md .= "**⚠️ Throws**\n\n";
        foreach (getResolvedTags($docData, 'throws') as $t) {
            if ($t instanceof ThrowsTag) {
                $exTypeStr = ltrim(trim((string) $t->getType()), '\\');
                $exDesc = trim((string) $t->getDescription());
                $linkedExType = linkType($exTypeStr, $classRegistry, 'doc');
                $md .= "- " . $linkedExType . ($exDesc ? "  " . resolveInlineTags($exDesc, $classRegistry) : "") . "\n";
            } else {
                $md .= "- " . safeTagToString($t) . "\n";
            }
        }
        $md .= "\n";
    }

    // Example
    if ($docData !== null && hasResolvedTag($docData, 'example')) {
        $md .= renderExampleTags(getResolvedTags($docData, 'example'));
    }

    return $md;
}

/* ---------------- Helpers ---------------- */

function resolveEffectiveClassDoc(ReflectionClass $reflector, DocBlockFactory $docFactory, array &$seen = []): ?array
{
    $key = $reflector->getName();
    if (isset($seen[$key])) {
        return parseDocData($reflector->getDocComment() ?: '', $docFactory);
    }

    $seen[$key] = true;

    $local = parseDocData($reflector->getDocComment() ?: '', $docFactory);
    if ($local === null || !docDataHasInheritdoc($local)) {
        unset($seen[$key]);
        return $local;
    }

    $inherited = null;
    $parent = $reflector->getParentClass();
    if ($parent !== false) {
        $inherited = resolveEffectiveClassDoc($parent, $docFactory, $seen);
    }

    if ($inherited === null) {
        foreach ($reflector->getInterfaces() as $interface) {
            $inherited = resolveEffectiveClassDoc($interface, $docFactory, $seen);
            if ($inherited !== null) {
                break;
            }
        }
    }

    unset($seen[$key]);
    return mergeResolvedDocData($local, $inherited);
}

function resolveEffectiveMethodDoc(ReflectionMethod $method, DocBlockFactory $docFactory, array &$seen = []): ?array
{
    $key = $method->getDeclaringClass()->getName() . '::' . $method->getName();
    if (isset($seen[$key])) {
        return parseDocData($method->getDocComment() ?: '', $docFactory);
    }

    $seen[$key] = true;

    $local = parseDocData($method->getDocComment() ?: '', $docFactory);
    if ($local === null || !docDataHasInheritdoc($local)) {
        unset($seen[$key]);
        return $local;
    }

    $prototype = findInheritedMethodPrototype($method);
    $inherited = $prototype ? resolveEffectiveMethodDoc($prototype, $docFactory, $seen) : null;

    unset($seen[$key]);
    return mergeResolvedDocData($local, $inherited, $method);
}

function parseDocData(string $doc, DocBlockFactory $docFactory): ?array
{
    $doc = trim($doc);
    if ($doc === '') {
        return null;
    }

    try {
        $block = $docFactory->create($doc);
        $tagsByName = [];
        foreach ($block->getTags() as $tag) {
            $tagsByName[strtolower($tag->getName())][] = $tag;
        }

        return [
            'raw' => $doc,
            'summary' => trim((string) $block->getSummary()),
            'description' => trim((string) $block->getDescription()),
            'tagsByName' => $tagsByName,
        ];
    } catch (Throwable $e) {
        return [
            'raw' => $doc,
            'summary' => '',
            'description' => trim(cleanDocBlock($doc)),
            'tagsByName' => [],
        ];
    }
}

function docDataHasInheritdoc(array $docData): bool
{
    return preg_match('/\{@inheritdoc\}|@inheritdoc\b/i', $docData['raw']) === 1;
}

function mergeResolvedDocData(?array $local, ?array $inherited, ?ReflectionMethod $method = null): ?array
{
    if ($local === null) {
        return null;
    }

    $merged = $local;
    $merged['summary'] = mergeInheritedText($local['summary'], $inherited['summary'] ?? '');
    $merged['description'] = mergeInheritedText($local['description'], $inherited['description'] ?? '');

    if (isPureInheritdocDoc($local)) {
        $merged['summary'] = $inherited['summary'] ?? '';
        $merged['description'] = $inherited['description'] ?? '';
    }

    $merged['tagsByName'] = mergeResolvedTags(
        $local['tagsByName'] ?? [],
        $inherited['tagsByName'] ?? [],
        $method
    );

    return $merged;
}

function mergeInheritedText(string $local, string $inherited): string
{
    if ($local === '') {
        return '';
    }

    $merged = preg_replace('/\{@inheritdoc\}|@inheritdoc\b/i', $inherited, $local);
    return trim(preg_replace('/\n{3,}/', "\n\n", $merged ?? $local));
}

function isPureInheritdocDoc(array $docData): bool
{
    $body = trim(cleanDocBlock($docData['raw'] ?? ''));
    if ($body === '') {
        return false;
    }

    $stripped = trim((string) preg_replace('/\{@inheritdoc\}|@inheritdoc\b/i', '', $body));
    return $stripped === '';
}

function mergeResolvedTags(array $localTagsByName, array $inheritedTagsByName, ?ReflectionMethod $method = null): array
{
    $merged = $inheritedTagsByName;

    foreach ($localTagsByName as $name => $tags) {
        if ($name === 'param') {
            $merged[$name] = $method ? mergeParamTagLists($tags, $inheritedTagsByName[$name] ?? [], $method) : $tags;
            continue;
        }

        if ($name === 'return' || $name === 'deprecated') {
            $merged[$name] = !empty($tags) ? $tags : ($inheritedTagsByName[$name] ?? []);
            continue;
        }

        if ($name === 'throws' || $name === 'example') {
            $merged[$name] = mergeSequentialTags($inheritedTagsByName[$name] ?? [], $tags);
            continue;
        }

        $merged[$name] = $tags;
    }

    return $merged;
}

function mergeParamTagLists(array $localTags, array $inheritedTags, ReflectionMethod $method): array
{
    $tagMap = [];
    $order = [];

    foreach ($inheritedTags as $index => $tag) {
        $key = resolveParamTagKey($tag, $index);
        $tagMap[$key] = $tag;
        $order[] = $key;
    }

    foreach ($localTags as $index => $tag) {
        $key = resolveParamTagKey($tag, $index);
        if (!isset($tagMap[$key])) {
            $order[] = $key;
        }
        $tagMap[$key] = $tag;
    }

    $ordered = [];
    $used = [];
    foreach ($method->getParameters() as $index => $parameter) {
        foreach (['name:' . $parameter->getName(), 'index:' . $index] as $key) {
            if (isset($tagMap[$key]) && !isset($used[$key])) {
                $ordered[] = $tagMap[$key];
                $used[$key] = true;
                break;
            }
        }
    }

    foreach ($order as $key) {
        if (!isset($used[$key]) && isset($tagMap[$key])) {
            $ordered[] = $tagMap[$key];
            $used[$key] = true;
        }
    }

    return $ordered;
}

function resolveParamTagKey($tag, int $fallbackIndex): string
{
    if ($tag instanceof ParamTag) {
        $varName = $tag->getVariableName();
        if ($varName !== null && $varName !== '') {
            return 'name:' . ltrim($varName, '$');
        }
    }

    $text = trim(safeTagToString($tag));
    if ($text !== '' && preg_match('/^\$?([A-Za-z0-9_]+)/', $text, $m)) {
        return 'name:' . $m[1];
    }

    return 'index:' . $fallbackIndex;
}

function mergeSequentialTags(array $inheritedTags, array $localTags): array
{
    $merged = [];
    $seen = [];

    foreach (array_merge($inheritedTags, $localTags) as $tag) {
        $key = trim(safeTagToString($tag));
        if ($key === '') {
            $key = spl_object_hash($tag);
        }
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $merged[] = $tag;
    }

    return $merged;
}

function hasResolvedTag(array $docData, string $name): bool
{
    return !empty($docData['tagsByName'][strtolower($name)] ?? []);
}

function getResolvedTags(array $docData, string $name): array
{
    return $docData['tagsByName'][strtolower($name)] ?? [];
}

function findInheritedMethodPrototype(ReflectionMethod $method): ?ReflectionMethod
{
    try {
        return $method->getPrototype();
    } catch (ReflectionException) {
    }

    $name = $method->getName();
    $class = $method->getDeclaringClass();

    $parent = $class->getParentClass();
    while ($parent !== false) {
        if ($parent->hasMethod($name)) {
            return $parent->getMethod($name);
        }
        $parent = $parent->getParentClass();
    }

    foreach ($class->getInterfaces() as $interface) {
        if ($interface->hasMethod($name)) {
            return $interface->getMethod($name);
        }
    }

    return null;
}

/**
 * Render one or more @example tags as a fenced PHP code block section.
 *
 * phpDocumentor's ExampleTag splits the tag body as:
 *   @example [filePath] [startLine] [lineCount] [description]
 *
 * We treat the combination of filePath + description as inline code when the
 * filePath does not look like an actual file reference (no extension / path
 * separator). Real file references are emitted as a prose link instead.
 */
function renderExampleTags(array $exampleTags): string
{
    if (empty($exampleTags)) {
        return '';
    }

    $count = count($exampleTags);
    $md = '**💡 ' . ($count === 1 ? 'Example' : 'Examples') . "**\n\n";

    foreach ($exampleTags as $tag) {
        if (!($tag instanceof ExampleTag)) {
            $raw = trim(safeTagToString($tag));
            if ($raw !== '') {
                $md .= "```php\n{$raw}\n```\n\n";
            }
            continue;
        }

        $filePath = trim($tag->getFilePath());
        $desc = trim((string) $tag->getDescription());

        // Looks like a real file when it has an extension or a path separator.
        $looksLikeFile = $filePath !== '' &&
            (str_contains($filePath, '/') ||
                str_contains($filePath, '\\') ||
                preg_match('/\.[a-zA-Z]{2,5}$/', $filePath));

        if ($looksLikeFile) {
            $lineInfo = '';
            $start = $tag->getStartingLine();
            $count = $tag->getLineCount();
            if ($start > 1) {
                $lineInfo = " (line{$start}" . ($count > 0 ? '–' . ($start + $count - 1) : '') . ')';
            }
            $md .= "`{$filePath}`{$lineInfo}" . ($desc !== '' ? " – {$desc}" : '') . "\n\n";
        } else {
            // Treat the whole tag body as an inline PHP snippet.
            $code = rtrim($filePath . ($desc !== '' ? "\n" . $desc : ''));
            if ($code !== '') {
                $md .= "```php\n{$code}\n```\n\n";
            }
        }
    }

    return $md;
}

function makeDocFileName(string $fqcn): string
{
    $parts = explode('\\', ltrim($fqcn, '\\'));
    if (count($parts) > 1 && $parts[0] === 'Merlin') {
        array_shift($parts);
    }
    $parts = array_map(
        fn(string $part): string => preg_replace('/[^A-Za-z0-9_]/', '_', $part),
        $parts
    );
    return implode('_', $parts) . '.md';
}

/**
 * Convert a type string (may contain | or & separators) into markdown with
 * inline links for known Merlin classes. Unrecognised types pass through
 * decorateType() which adds emojis for primitives and backtick-wraps the rest.
 */
/**
 * @param string $mode 'doc' = link to API .md page, 'src' = link to source file
 * @param bool $decorate Whether to decorate unrecognized types with emojis and backticks
 */
function linkType(string $typeStr, array $classRegistry, string $mode = 'doc', bool $decorate = false): string
{
    if ($typeStr === '')
        return '';
    $decorate = false;

    // Split on | and & while keeping the delimiters
    $parts = preg_split('/([|&])/', $typeStr, -1, PREG_SPLIT_DELIM_CAPTURE);
    $result = '';
    foreach ($parts as $part) {
        if ($part === '|' || $part === '&') {
            $result .= $part;
            continue;
        }
        $part = trim($part);
        $lookup = ltrim($part, '\\');    // strip leading \ from docblock FQCNs

        if (isset($classRegistry[$lookup])) {
            $info = $classRegistry[$lookup];
            $target = $mode === 'src' ? $info['srcLink'] : $info['docLink'];
            $name = $info['short'];
            if ($decorate) {
                $name = "🧩`{$name}`";
            }
            $result .= "[{$name}]({$target})";
        } else {
            $result .= $decorate ? decorateType($part) : $part;
        }
    }
    return $result;
}

/**
 * Resolve {@see \Ns\Class}, {@see \Ns\Class::method()} and bare {@see \method()}
 * inline tags in prose text to markdown links, using the class registry.
 * Falls back to inline code when the target is not found.
 */
function resolveInlineTags(string $text, array $classRegistry): string
{
    // Handles all forms:
    //   {@see \Ns\Class}
    //   {@see \Ns\Class::method()}
    //   {@see \bareMethod()}   (method-only, no class qualifier)
    //   {@see bareMethod()}
    //   {@see $variable()}     (variable callable – rendered as inline code)
    return preg_replace_callback(
        '/\{@(?:see|link)\s+(\$?\\\\?[A-Za-z0-9_\\\\]+)(?:::([A-Za-z0-9_]+))?(\(\))?\s*\}/',
        function (array $m) use ($classRegistry): string {
            $fqcn = ltrim($m[1], '\\');
            $method = ($m[2] ?? '') !== '' ? $m[2] : null;
            $hasParens = ($m[3] ?? '') !== '';

            if (isset($classRegistry[$fqcn])) {
                $info = $classRegistry[$fqcn];
                $docLink = $info['docLink'];
                if ($method) {
                    $anchor = strtolower($method);
                    $label = $info['short'] . '::' . $method . '()';
                    return "[`{$label}`]({$docLink}#{$anchor})";
                }
                return "[`{$info['short']}`]({$docLink})";
            }

            // Unknown target (bare method name, external class, etc.) – inline code
            $raw = $fqcn . ($method ? '::' . $method . '()' : ($hasParens ? '()' : ''));
            return "`{$raw}`";
        },
        $text
    );
}

/**
 * Escape only the union/intersection | separators for use inside a markdown
 * table cell, without touching | characters inside link URL parentheses.
 */
function escapeTablePipes(string $linkedType): string
{
    $result = '';
    $depth = 0;
    for ($i = 0, $len = strlen($linkedType); $i < $len; $i++) {
        $ch = $linkedType[$i];
        if ($ch === '(') {
            $depth++;
            $result .= $ch;
        } elseif ($ch === ')') {
            $depth--;
            $result .= $ch;
        } elseif ($ch === '|' && $depth === 0) {
            $result .= '\|';
        } else {
            $result .= $ch;
        }
    }
    return $result;
}

/**
 * Format a single method parameter as a linked-type + `$name` fragment
 * suitable for embedding inline in the signature line.
 */
function formatLinkedParameter(ReflectionParameter $p, array $classRegistry): string
{
    $typeStr = formatReflectionType($p->getType());
    $linkedType = $typeStr ? linkType($typeStr, $classRegistry, 'doc', false) : '';
    $byRef = $p->isPassedByReference() ? '&' : '';
    $variadic = $p->isVariadic() ? '...' : '';
    $namePart = '`' . $byRef . $variadic . '$' . $p->getName();
    if ($p->isDefaultValueAvailable() && !$p->isVariadic()) {
        $namePart .= ' = ' . formatDefaultValue($p->getDefaultValue());
    }
    $namePart .= '`';
    return $linkedType ? ($linkedType . ' ' . $namePart) : $namePart;
}

function formatParameter(ReflectionParameter $p): string
{
    $typeStr = formatReflectionType($p->getType());
    $byRef = $p->isPassedByReference() ? '&' : '';
    $variadic = $p->isVariadic() ? '...' : '';
    $namePart = $byRef . $variadic . '$' . $p->getName();
    if ($p->isDefaultValueAvailable() && !$p->isVariadic()) {
        $namePart .= ' = ' . formatDefaultValue($p->getDefaultValue());
    }
    return $typeStr ? ($typeStr . ' ' . $namePart) : $namePart;
}

function mapParamTags(array $paramTags): array
{
    $map = [];
    foreach ($paramTags as $tag) {
        if ($tag instanceof ParamTag) {
            $varName = $tag->getVariableName();
            $desc = (string) $tag->getDescription();
            $varName = $varName ? ltrim($varName, '$') : null;
            if ($varName) {
                $map[$varName] = $desc;
            } else {
                $map[] = $desc;
            }
        } else {
            $text = trim(safeTagToString($tag));
            if ($text === '')
                continue;
            if (preg_match('/^\$?([a-zA-Z0-9_]+)\b(.*)$/s', $text, $m)) {
                $name = $m[1];
                $desc = trim($m[2]);
                if ($name) {
                    $map[$name] = $desc;
                    continue;
                }
            }
            $map[] = $text;
        }
    }
    return $map;
}

function safeTagToString($tag): string
{
    try {
        return (string) $tag;
    } catch (Throwable $e) {
        return '';
    }
}

function cleanDocBlock(string $doc): string
{
    $clean = preg_replace('/^\s*\/\*\*|\*\/\s*$/', '', $doc);
    $clean = preg_replace('/^\s*\*\s?/m', '', trim($clean));
    $clean = preg_replace('/@[\w]+\s+.*$/ms', '', $clean);
    return trim($clean);
}

function formatReflectionType(?ReflectionType $type): string
{
    if ($type === null)
        return 'mixed';

    // Intersection types (PHP 8.1+)
    if (class_exists('ReflectionIntersectionType') && $type instanceof ReflectionIntersectionType) {
        $names = [];
        foreach ($type->getTypes() as $t) {
            $names[] = $t instanceof ReflectionNamedType ? $t->getName() : 'mixed';
        }
        return implode('&', $names);
    }

    // Union types
    if ($type instanceof ReflectionUnionType) {
        $names = [];
        foreach ($type->getTypes() as $t) {
            $names[] = $t instanceof ReflectionNamedType ? $t->getName() : 'mixed';
        }
        return implode('|', $names);
    }

    // Named type
    if ($type instanceof ReflectionNamedType) {
        $name = $type->getName();
        if ($type->allowsNull() && $name !== 'mixed') {
            return $name . '|null';
        }
        return $name;
    }

    return 'mixed';
}

function formatDefaultValue($value): string
{
    if (is_null($value))
        return 'null';
    if (is_bool($value))
        return $value ? 'true' : 'false';
    if (is_string($value))
        return "'" . str_replace("'", "\\'", $value) . "'";
    if (is_array($value))
        return '[]';
    if (is_object($value))
        return get_class($value);
    return (string) $value;
}

/**
 * Export a value similar to var_export but using short array syntax ([]) and
 * a readable, indented format for arrays.
 */
function short_var_export($value, int $indent = 0): string
{
    if (is_array($value)) {
        if (empty($value)) {
            return '[]';
        }
        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        $pad = str_repeat(' ', $indent);
        $padNext = str_repeat(' ', $indent + 4);
        $items = [];
        foreach ($value as $k => $v) {
            $exported = short_var_export($v, $indent + 4);
            if ($isAssoc) {
                $key = is_int($k) ? $k : '\'' . str_replace("'", "\\'", $k) . '\'';
                $items[] = $padNext . $key . ' => ' . $exported;
            } else {
                $items[] = $padNext . $exported;
            }
        }
        return "[\n" . implode(",\n", $items) . "\n" . $pad . "]";
    }
    if (is_null($value)) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_string($value)) {
        return "'" . str_replace("'", "\\'", $value) . "'";
    }
    if (is_object($value)) {
        return 'object(' . get_class($value) . ')';
    }
    if (is_array($value) === false && (is_int($value) || is_float($value))) {
        return (string) $value;
    }
    return var_export($value, true);
}

function getVisibility(ReflectionMethod|ReflectionProperty $r): string
{
    if ($r->isPrivate())
        return 'private';
    if ($r->isProtected())
        return 'protected';
    return 'public';
}

function decorateType(string $type): string
{
    return match ($type) {
        'string' => "🔤 `string`",
        'int' => "🔢 `int`",
        'float' => "🌡️ `float`",
        'bool' => "⚙️ `bool`",
        'array' => "📦 `array`",
        'object' => "🧱 `object`",
        'mixed' => "🎲 `mixed`",
        'null' => "`null`",
        'void' => "`void`",
        'never' => "`never`",
        'self' => "🧩 `self`",
        'static' => "🧩 `static`",
        default => "`{$type}`",
    };
}
