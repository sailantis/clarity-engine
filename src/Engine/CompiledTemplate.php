<?php
namespace Clarity\Engine;

/**
 * Value object produced by the Clarity Compiler for a single template.
 *
 * @property-read string   $className    Fully-qualified class name of the compiled template.
 * @property-read string   $code         Complete PHP source code of the compiled class.
 * @property-read string[] $sourceFiles  Logical template names, indexed by the integers in $sourceMap.
 * @property-read array    $sourceMap    Maps PHP line numbers → [fileIndex, templateLine].
 * @property-read array    $dependencies Associative array of [logicalName => revision (int|string)]
 *                                       for every template (entry + extends + includes) read
 *                                       during compilation. Used for cache invalidation.
 */
class CompiledTemplate
{
    /**
     * @param string             $className    Generated class name (e.g. __Clarity_f1f1fde8ef8cc7825f199f1b7bf3ad0e).
     * @param string             $code         Full PHP source of the compiled file.
     * @param array              $sourceMap    [phpLine, fileIndex, templateLine] mapping.
     * @param array<string,int|string> $dependencies [logicalName => revision] for cache invalidation.
     * @param string[]           $sourceFiles  Unique logical template names (parallel to $sourceMap file indices).
     */
    public function __construct(
        public readonly string $className,
        public readonly string $code,
        public readonly array $sourceMap,
        public readonly array $dependencies,
        public readonly array $sourceFiles = [],
    ) {
    }
}
