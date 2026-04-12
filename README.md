[![Laravel Forge Site Deployment Status](https://img.shields.io/endpoint?url=https%3A%2F%2Fforge.laravel.com%2Fsite-badges%2Fd55f69ee-1ca4-4496-b87d-a710397231fc%3Fdate%3D1%26commit%3D1&style=plastic)](https://forge.laravel.com/feras-jobeir/e-syrians/2627744)

# E-SYRIANS API

The backend API for e-Syrians, a community platform and census tool for Syrian citizens. It powers peer-to-peer identity verification, demographic polling with audience targeting, violation reporting, and census data collection.

The API serves a Next.js web app and a React Native mobile app.

## Tech Stack

Built with PHP 8.3+ and Laravel 13, using Sanctum for API authentication, Socialite for Google OAuth, Spatie Permission for role management, and Spatie Translatable for multilingual support (Arabic, English, Kurdish). Files are stored on AWS S3 and emails are sent via Resend.

## Features

**Authentication and Security** — Email/password registration, Google OAuth, two-factor authentication (TOTP with recovery codes), email verification, and password reset flows. All sensitive PII is stored with dual encryption (encrypted + hashed columns).

**Identity Verification** — A peer-to-peer verification system where users verify each other's identity. Configurable thresholds control how many verifiers are needed and what ratio is required. Certain profile changes (email, phone, national ID) automatically revoke verification status.

**Polling** — Create polls with demographic audience targeting, multiple choice options with configurable max selections, community-contributed options, upvote/downvote reactions, and privacy controls. Only verified users can vote.

**Violation Reporting** — Users can report violations with categorization, file attachments, and status tracking. The community can react to violations.

**Census and Statistics** — Platform-wide demographic statistics covering gender, age, hometown, current country, ethnicity, and religion. Data is cached for performance.

## Getting Started

### Prerequisites

- PHP 8.3+
- Composer
- SQLite (for local development and testing)

### Installation

```bash
git clone git@github.com:FJ-Studio/e-syrians-api.git
cd e-syrians-api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

### Running the Development Server

```bash
composer dev
```

This starts the Laravel server, queue worker, log viewer, and Vite dev server concurrently.

### Running Tests

```bash
php artisan test
```

## Code Quality

This project enforces strict code quality standards through automated tooling. All checks run on every commit via GrumPHP pre-commit hooks, and again in CI on every pull request.

**Laravel Pint** — PSR-12 formatting with custom rules. Run `./vendor/bin/pint` to format your code.

**PHPStan Level 5** — Static analysis via Larastan. Run `./vendor/bin/phpstan analyse` to check for errors.

**Pest** — Test framework. All new features and bug fixes should include tests.

**Conventional Commits** — All commit messages must follow the format `type: description` where type is one of: `feat`, `fix`, `refactor`, `chore`, `style`, `docs`, `test`.

**Git Blacklist** — Debug statements (`dd()`, `dump()`, `var_dump()`, `die()`, `exit;`) are blocked from being committed.

When you run `composer install`, GrumPHP automatically registers the pre-commit hook. No extra setup is needed.

## Contributing

We welcome contributions! Please read [CONTRIBUTING.md](CONTRIBUTING.md) before getting started. The guide covers the development setup, code quality standards, commit conventions, and pull request process.

## License

This project is open-sourced software licensed under the [MIT license](LICENSE).
