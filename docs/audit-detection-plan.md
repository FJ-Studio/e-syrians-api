# Profile Audit & Fraud Detection — Implementation Plan

## Problem

Users can manipulate their profile data (country, ethnicity, gender, etc.) to become eligible for polls they don't genuinely belong to. Some may use tools like Postman to bypass the frontend. We need to detect and flag this behavior.

## Current State (what already exists)

**Profile update limits** (correctly implemented in `ProfileService.php`):

- Basic info (name, surname, birth_date, gender, ethnicity, hometown, national_id): max **2 updates total** — enforced via `getTotalUpdatesCount(BasicData)` against `config('e-syrians.verification.basic_info_updates_limit')`
- Address (country, city_inside_syria): max **2 updates per 365 days** — enforced via `getAddressUpdatesCount()` which filters `created_at >= now()->subYear()`
- Social links: max **5 updates total**

**Existing audit table** (`profile_updates`):

- Stores: `user_id`, `change_type` (enum), `meta_data` (JSON, only for address changes), `ip_address`, `user_agent`
- **Gap**: does NOT store old/new values for all fields — only stores country/city in `meta_data` for address changes

---

## Architecture Overview

```
┌──────────────┐       ┌──────────────┐       ┌──────────────────┐
│  Laravel API │──────▶│   BigQuery   │──────▶│  Cloud Function  │
│              │       │              │       │  (Analysis)      │
│ Log every    │       │ Permanent    │       │                  │
│ profile      │       │ audit store  │       │ Scheduled daily  │
│ change with  │       │              │       │ or on-demand     │
│ old/new vals │       │              │       │                  │
└──────┬───────┘       └──────────────┘       └────────┬─────────┘
       │                                               │
       │  30-day local                                  │ writes back
       │  retention                                     ▼
       │                                        ┌──────────────┐
       └───────────────────────────────────────▶│   Laravel DB │
                                                │              │
                                                │ suspicious_  │
                                                │ activities   │
                                                │ table        │
                                                └──────┬───────┘
                                                       │
                                                       ▼
                                                ┌──────────────┐
                                                │ Admin email  │
                                                │ notification │
                                                └──────────────┘
```

---

## Phase 1 — Enhanced Audit Logging (Laravel)

### 1.1 Expand `profile_updates` table

Add columns to capture old and new values for every change:

| Column | Type | Purpose |
|--------|------|---------|
| `changes` | JSON | `{"field": {"old": "...", "new": "..."}, ...}` for every changed field |
| `request_source` | varchar | `"web"`, `"api"`, `"mobile"` — detected from User-Agent / request headers |
| `session_id` | varchar (nullable) | Sanctum token ID, helps correlate rapid changes |

The existing `meta_data` column (used only for address) becomes redundant once `changes` is populated — but keep it for backward compatibility.

### 1.2 Update `ProfileService` to capture old/new values

For every update method (`updateBasicInfo`, `updateAddress`, `updateCensus`):

```php
// Before applying changes, snapshot old values
$oldValues = $user->only(array_keys($validated));

// Apply changes
$user->update($validated);

// Build changes map
$changes = [];
foreach ($validated as $field => $newValue) {
    if ($oldValues[$field] !== $newValue) {
        $changes[$field] = [
            'old' => $oldValues[$field],
            'new' => $newValue,
        ];
    }
}

// Store audit record with full diff
ProfileUpdate::create([
    'user_id'        => $user->id,
    'change_type'    => $changeType,
    'changes'        => $changes,
    'ip_address'     => $request->ip(),
    'user_agent'     => $request->userAgent(),
    'request_source' => $this->detectSource($request),
    'session_id'     => $request->user()?->currentAccessToken()?->id,
]);
```

### 1.3 Log failed/blocked attempts too

When a user hits the update limit, still log the attempt:

```php
// In the exception handler or before throwing
ProfileUpdate::create([
    'user_id'        => $user->id,
    'change_type'    => $changeType,
    'changes'        => $attemptedChanges,
    'ip_address'     => $request->ip(),
    'user_agent'     => $request->userAgent(),
    'request_source' => $this->detectSource($request),
    'blocked'        => true,      // new boolean column
    'block_reason'   => 'limit_reached',
]);
```

This captures Postman/API abuse attempts that would otherwise be invisible.

### 1.4 Detect request source

```php
private function detectSource(Request $request): string
{
    $ua = strtolower($request->userAgent() ?? '');

    if (str_contains($ua, 'postman') || str_contains($ua, 'insomnia') || str_contains($ua, 'httpie')) {
        return 'api_tool';
    }

    if ($request->hasHeader('X-Mobile-App')) {
        return 'mobile';
    }

    return 'web';
}
```

---

## Phase 2 — BigQuery Integration

### 2.1 Setup

- **Google Cloud project**: Create or use existing project
- **Dataset**: `e_syrians_audit`
- **Service account**: Create with BigQuery Data Editor role, download JSON key
- **Composer**: `google/cloud-bigquery` ^1.30

### 2.2 Configuration

Add to `.env`:

```
BIGQUERY_ENABLED=false
BIGQUERY_PROJECT_ID=""
BIGQUERY_DATASET="e_syrians_audit"
BIGQUERY_CREDENTIALS=""
BIGQUERY_TABLE_PROFILE_CHANGES="profile_changes"
BIGQUERY_TABLE_POLL_VOTES="poll_votes"
BIGQUERY_TABLE_POLL_AUDIENCE_RULES="poll_audience_rules"
BIGQUERY_TABLE_SUSPICIOUS_ACTIVITIES="suspicious_activities"

ADMIN_NOTIFICATION_EMAIL=""
```

Add to `config/services.php`:

```php
'bigquery' => [
    'enabled'    => env('BIGQUERY_ENABLED', false),
    'project_id' => env('BIGQUERY_PROJECT_ID'),
    'dataset'    => env('BIGQUERY_DATASET', 'e_syrians_audit'),
    'credentials' => env('BIGQUERY_CREDENTIALS'),
    'tables' => [
        'profile_changes'       => env('BIGQUERY_TABLE_PROFILE_CHANGES', 'profile_changes'),
        'poll_votes'            => env('BIGQUERY_TABLE_POLL_VOTES', 'poll_votes'),
        'poll_audience_rules'   => env('BIGQUERY_TABLE_POLL_AUDIENCE_RULES', 'poll_audience_rules'),
        'suspicious_activities' => env('BIGQUERY_TABLE_SUSPICIOUS_ACTIVITIES', 'suspicious_activities'),
    ],
],
```

Add to `config/e-syrians.php`:

```php
'admin_notification_email' => env('ADMIN_NOTIFICATION_EMAIL'),
```

### 2.3 BigQuery table schema — `profile_changes`

| Column | Type | Description |
|--------|------|-------------|
| `event_id` | STRING | UUID |
| `user_id` | INTEGER | |
| `change_type` | STRING | basic_data, address, regular, social, avatar |
| `field_name` | STRING | Individual field (one row per field changed) |
| `old_value` | STRING | Previous value |
| `new_value` | STRING | New value |
| `ip_address` | STRING | |
| `user_agent` | STRING | |
| `request_source` | STRING | web, mobile, api_tool |
| `blocked` | BOOLEAN | Was this attempt blocked? |
| `block_reason` | STRING (nullable) | Why it was blocked |
| `occurred_at` | TIMESTAMP | |

Denormalized (one row per field changed) for easier querying in BigQuery.

### 2.4 BigQueryService

Singleton service following Found Here's pattern:

```php
class BigQueryService
{
    private ?BigQueryClient $client = null;

    public function insert(string $table, array $data): void
    {
        if (!config('services.bigquery.enabled')) return;

        try {
            $dataset = $this->getClient()->dataset(config('services.bigquery.dataset'));
            $table = $dataset->table(config("services.bigquery.tables.{$table}"));
            $table->insertRows([['data' => $data]]);
        } catch (\Throwable $e) {
            Log::warning('BigQuery insert failed', ['error' => $e->getMessage()]);
        }
    }
}
```

### 2.5 Async dispatch

Use a queued job to avoid blocking the request:

```php
// Dispatched from ProfileService after creating the local audit record
LogProfileChangeToBigQuery::dispatch($profileUpdate);
```

### 2.6 Local retention

Add Laravel's `Prunable` trait to `ProfileUpdate` — delete local records older than 30 days. BigQuery holds the permanent copy.

---

## Phase 3 — Cloud Function (Detection Engine)

### 3.1 Runtime & trigger

- **Runtime**: Python 3.12 (Cloud Functions 2nd gen)
- **Trigger**: Cloud Scheduler — runs every 2 hours

### 3.2 Detection rules

The Cloud Function queries BigQuery and flags suspicious patterns:

**Rule 1 — Profile flip-flopping**
User changes a field to value A, then to B, then back to A within a short window. Suggests gaming audience criteria.

```sql
SELECT user_id, field_name, COUNT(DISTINCT new_value) as distinct_values
FROM profile_changes
WHERE occurred_at >= TIMESTAMP_SUB(CURRENT_TIMESTAMP(), INTERVAL 90 DAY)
  AND field_name IN ('country', 'ethnicity', 'gender', 'hometown', 'city_inside_syria')
  AND blocked = FALSE
GROUP BY user_id, field_name
HAVING distinct_values >= 3
```

**Rule 2 — Change before vote pattern**
User changes an audience-relevant field shortly before or after voting on a poll that targets that field.

```sql
-- This requires joining with poll_votes and poll_audience_rules
-- Pseudocode: find users who changed field X within 24h of voting on a poll that filters by X
```

**Rule 3 — API tool usage**
Any profile change with `request_source = 'api_tool'` is inherently suspicious.

```sql
SELECT user_id, COUNT(*) as api_changes
FROM profile_changes
WHERE request_source = 'api_tool'
  AND occurred_at >= TIMESTAMP_SUB(CURRENT_TIMESTAMP(), INTERVAL 30 DAY)
GROUP BY user_id
HAVING api_changes >= 1
```

**Rule 4 — Blocked attempt frequency**
User repeatedly hits update limits, suggesting they're trying to brute-force changes.

```sql
SELECT user_id, COUNT(*) as blocked_attempts
FROM profile_changes
WHERE blocked = TRUE
  AND occurred_at >= TIMESTAMP_SUB(CURRENT_TIMESTAMP(), INTERVAL 30 DAY)
GROUP BY user_id
HAVING blocked_attempts >= 3
```

**Rule 5 — Rapid succession changes**
Multiple profile updates from different IPs within a short time frame.

```sql
SELECT user_id, COUNT(DISTINCT ip_address) as unique_ips
FROM profile_changes
WHERE occurred_at >= TIMESTAMP_SUB(CURRENT_TIMESTAMP(), INTERVAL 7 DAY)
  AND blocked = FALSE
GROUP BY user_id
HAVING unique_ips >= 3
```

### 3.3 Severity scoring

Each rule produces a severity score. The function sums scores per user:

| Rule | Score |
|------|-------|
| Profile flip-flopping | 30 |
| Change-before-vote | 50 |
| API tool usage | 20 |
| Blocked attempts (3+) | 15 |
| Rapid IP changes | 25 |

**Thresholds**:
- Score 20–39: `low` — log only
- Score 40–69: `medium` — flag in DB
- Score 70+: `high` — flag in DB + email admin

### 3.4 Output

The Cloud Function writes results to two places:

**a) BigQuery table `suspicious_activities`:**

| Column | Type |
|--------|------|
| `user_id` | INTEGER |
| `detection_run` | TIMESTAMP |
| `rules_triggered` | JSON (array of rule names + individual scores) |
| `total_score` | INTEGER |
| `severity` | STRING (low/medium/high) |
| `evidence` | JSON (sample data supporting the detection) |

**b) Laravel API endpoint (webhook):**

The function calls a secured internal endpoint on the Laravel API:

```
POST /api/internal/suspicious-activity
Authorization: Bearer {INTERNAL_API_KEY}

{
  "user_id": 123,
  "severity": "high",
  "score": 75,
  "rules": ["profile_flip_flop", "change_before_vote"],
  "evidence": { ... },
  "detected_at": "2026-04-18T00:00:00Z"
}
```

---

## Phase 4 — Laravel: Flagging & Notifications

### 4.1 New `suspicious_activities` table

| Column | Type |
|--------|------|
| `id` | bigint (PK) |
| `user_id` | FK to users |
| `severity` | enum: low, medium, high |
| `score` | integer |
| `rules_triggered` | JSON |
| `evidence` | JSON |
| `status` | enum: pending, reviewed, dismissed, confirmed |
| `reviewed_by` | FK to users (nullable, admin who reviewed) |
| `reviewed_at` | timestamp (nullable) |
| `notes` | text (nullable, admin notes) |
| `detected_at` | timestamp |
| `created_at` / `updated_at` | timestamps |

### 4.2 Internal webhook controller

Secured with a shared secret (`INTERNAL_API_KEY` in `.env`):

```php
Route::post('/api/internal/suspicious-activity', [SuspiciousActivityController::class, 'store'])
    ->middleware('internal-api');
```

### 4.3 Admin email notification

When severity is `medium` or `high`, send an email to `config('e-syrians.admin_notification_email')`:

```
Subject: [e-syrians] Suspicious activity detected — User #123 (severity: high)

User: Ahmad Al-... (user@email.com)
Score: 75/100
Rules triggered:
  - Profile flip-flopping (country changed 4 times in 90 days)
  - Changed country 2h before voting on poll #456 targeting Turkey residents

Evidence summary attached.

Review: https://admin.e-syrians.com/users/123/suspicious-activities
```

### 4.4 Admin panel integration (future)

Expose `suspicious_activities` via an API resource so the admin dashboard can show a review queue with approve/dismiss actions.

---

## Implementation Order

| Step | Scope | Effort |
|------|-------|--------|
| **1** | Migration: add `changes`, `request_source`, `session_id`, `blocked`, `block_reason` to `profile_updates` | Small |
| **2** | Update `ProfileService` to capture old/new values + log blocked attempts | Medium |
| **3** | Add `ADMIN_NOTIFICATION_EMAIL` to `.env` and `config/e-syrians.php` | Small |
| **4** | Set up BigQuery project, dataset, service account | Small (infra) |
| **5** | Add `BigQueryService` + `LogProfileChangeToBigQuery` job | Medium |
| **6** | Add `LogPollVoteToBigQuery` job + mirror `poll_audience_rules` on sync | Medium |
| **7** | Create `suspicious_activities` table + model | Small |
| **8** | Build Cloud Function with 5 detection rules (runs every 2h via Cloud Scheduler) | Large |
| **9** | Internal webhook endpoint + admin email notification (using `config('e-syrians.admin_notification_email')`) | Medium |
| **10** | Add `Prunable` trait to `ProfileUpdate` (30-day local retention) | Small |
| **11** | Admin panel UI for reviewing flagged users | Medium (frontend) |

Steps 1–3 can ship immediately — they improve audit quality even before BigQuery is connected. Steps 4–6 are the BigQuery foundation including poll vote mirroring for Rule 2. Steps 7–9 build the detection and alerting loop. Steps 10–11 are cleanup and UI.

---

## Decisions

1. **Poll votes**: Mirror `poll_votes` and `poll_audience_rules` to BigQuery so the Cloud Function can run Rule 2 (change-before-vote detection) without direct DB access.
2. **Admin email**: Defined as `ADMIN_NOTIFICATION_EMAIL` in `.env`, mapped via `config('e-syrians.admin_notification_email')`.
3. **Detection frequency**: Cloud Scheduler runs every 2 hours.
4. **Consequences**: Detection and reporting only for now. No automated suspension or vote nullification — that's a future decision.

---

## Additional BigQuery Tables

### `poll_votes`

| Column | Type | Description |
|--------|------|-------------|
| `event_id` | STRING | UUID |
| `user_id` | INTEGER | |
| `poll_id` | INTEGER | |
| `option_id` | INTEGER | |
| `ip_address` | STRING | |
| `user_agent` | STRING | |
| `occurred_at` | TIMESTAMP | |

### `poll_audience_rules`

| Column | Type | Description |
|--------|------|-------------|
| `poll_id` | INTEGER | |
| `criterion` | STRING | gender, country, ethnicity, etc. |
| `value` | STRING | The required value |
| `synced_at` | TIMESTAMP | When this row was mirrored |

These tables enable Rule 2 by joining profile changes with votes and audience criteria:

```sql
-- Rule 2: User changed a field that matches a poll's audience criterion
-- within 24h before or after voting on that poll
SELECT
  pc.user_id,
  pc.field_name,
  pc.old_value,
  pc.new_value,
  pc.occurred_at AS change_time,
  pv.poll_id,
  pv.occurred_at AS vote_time,
  par.criterion,
  par.value AS required_value
FROM profile_changes pc
JOIN poll_votes pv
  ON pc.user_id = pv.user_id
  AND ABS(TIMESTAMP_DIFF(pv.occurred_at, pc.occurred_at, HOUR)) <= 24
JOIN poll_audience_rules par
  ON pv.poll_id = par.poll_id
  AND pc.field_name = par.criterion
WHERE pc.blocked = FALSE
  AND pc.occurred_at >= TIMESTAMP_SUB(CURRENT_TIMESTAMP(), INTERVAL 30 DAY)
```
