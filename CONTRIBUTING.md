# Contributing to e-Syrians API

Thank you for your interest in contributing! This guide will help you get set up and ensure your contributions meet the project's standards.

## Getting Started

### Prerequisites

- PHP 8.3+
- Composer
- Node.js 22+ and npm
- SQLite (for testing)

### Setup

1. Fork the repository and clone your fork:

```bash
git clone git@github.com:YOUR_USERNAME/e-syrians-api.git
cd e-syrians-api
```

2. Install dependencies:

```bash
composer install
```

3. Copy the environment file and generate an app key:

```bash
cp .env.example .env
php artisan key:generate
```

4. Run the migrations:

```bash
php artisan migrate
```

5. Verify everything works:

```bash
php artisan test
```

When you run `composer install`, GrumPHP automatically registers a pre-commit hook. Every commit you make will be checked for code quality before it goes through.

## Code Quality Standards

This project enforces strict code quality through automated tooling. All checks run automatically on every commit via GrumPHP, and again in CI on every pull request.

### Formatting — Laravel Pint (PSR-12)

All PHP code must follow PSR-12 with our custom rules (defined in `pint.json`). To format your code:

```bash
./vendor/bin/pint
```

To check without modifying files:

```bash
./vendor/bin/pint --test
```

### Static Analysis — PHPStan Level 5

All new code must pass PHPStan at Level 5. Existing errors are tracked in `phpstan-baseline.neon` and should not grow.

```bash
./vendor/bin/phpstan analyse
```

If you're fixing existing PHPStan errors, regenerate the baseline after your fix:

```bash
./vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon
```

### Tests — Pest

All new features and bug fixes should include tests. Run the test suite with:

```bash
php artisan test
```

### What the Pre-Commit Hook Checks

Every commit is automatically checked for:

- **Pint** — code formatting
- **PHPStan** — static analysis
- **Pest** — test suite
- **Git blacklist** — blocks `dd()`, `dump()`, `var_dump()`, `die()`, `exit;`
- **Commit message** — must follow Conventional Commits format

If a check fails, your commit will be rejected with an explanation of what to fix.

## Commit Message Convention

All commit messages must follow the [Conventional Commits](https://www.conventionalcommits.org/) specification:

```
type: short description
```

Allowed types: `feat`, `fix`, `refactor`, `chore`, `style`, `docs`, `test`

Examples:

```
feat: add poll expiration date support
fix: prevent duplicate votes from same user
refactor: extract poll validation to form request
docs: update API authentication examples
test: add coverage for poll audience filtering
```

## Pull Request Process

1. Create a feature branch from `develop`:

```bash
git checkout -b feat/your-feature develop
```

2. Make your changes with well-structured commits.

3. Make sure all checks pass:

```bash
./vendor/bin/pint
./vendor/bin/phpstan analyse
php artisan test
```

4. Push your branch and open a pull request against `develop`.

5. Fill out the PR template — describe what changed, why, and how to test it.

6. Wait for CI to pass and a maintainer to review.

## Rector (Optional)

Rector is configured for automated refactoring but is not part of the pre-commit hook. You can run it manually to modernize code:

```bash
php vendor/bin/rector process
```

Review the changes carefully before committing — Rector can be aggressive with refactoring.

## Questions?

If you're unsure about anything, open an issue and ask. We'd rather help you contribute than have you get stuck.
