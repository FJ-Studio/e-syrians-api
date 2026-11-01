# Environment configuration and runtime feature flags

## Audit result

Reviewed the API `.env.example`, application/configuration consumers, and the web
and mobile environment templates. Three API environment switches are appropriate
for runtime Pennant controls. Everything else is connection/credential setup,
deployment configuration, security policy, or a nonboolean application setting.

| Previous variable | Global Pennant flag | Filament control |
| --- | --- | --- |
| `EXPO_PUSH_ENABLED` | `expo-push` | Push notification delivery |
| `NOTIFICATION_EVENTS_ENABLED` | `notification-events` | Automatic notification generation |
| `BIGQUERY_ENABLED` | `bigquery-export` | BigQuery analytics export |
| Already Pennant-managed | `crypto-donations` | Cryptocurrency donations |

All four are available at **Settings → Feature flags**, for administrators only.
Saving requires confirmation. This is a whitelist, not an editor for arbitrary
environment variables or Pennant records. Stale admin tabs are rejected rather
than silently overwriting a newer saved state from another session.

## What the flags mean

- **Push delivery:** the global Expo transport switch, checked by both send and
  receipt jobs from fresh Pennant state. Per-event/channel settings, account
  preferences, active registrations and explicit device consent still apply.
  Turning this on does not configure APNs/FCM credentials or request OS permission.
- **Automatic notification generation:** preserves the former environment switch's
  scope: observers, security-alert generation and scheduled poll fanout. It does
  not erase Security Activity, cancel already-queued alerts, or stop manual
  announcements. Polls due while generation is paused can be processed after it
  resumes. Use the delivery flags to suppress queued delivery.
- **BigQuery export:** controls the existing audit export pipeline. Queued export
  jobs recheck it at execution. Export can contain profile history, votes and IP
  addresses; enable only after reviewing the dataset and access policy. The page
  blocks new activation when the BigQuery client package, project or dataset is
  missing. Credentials or application-default authentication remain server setup.
  This change does not install a package or activate Google Cloud services.
- **Crypto donations:** the same global flag already used by the Donations page.
  At least one wallet must be configured there before activation from this page.
  The wallet editor and individual notification controls remain on their dedicated
  settings pages.

## Upgrade without losing existing states

The migration initializes missing global Pennant records using the existing three
boolean environment values. Existing records, including administrator changes and
the crypto-donations flag, are never overwritten. First resolution also persists
the legacy initial value, so requests during a rolling deployment are consistent.
Initialization also understands the previous release's cached configuration, so
an old config cache does not discard the existing switch values during upgrade.

After a flag has been stored, environment values are no longer consulted for that
flag. For example, enabling `expo-push` in Filament works even if the old
`EXPO_PUSH_ENABLED=false` line remains. The legacy entries remain documented solely
for upgrades; they are not ongoing kill switches. Flag states are retained on
migration rollback.

Refresh cached Laravel configuration, run migrations and reload Octane/Horizon
once when deploying this code change. Subsequent admin flag changes do not require
a configuration refresh, process restart or deployment. An in-flight request can
finish with the state it already read, and accepted Expo requests cannot be recalled.

## Keep these in environment/server configuration

| Configuration | Reason |
| --- | --- |
| `APP_KEY`, database/Redis/mail credentials, OAuth/Apple keys, `EXPO_PUSH_ACCESS_TOKEN`, `INTERNAL_API_KEY`, storage keys, BigQuery credentials | Secrets and service identity, not product features. Never expose them in Filament. |
| `APP_ENV`, `APP_DEBUG`, maintenance driver, logging settings, `NIGHTWATCH_ENABLED`/token | Deployment and operational monitoring; not safe product switches or reliably dynamic across long-running agents. |
| Session encryption/cookie/security settings, `OCTANE_HTTPS`, database foreign-key checks, TLS/endpoint options | Security/integrity and protocol configuration; not admin-toggleable features. |
| reCAPTCHA keys/project and `RECAPTCHA_MIN_SCORE`, `BCRYPT_ROUNDS`, Horizon access allowlist | Security policy and access control. No disable/bypass switch is added. |
| URLs/domains, locale/timezone, queue/cache/filesystem/mail drivers, server workers, network ports, service project/dataset names | Installation/environment settings or typed values, not boolean features. |
| Web Stripe/auth/API/public SDK configuration; mobile Firebase file, OAuth IDs, Sentry/Apple configuration | Credential/integration or native/build-time setup. The API flags enforce delivery for both clients without changing their bundles. |

`NIGHTWATCH_ENABLED` is not migrated: it configures observability at process startup
and may involve a separate agent. It remains outside the feature page. No controls
are added for authentication, reCAPTCHA, account-deletion retention, audit storage,
or logging/monitoring protections.

Push and BigQuery are not enabled by this implementation or by saving unrelated
flags. On a fresh installation their defaults remain off; automatic generation
defaults on, and crypto donations remain off until configured and explicitly enabled.
