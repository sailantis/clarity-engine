#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Extract one released section of CHANGELOG.md, for use as GitHub release notes.
 *
 * Usage:
 *   php scripts/release-notes.php v0.1.1            > notes.md   (stdout)
 *   php scripts/release-notes.php v0.1.1 notes.md               (file)
 *   gh release create v0.1.1 --notes-file notes.md
 *
 * Prefer the two-argument form on Windows: PowerShell's `>` redirection
 * encodes as UTF-16LE, which `gh` would upload as NUL-littered notes.
 *
 * Prints the section body (without its `## [x.y.z]` heading, because `gh` takes
 * that from --title) and stops at the next version heading or at the trailing
 * link-reference definitions.  Exits non-zero when the version is undocumented,
 * so a tag with no changelog entry cannot silently publish an empty release.
 */

$root      = dirname(__DIR__);
$changelog = $root . '/CHANGELOG.md';

$arg = $argv[1] ?? '';
if ($arg === '') {
    fwrite(STDERR, "usage: php scripts/release-notes.php <version> [output-file]\n");
    exit(2);
}

$version = ltrim($arg, 'vV');
if ($version === '') {
    fwrite(STDERR, "error: could not read a version out of '{$arg}'\n");
    exit(2);
}

if (!is_file($changelog)) {
    fwrite(STDERR, "error: no CHANGELOG.md at {$changelog}\n");
    exit(2);
}

$lines = file($changelog, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "error: could not read {$changelog}\n");
    exit(2);
}

/** Every `## [x.y.z]` heading found, for the "available" hint. */
$documented = [];
foreach ($lines as $line) {
    if (preg_match('/^## \[([^\]]+)\]/', $line, $m) && $m[1] !== 'Unreleased') {
        $documented[] = $m[1];
    }
}

$start = null;
foreach ($lines as $i => $line) {
    // Keep a Changelog allows an optional release date after the version, so
    // both `## [0.1.1]` and `## [0.1.1] - 2026-09-28` select the section.
    if (preg_match('/^## \[' . preg_quote($version, '/') . '\](?:\s+-\s+\S+)?\s*$/', $line)) {
        $start = $i + 1;
        break;
    }
}

if ($start === null) {
    fwrite(STDERR, "error: no '## [{$version}]' section in CHANGELOG.md\n");
    fwrite(STDERR, 'documented versions: ' . ($documented === [] ? '(none)' : implode(', ', $documented)) . "\n");
    exit(1);
}

$body = [];
for ($i = $start, $n = count($lines); $i < $n; $i++) {
    $line = $lines[$i];

    // A new version section ends this one.
    if (preg_match('/^## \[/', $line)) {
        break;
    }
    // Link-reference definitions (`[0.1.1]: https://…`) and horizontal rules are
    // file furniture, not release notes; both appear after the last version.
    if (preg_match('/^\[[^\]]+\]:\s+\S/', $line) || preg_match('/^---\s*$/', $line)) {
        break;
    }

    $body[] = $line;
}

$body = trim(implode("\n", $body));

if ($body === '') {
    fwrite(STDERR, "error: the '## [{$version}]' section in CHANGELOG.md is empty\n");
    exit(1);
}

$body .= "\n";

// Two-argument form: write the file here so the caller never relies on shell
// redirection (PowerShell would pick UTF-16LE and corrupt the notes).
$outFile = $argv[2] ?? '';
if ($outFile !== '') {
    $dir = \dirname($outFile);
    if ($dir !== '' && !\is_dir($dir) && !@\mkdir($dir, 0777, true) && !\is_dir($dir)) {
        fwrite(STDERR, "error: cannot create directory {$dir}\n");
        exit(2);
    }
    if (@\file_put_contents($outFile, $body) === false) {
        fwrite(STDERR, "error: cannot write {$outFile}\n");
        exit(2);
    }
    fwrite(STDERR, "wrote {$outFile} (" . \strlen($body) . " bytes)\n");
    exit(0);
}

echo $body;
