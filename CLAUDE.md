# CLAUDE.md — E-Syrians API

## Project Overview

E-Syrians is a community platform for Syrian citizens. This is the **Laravel 12 backend API** serving the Next.js frontend. Core features: user registration and authentication, peer-to-peer identity verification, demographic polls with audience targeting, violation reporting, and census data collection.

Tech stack: PHP 8.2+, Laravel 12, Sanctum (API tokens), Socialite (Google OAuth), Spatie Permission (roles), Pest (testing), MySQL, AWS S3 (file storage), Resend (email).

## Architecture

### Service/Contract Pattern

Every domain has a Contract (interface) and a Service (implementation), bound in `AppServiceProvider::$bindings`. Controllers inject contracts, never concrete services.

```
Controller → Contract (interface) → Service (implementation)
```

Contracts: `app/Contracts/` — 8 interfaces
Services: `app/Services/` — 10 classes (includes `ApiService` and `StrService` which have no contracts)

Current bindings:
- `AuthServiceContract` → `AuthService`
- `FileUploadServiceContract` → `FileUploadService`
- `PasswordServiceContract` → `PasswordService`
- `PollServiceContract` → `PollService`
- `ProfileServiceContract` → `ProfileService`
- `StatsServiceContract` → `StatsService`
- `UserPollServiceContract` → `UserPollService`
- `VerificationServiceContract` → `VerificationService`

### API Response Format

All responses go through `ApiService` which enforces a consistent JSON structure:

```json
{
  "success": true|false,
  "messages": ["string key or message"],
  "data": { ... }
}
```

Use `ApiService::success($data, $message, $status)` and `ApiService::error($status, $message, $data)`.

### Error Messages

Backend sends **raw string keys** (not translated strings). The frontend handles localization. Example: `'poll_has_expired'` not `__('api.poll_has_expired')`.

### FormRequest Validation

Organized by domain in `app/Http/Requests/`:
- `Polls/` — 7 request classes (Store/Update for Poll, Option, Vote, Reaction)
- `User/` — 12 request classes (auth, profile updates, verification)
- `Violations/` — 3 request classes

The `authorize()` method in FormRequests often checks roles via `$this->user()->hasRole('citizen')`.

### API Resources

Resources in `app/Http/Resources/` transform models for JSON output:
- `UserResource` — handles avatar S3 temporary URLs via `FileUploadServiceContract`
- `PollResource` — includes options, conditional voter preview loading
- `PollOptionResource` — includes `voters_preview` when `latestVoters` relationship is loaded
- `PollVoteResource`, `PollReactionResource`, `UserVerificationResource`, `ViolationResource`

## Models

All in `app/Models/`. Key patterns:

**User** — The central model. Uses `HasApiTokens`, `HasFactory`, `HasRoles`, `Notifiable`, `SoftDeletes`. Routes resolve by `uuid` (not `id`). PII fields (`national_id`, `email`, `phone`) are hashed on create/update into `*_hashed` columns. `address` and `national_id` use Laravel's `encrypted` cast. Relationships: `verifiers`, `activeVerifiers`, `verifications`, `polls`, `votes`, `reactions`, `profileUpdates`, `violations`.

**Poll** — Uses `SoftDeletes`. Has a global scope filtering `is_private = false`. Appends cached `ups_count` and `downs_count` from reactions. `audience` is cast to array. Relationships: `options`, `user` (creator), `reactions`.

**PollOption** — Belongs to `Poll`. Has `votes` and `latestVoters` (hasMany with `take(3)` and constrained user select: `id,uuid,name,surname,avatar`).

**PollVote** — Belongs to `Poll`, `PollOption`, and `User`.

**PollReaction** — Belongs to `Poll` and `User`. Type is `up` or `down`.

**UserVerification** — Represents peer verification. Has `user_id` (verified user) and `verifier_id`.

**ProfileUpdate** — Audit trail for profile changes. Tracks `change_type` (enum), `ip`, timestamps.

**Violation** — User-reported violations with categories, status, and attachments.

## Enums

17 enums in `app/Enums/` covering: `CountryEnum`, `EducationLevelEnum`, `EthnicityEnum`, `GenderEnum`, `HealthStatusEnum`, `HometownEnum`, `IncomeSourceEnum`, `LanguageEnum`, `MaritalStatusEnum`, `ProfileChangeTypeEnum`, `ReligiousAffiliationEnum`, `RevealResultsEnum`, `SysLanguageEnum`, `UserProviderEnum`, `VerificationReasonEnum`, `ViolationCategoryEnum`, `ViolationStatusEnum`.

## Routing

All routes in `routes/api.php`. Key structure:

**Public routes:**
- `GET /ping` — health check
- `GET /stats` — platform statistics (throttled)
- `GET /verify-email` — email verification (signed URL)

**Auth (guest-only, throttled):**
- `POST /users/register`, `/users/login`, `/users/login/social`
- `POST /users/forgot-password`, `/users/reset-password`

**Authenticated (`auth:sanctum`):**
- `GET /users/me` — current user profile
- `POST /users/logout`
- Profile updates: `/users/update/basic-info`, `/update/social`, `/update/avatar`, `/update/address`, `/update/language`, `/update/census`
- Verification: `POST /users/verify` (with `CanVerify` middleware)
- User data: `GET /users/my-polls`, `/my-reactions`, `/my-votes`, `/my-verifications`, `/my-verifiers`

**Polls:**
- `GET /polls` — list (public)
- `GET /polls/{poll}` — show (public, wildcard — must be last)
- `GET /polls/option-voters` — paginated voters (auth)
- `POST /polls` — create (auth)
- `POST /polls/vote` — vote (auth + `UserIsVerified`)
- `POST /polls/react` — react (auth + `UserIsVerified`)

**Violations:**
- `GET /violations`, `GET /violations/{violation}` — public
- `POST /violations`, `POST /violations/react`, `POST /violations/attachments` — auth

**Important:** The `/{poll}` wildcard route must be defined **after** all specific poll routes (like `/option-voters`) to avoid swallowing them.

## Middleware

Custom middleware in `app/Http/Middleware/`:
- **SetAppLocalization** — reads `Accept-Language` header, validates against `config('e-syrians.locales')` (en, ar, ku), falls back to default
- **UserIsVerified** — checks `$user->verified_at`, returns 403 with `'you_are_not_verified'` if not
- **CanVerify** — calls `$user->canVerify()`, checks ban status, verification count limits, and ratio thresholds
- **Recaptcha** — validates reCAPTCHA token from request, returns 422 on failure

## Custom Exceptions

In `app/Exceptions/`:
- `PollVotingException` — thrown during vote validation (expired poll, already voted, not in audience, max selections). Defaults to HTTP 400.
- `PollReactionException` — thrown during reaction validation
- `UpdateLimitReachedException` — thrown when profile update limits exceeded

## Authentication

- **Sanctum API tokens** — bearer token auth for all protected routes
- **Google OAuth** — via Socialite, registered in `AppServiceProvider::boot()` event listener
- **Email verification** — signed temporary URLs, configurable expiry, redirects to frontend
- **Password reset** — generates frontend URL with token
- Roles managed by Spatie Permission. Key role: `citizen` (required for poll creation).

## Verification System

Peer-to-peer identity verification with configurable thresholds in `config/e-syrians.php`:
- `verification.min` — minimum verifiers needed (currently 1)
- `verification.max` — max verifications a user can make (25)
- `verification.diff` — ratio threshold between received/given verifications (-10)
- `verification.basic_info_updates_limit` — updates before losing verification (2)
- `verification.country_updates_limit` — country changes before losing verification (2)

## PII Handling

Sensitive fields are dual-stored: encrypted for retrieval, hashed for lookups. On `User::creating` and `User::updating`, the `handleHashing()` method auto-populates `*_hashed` columns from source fields (`national_id` → `national_id_hashed`, `email` → `email_hashed`, `phone` → `phone_hashed`). Hashing uses `StrService::hash()`. The `address` and `national_id` fields use Laravel's `encrypted` cast.

## File Storage

AWS S3 for file uploads (avatars, violation attachments). The `FileUploadService` handles uploads and generates temporary signed URLs. Avatar temporary URL TTL is configured at `config('e-syrians.files.avatar.ttl')` (7 days). Resources like `UserResource` and `PollOptionResource` use `FileUploadServiceContract` to generate avatar URLs.

## Caching

Cache keys defined in `config('e-syrians.cache')` for stats: `daily_registrants`, `gender`, `age`, `hometown`, `country`, `ethnicity`, `religion`. Poll reaction counts (`ups_count`, `downs_count`) are cached on the Poll model.

## Testing

Uses **Pest** framework. Structure:
- `tests/Feature/` — AuthRoutesTest, CitizenRegistrationTest, PasswordRoutesTest, PollRoutesTest, UserAccountTest, UserPollRoutesTest, VerificationTest
- `tests/Unit/Services/` — AuthServiceTest, PasswordServiceTest, PollServiceTest, ProfileServiceTest, VerificationServiceTest
- `tests/Unit/` — ArabicDigitsConversionTest, ArchTest

`tests/Pest.php` extends `Tests\TestCase` for both Feature and Unit tests. Seeders (`RolesPermissionsSeeder`) run automatically in test setup for role-dependent tests.

Run tests: `php artisan test` or `./vendor/bin/pest`

## Localization

Three languages: English (`en`), Arabic (`ar`), Kurdish (`ku`). Lang files at `lang/{locale}/` with standard Laravel files (auth, passwords, validation, pagination). Locale is set per-request via `Accept-Language` header through `SetAppLocalization` middleware.

## Development Commands

```bash
# Start all services (server, queue, logs, vite)
composer dev

# Run tests
php artisan test

# Lint/format
./vendor/bin/pint

# Fresh migration with seeding
php artisan migrate:fresh --seed
```

## Key Conventions

1. All files use `declare(strict_types=1)`
2. User model resolves routes by `uuid`, not `id`
3. Error messages are raw string keys, never `__()` translated
4. FormRequests handle both validation and authorization
5. Controllers stay thin — business logic lives in services
6. Relationships that expose user data should constrain select to: `id,uuid,name,surname,avatar`
7. Poll wildcard route `/{poll}` must always be the last route in its group
8. Throttle rates are set per-route with named limiters (e.g., `throttle:6,1,register`)
