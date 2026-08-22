# Code Quality Setup Guide

**Laravel API & Next.js Projects**
Based on the Kautionskonto project setup | April 2026

---

## Part 1: Laravel API Project

This section covers setting up a complete code quality pipeline for a Laravel API-only project: GrumPHP pre-commit hooks, Laravel Pint (PSR-12), Rector for automated refactoring, PHPStan for static analysis, and PHPUnit for testing.

### 1. Tool Overview

| Tool | Purpose |
|------|---------|
| Laravel Pint | Code formatter — enforces PSR-12 style, removes unused imports, orders imports by length |
| Rector | Automated refactoring — applies Laravel best practices, removes dead code, improves type coverage |
| PHPStan (Larastan) | Static analysis — catches bugs, type errors, and undefined properties at Level 5 |
| PHPUnit | Testing framework — runs your feature and unit tests |
| GrumPHP | Pre-commit hooks — runs all of the above automatically before each git commit |

### 2. Installation

#### 2.1 Install Packages

```bash
composer require --dev laravel/pint rector/rector larastan/larastan phpunit/phpunit
composer require --dev phpro/grumphp yieldstudio/grumphp-laravel-pint
```

#### 2.2 Verify Installation

```bash
./vendor/bin/pint --version
./vendor/bin/rector --version
./vendor/bin/phpstan --version
./vendor/bin/grumphp run
```

### 3. Laravel Pint Configuration

Create `pint.json` in your project root:

```json
{
    "preset": "psr12",
    "rules": {
        "no_unused_imports": true,
        "ordered_imports": {
            "sort_algorithm": "length"
        },
        "global_namespace_import": {
            "import_classes": true,
            "import_constants": true,
            "import_functions": true
        },
        "fully_qualified_strict_types": {
            "import_symbols": true
        },
        "no_superfluous_phpdoc_tags": {
            "allow_mixed": true,
            "allow_unused_params": true
        }
    }
}
```

#### Usage

```bash
./vendor/bin/pint                  # Fix all files
./vendor/bin/pint --dirty           # Fix only changed files
./vendor/bin/pint --test            # Check without fixing
```

### 4. Rector Configuration

Create `rector.php` in your project root:

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;
use RectorLaravel\Set\LaravelSetList;
use Rector\Caching\ValueObject\Storage\FileCacheStorage;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/bootstrap',
        __DIR__ . '/config',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ])
    ->withCache(
        __DIR__ . '/storage/rector/cache',
        FileCacheStorage::class
    )
    ->withTypeCoverageLevel(0)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0)
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
    ])
    ->withPhpVersion(PhpVersion::PHP_83);
```

#### Usage

```bash
./vendor/bin/rector process            # Apply fixes
./vendor/bin/rector process --dry-run  # Preview changes
```

> **Tip:** Add `storage/rector/cache` to your `.gitignore`.

### 5. PHPStan (Larastan) Configuration

Create `phpstan.neon` in your project root:

```yaml
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app/
    level: 5
    excludePaths:
        - ./tests/**
        - ./database/**
```

#### Usage

```bash
./vendor/bin/phpstan analyse
./vendor/bin/phpstan analyse --memory-limit=-1
```

| Level | What It Checks |
|-------|----------------|
| 0–2 | Basic: unknown classes, functions, methods |
| 3–4 | + return types, dead code branches |
| 5 | + argument types passed to methods (recommended starting point) |
| 6–8 | + strict nullability, union types, mixed |
| 9–10 | Maximum strictness |

> **Tip:** Start at Level 5 and increase gradually. Suppress false positives in the `ignoreErrors` section of `phpstan.neon`.

### 6. GrumPHP Pre-Commit Hooks

Create `grumphp.yml` in your project root. GrumPHP will automatically register itself as a Git pre-commit hook when installed via Composer.

```yaml
grumphp:
    process_timeout: 120
    extensions:
        - YieldStudio\GrumPHPLaravelPint\ExtensionLoader
    tasks:
        laravel_pint:
            config: pint.json
            preset: psr12
            auto_fix: false
            auto_stage: 'pre_commit'
            triggered_by: [php]
        rector: ~
        phpstan:
            configuration: ./phpstan.neon
            use_grumphp_paths: false
            triggered_by: [php]
            memory_limit: "-1"
        git_blacklist:
            match_word: true
            keywords:
                - " die("
                - " dump("
                - " dd("
                - " var_dump("
                - " exit;"
        paratest: ~
        git_commit_message:
            enforce_capitalized_subject: false
            max_body_width: 100
            max_subject_width: 100
            type_scope_conventions:
                types:
                    - feat
                    - fix
                    - refactor
                    - chore
                    - style
                    - docs
                    - test
```

> **Tip:** If you use JIRA, add a `matchers` section under `git_commit_message` to enforce ticket numbers in feat/fix commits.

### 7. Daily Workflow

During development, focus on writing code. Only run quality tools at the end before committing:

1. **Write your code** — don't run linters mid-development
2. **Run Rector:** `./vendor/bin/rector process`
3. **Run Pint:** `./vendor/bin/pint`
4. **Stage and commit** — GrumPHP runs all checks automatically
5. **If the commit is rejected,** fix the reported issues, re-stage, and commit again

---

## Part 2: Next.js Project

This section covers setting up ESLint v9 (flat config), Prettier, strict TypeScript checking, and Husky + lint-staged for pre-commit hooks in a Next.js project.

### 8. Tool Overview

| Tool | Purpose |
|------|---------|
| ESLint v9 | Linter — catches bugs, enforces best practices with flat config format |
| Prettier | Formatter — consistent code style (semicolons, quotes, indentation) |
| TypeScript | Type checker — strict mode catches type errors at compile time |
| Husky | Git hooks manager — runs scripts on pre-commit |
| lint-staged | Runs linters only on staged files for fast pre-commit checks |

### 9. Installation

#### 9.1 ESLint + Prettier

```bash
npm install -D eslint @eslint/js typescript-eslint eslint-config-prettier
npm install -D eslint-plugin-react eslint-plugin-react-hooks
npm install -D prettier prettier-plugin-organize-imports prettier-plugin-tailwindcss
```

#### 9.2 Husky + lint-staged

```bash
npm install -D husky lint-staged
npx husky init
```

This creates a `.husky/` directory and adds a `prepare` script to your `package.json`.

### 10. ESLint Configuration

Create `eslint.config.js` in your project root (ESLint v9 flat config):

```js
import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import typescript from 'typescript-eslint';
import { globalIgnores } from 'eslint/config';

export default [
    globalIgnores(['node_modules/**', '.next/**', 'out/**']),
    js.configs.recommended,
    ...typescript.configs.recommended,
    {
        files: ['**/*.tsx', '**/*.ts'],
        ...react.configs.flat.recommended,
        ...react.configs.flat['jsx-runtime'],
        languageOptions: {
            globals: { ...globals.browser },
        },
        rules: {
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off',
        },
        settings: { react: { version: 'detect' } },
    },
    {
        files: ['**/*.tsx', '**/*.ts'],
        plugins: { 'react-hooks': reactHooks },
        rules: {
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'warn',
        },
    },
    prettier,
];
```

> **Tip:** The `prettier` config at the end disables all ESLint rules that conflict with Prettier, so the two tools never fight.

### 11. Prettier Configuration

Create `.prettierrc` in your project root:

```json
{
    "semi": true,
    "singleQuote": true,
    "printWidth": 120,
    "tabWidth": 4,
    "plugins": [
        "prettier-plugin-organize-imports",
        "prettier-plugin-tailwindcss"
    ],
    "overrides": [
        { "files": "**/*.json", "options": { "tabWidth": 2 } },
        { "files": "**/*.yml", "options": { "tabWidth": 2 } }
    ]
}
```

Create `.prettierignore`:

```
node_modules/
.next/
out/
coverage/
package-lock.json
```

### 12. TypeScript Strict Configuration

Ensure these options are enabled in your `tsconfig.json`:

```json
{
    "compilerOptions": {
        "strict": true,
        "noImplicitAny": true,
        "noEmit": true,
        "esModuleInterop": true,
        "forceConsistentCasingInFileNames": true,
        "skipLibCheck": true,
        "isolatedModules": true,
        "moduleResolution": "bundler",
        "jsx": "preserve"
    }
}
```

> **Tip:** Next.js generates a `tsconfig.json` with sensible defaults. Only add `strict: true` and `noImplicitAny: true` if they are not already set.

### 13. Husky + lint-staged

#### 13.1 Configure lint-staged

Add to your `package.json`:

```json
"lint-staged": {
    "*.{ts,tsx}": [
        "eslint --fix",
        "prettier --write"
    ],
    "*.{json,md,yml,yaml,css}": [
        "prettier --write"
    ]
}
```

#### 13.2 Configure Husky pre-commit hook

Edit `.husky/pre-commit`:

```bash
npx lint-staged
npx tsc --noEmit
```

> **Tip:** TypeScript type checking runs on all files (not just staged) because types depend on the whole project. This takes a few seconds but catches real bugs.

### 14. Package.json Scripts

Add these scripts for manual use:

```json
"scripts": {
    "lint": "eslint . --fix",
    "lint:check": "eslint .",
    "format": "prettier --write .",
    "format:check": "prettier --check .",
    "types": "tsc --noEmit"
}
```

### 15. Daily Workflow

1. **Write your code** — don't run linters mid-development
2. **Stage and commit** — Husky triggers lint-staged + tsc automatically
3. **If the commit is rejected:** fix ESLint/Prettier issues (often auto-fixed), fix TypeScript errors manually, then re-stage and commit
4. **For manual checks:** `npm run lint`, `npm run format`, `npm run types`

---

## Quick Reference

### Laravel API Commands

| Command | Description |
|---------|-------------|
| `./vendor/bin/pint` | Format PHP code (PSR-12) |
| `./vendor/bin/pint --dirty` | Format only changed files |
| `./vendor/bin/rector process` | Apply automated refactoring |
| `./vendor/bin/rector process --dry-run` | Preview Rector changes |
| `./vendor/bin/phpstan analyse` | Run static analysis |
| `php artisan test` | Run PHPUnit tests |
| `./vendor/bin/grumphp run` | Run all checks manually |

### Next.js Commands

| Command | Description |
|---------|-------------|
| `npm run lint` | Lint and auto-fix TypeScript/React |
| `npm run lint:check` | Check lint without fixing |
| `npm run format` | Format all files with Prettier |
| `npm run format:check` | Check formatting without fixing |
| `npm run types` | TypeScript type check |
