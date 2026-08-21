# Contributing to e-Syrians API

Thank you for your interest in contributing! This guide will help you get set up and ensure your contributions meet the project's standards.

## Getting Started

### Prerequisites

- PHP 8.4+
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

## AI Review Policy

Every non-draft, non-bot PR gets one AI review pass (Codex)
automatically on open. Maintainers can request up to **2 additional
passes** if needed for follow-ups. Beyond that, further invocations
are auto-declined until you address the outstanding feedback.

Ceiling: **3 reviews per PR** (1 auto + 2 manual). Non-maintainers
cannot invoke Codex — their `@codex` comments are auto-deleted
with a friendly notice.

Bot-authored comments and draft PRs never trigger Codex. If you
open a draft, the auto-invocation fires when you mark the PR ready
for review (not on `opened`).

Workflows involved:
- `.github/workflows/auto-invoke-ai-review.yml` — posts the initial
  `@codex review` comment on PR open / ready-for-review, once per PR.
- `.github/workflows/gate-codex-review.yml` — enforces maintainer-only
  for manual re-invocations and caps them at 2.

### If you're a contributor

You don't need to do anything. When you open a PR, the auto-invoker
will post an `@codex review` comment within seconds and Codex will
review your changes. Address the feedback the same way you'd address
a human reviewer's — reply where you disagree, push commits for the
rest. If you want a second look after your fixes, ask a maintainer
in a normal comment (don't try `@codex review` yourself; the gate
will delete it).

### For maintainers: requesting a follow-up review

Post a **new comment** on the PR (not an edit to any existing
comment — that path doesn't fire the workflow) containing the focus
block below. GitHub suppresses `@` mentions inside code fences, so
the snippet is safe to copy from here:

```
@codex review

Focus especially on:
- SOLID: is business logic in services, not controllers?
- N+1: any eager-load misses in list endpoints?
- FormRequest validation: rules for every write; error keys are raw
  strings (not `__()` calls)
- PII: no plaintext `national_id` / `address` in responses, logs, or
  seeder output
- reCAPTCHA middleware present on every state-changing route
- Sanctum: bearer-token guard on protected endpoints (not the web
  guard)
- Soft-delete: relevant queries respect `SoftDeletingScope`; global
  scopes not bypassed silently
- Pest coverage: happy path AND at least one 4xx path per endpoint
- Backwards compat: API envelope (`success` / `messages` / `data`)
  and existing keys untouched
```

Tune the focus list per PR — remove bullets that don't apply, add
change-specific concerns (e.g. "check that the new migration is
rollback-safe under concurrent writes").

### Handling abusive PRs

If a contributor opens many low-value PRs to farm AI reviews,
block them at the repo or org level (GitHub → user profile → Block).
Blocked users can't open PRs or comment; the auto-invocation
naturally stops firing for them.

## Rector (Optional)

Rector is configured for automated refactoring but is not part of the pre-commit hook. You can run it manually to modernize code:

```bash
php vendor/bin/rector process
```

Review the changes carefully before committing — Rector can be aggressive with refactoring.

## Questions?

If you're unsure about anything, open an issue and ask. We'd rather help you contribute than have you get stuck.
