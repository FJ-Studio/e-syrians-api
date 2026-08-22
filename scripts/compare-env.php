<?php

declare(strict_types=1);

/**
 * Compare `.env.example` (the canonical key list) against `.env` (the
 * actual local config) and fail when the two sets aren't EQUAL.
 *
 * Wired in:
 *   - `composer.json` → `scripts.check-env` → `@php scripts/compare-env.php`
 *   - `grumphp.yml`   → `check_env` task    → `composer_script: check-env`
 *
 * So this runs on every `git commit` (via the GrumPHP pre-commit hook)
 * and manually via `composer check-env`.
 *
 * Behaviour:
 *   - MISSING (in .env.example, absent in .env)
 *       → FAIL. A new required env was added but the local `.env`
 *         wasn't updated; the app would silently fall back to wrong
 *         defaults at runtime.
 *   - EXTRA (in .env, absent in .env.example)
 *       → FAIL. Either it's a typo (e.g. `RECAPTHCA_SECRET`) or it's a
 *         secret we forgot to document. Either way it should be in
 *         `.env.example` (with a placeholder value) so other devs see it.
 *   - Both files match → exit 0 quietly.
 *   - `.env` missing → SKIP (exit 0). Fresh clones haven't run
 *     `composer install`'s post-root-package-install copy hook yet.
 */

$root = dirname(__DIR__);
$examplePath = $root . '/.env.example';
$envPath = $root . '/.env';

if (! is_file($examplePath)) {
    fwrite(STDERR, "[check-env] ✗ .env.example missing at {$examplePath}\n");
    exit(1);
}

if (! is_file($envPath)) {
    fwrite(STDOUT, "[check-env] .env not found — skipping (run `cp .env.example .env`).\n");
    exit(0);
}

/**
 * Extract env keys from a Laravel-style env file.
 *
 * Accepts: `KEY=value`, `KEY="value"`, `KEY=` (empty values still count).
 * Ignores: blank lines, comments (`#…`), lines without `=`, and lines
 * whose LHS isn't a SCREAMING_SNAKE_CASE identifier — that last filter
 * catches accidentally committed prose or assignments inside comments
 * like `// foo = bar` that a naive split would otherwise pick up.
 *
 * Returns keys in declaration order (handy for diff-style output).
 *
 * @return list<string>
 */
function extract_env_keys(string $path): array
{
    $keys = [];
    $handle = fopen($path, 'r');
    if ($handle === false) {
        return $keys;
    }

    while (($line = fgets($handle)) !== false) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || $trimmed[0] === '#') {
            continue;
        }
        $eq = strpos($trimmed, '=');
        if ($eq === false) {
            continue;
        }
        $key = rtrim(substr($trimmed, 0, $eq));
        // Strict env-key shape — POSIX env var rules: must start with
        // a letter or underscore, then [A-Z0-9_]. Lowercase is
        // technically allowed by POSIX but conventionally not used in
        // Laravel env files; treat as "not an env line".
        if (! preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
            continue;
        }
        $keys[] = $key;
    }

    fclose($handle);

    return $keys;
}

$exampleKeys = extract_env_keys($examplePath);
$envKeys = extract_env_keys($envPath);

$exampleSet = array_flip($exampleKeys);
$envSet = array_flip($envKeys);

$missing = array_values(array_filter(
    $exampleKeys,
    static fn (string $k): bool => ! isset($envSet[$k]),
));
$extra = array_values(array_filter(
    $envKeys,
    static fn (string $k): bool => ! isset($exampleSet[$k]),
));

// Duplicate detection — same key declared twice silently shadows the
// first value when Laravel boots, so the dev's "actual" config is the
// LAST occurrence. Caught e.g. `INTERNAL_API_KEY` being listed twice
// in .env. Treat as a hard fail in both files.
$findDuplicates = static function (array $keys): array {
    $counts = array_count_values($keys);

    return array_keys(array_filter($counts, static fn (int $n): bool => $n > 1));
};
$exampleDupes = $findDuplicates($exampleKeys);
$envDupes = $findDuplicates($envKeys);

$failed = false;

if ($missing !== []) {
    fwrite(STDERR, "[check-env] ✗ Missing in .env (declared in .env.example):\n");
    foreach ($missing as $k) {
        fwrite(STDERR, "    - {$k}\n");
    }
    fwrite(STDERR, "  Fix: add the keys above to .env (copy the placeholders from .env.example, then fill in real values).\n");
    $failed = true;
}

if ($extra !== []) {
    fwrite(STDERR, "[check-env] ✗ Extra in .env (not declared in .env.example):\n");
    foreach ($extra as $k) {
        fwrite(STDERR, "    - {$k}\n");
    }
    fwrite(STDERR, "  Fix: either add these keys to .env.example (with a placeholder value) so other devs see them,\n");
    fwrite(STDERR, "       or remove them from .env if they're typos / no longer needed.\n");
    $failed = true;
}

if ($exampleDupes !== []) {
    fwrite(STDERR, "[check-env] ✗ Duplicate keys in .env.example:\n");
    foreach ($exampleDupes as $k) {
        fwrite(STDERR, "    - {$k}\n");
    }
    fwrite(STDERR, "  Fix: delete the duplicate declarations.\n");
    $failed = true;
}

if ($envDupes !== []) {
    fwrite(STDERR, "[check-env] ✗ Duplicate keys in .env:\n");
    foreach ($envDupes as $k) {
        fwrite(STDERR, "    - {$k}\n");
    }
    fwrite(STDERR, "  Fix: delete the duplicate declarations — Laravel silently takes the last value, which is rarely what you want.\n");
    $failed = true;
}

if ($failed) {
    exit(1);
}

fwrite(STDOUT, "[check-env] ✓ .env matches .env.example (" . count($exampleKeys) . " keys).\n");
exit(0);
