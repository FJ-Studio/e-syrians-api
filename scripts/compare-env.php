<?php

function parseEnvFile($filePath): array
{
    if (! file_exists($filePath)) {
        throw new Exception("File not found: $filePath");
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and invalid lines
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value);
    }

    return $env;
}

try {
    $envPath = __DIR__ . '/../.env';
    $envExamplePath = __DIR__ . '/../.env.example';

    if (! file_exists($envPath)) {
        echo "⏭️  .env not found — skipping consistency check.\n";
        exit(0);
    }

    if (! file_exists($envExamplePath)) {
        echo "⏭️  .env.example not found — skipping consistency check.\n";
        exit(0);
    }

    $envExample = parseEnvFile($envExamplePath);
    $env = parseEnvFile($envPath);

    $missingInEnv = array_diff_key($envExample, $env);
    $extraInEnv = array_diff_key($env, $envExample);

    echo "=== Missing in .env (present in .env.example) ===\n";
    foreach ($missingInEnv as $key => $value) {
        echo "$key\n";
    }

    echo "\n=== Extra in .env (not in .env.example) ===\n";
    foreach ($extraInEnv as $key => $value) {
        echo "$key\n";
    }

    if (empty($missingInEnv) && empty($extraInEnv)) {
        echo "\n✅ .env and .env.example match perfectly.\n";
    }
    if (! empty($missingInEnv) || ! empty($extraInEnv)) {
        exit(1);
    }

    exit(0);
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
