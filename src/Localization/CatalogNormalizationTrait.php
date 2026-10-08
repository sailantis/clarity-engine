<?php

namespace Clarity\Localization;

/**
 * Shares the flat-catalog rules between the array-backed and file-backed
 * translation loaders.
 *
 * A catalog is a flat `key → string` map: the `t` filter looks up `nav.home` as
 * a single key, so nesting is flattened with dot separators before a loader
 * returns anything. Both loaders must agree on that, or the same translations
 * would resolve differently depending on which loader read them.
 */
trait CatalogNormalizationTrait
{
    /**
     * Flatten nested mappings to dot-notation keys, stringifying every value.
     *
     * `['nav' => ['home' => 'Start']]` → `['nav.home' => 'Start']`
     *
     * @param  array<mixed, mixed> $data   Possibly nested message table.
     * @param  string              $prefix Key prefix for recursion.
     * @return array<string, string>
     */
    protected function flattenCatalog(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $k => $v) {
            $key = $prefix !== '' ? $prefix . '.' . $k : (string) $k;
            if (\is_array($v)) {
                foreach ($this->flattenCatalog($v, $key) as $fk => $fv) {
                    $result[$fk] = $fv;
                }
            } else {
                $result[$key] = (string) $v;
            }
        }

        return $result;
    }
}
