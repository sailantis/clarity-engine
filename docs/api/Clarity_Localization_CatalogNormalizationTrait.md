# Class: CatalogNormalizationTrait

**Full name:** [Clarity\Localization\CatalogNormalizationTrait](../../src/Localization/CatalogNormalizationTrait.php)

Shares the flat-catalog rules between the array-backed and file-backed
translation loaders.

A catalog is a flat `key → string` map: the `t` filter looks up `nav.home` as
a single key, so nesting is flattened with dot separators before a loader
returns anything. Both loaders must agree on that, or the same translations
would resolve differently depending on which loader read them.



---

[Back to the Index ⤴](README.md)
