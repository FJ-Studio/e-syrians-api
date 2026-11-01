# Notification delivery feature flags

Admins manage the API's notification policy in **Filament → Settings → Notification
delivery**. Every supported event has separate **In app** and **Push** switches.
Verification received and Account becomes verified also have an **Email** switch
for the existing optional verification emails. No email option is exposed for
events that do not have an email implementation.
The 16 events are registered in `App\Enums\NotificationEvent` and grouped into the
same seven categories used for account preferences.

## Policy and defaults

Flags use the existing database-backed Laravel Pennant store and the explicit
`global` scope, never the signed-in admin or notification recipient. Names follow
`notifications.<event>.<channel>`, for example:

- `notifications.verification_received.in_app`
- `notifications.verification_received.push`
- `notifications.security_password_changed.push`

All flags initially allow the existing routing. This does **not** enable Expo push
transport, grant user consent, or change announcement opt-in defaults.

In-app delivery requires the event's In-app flag **and** the account's category
preference. Push requires the event's Push flag, the account's category preference,
an active opted-in device, and the global Pennant `expo-push` flag. Keep that flag
**off in production** until credentials and physical-device tests pass. The
Notification delivery page shows its current state and links to **Settings →
Feature flags**, where administrators can change it without editing environment
variables. Legacy environment values are imported only when initializing missing
flags; they do not override saved administrator choices.

Authentication OTPs, password-recovery emails and other transactional mail are
outside this notification matrix. Security Activity is always stored independently
of delivery flags and user preferences.

The two optional verification email preferences are included as `email` booleans
in `GET /users/notification-preferences` under `verifications` and `account_status`.
The owner-bound PATCH endpoint accepts `channel: email` only for these categories.
It updates the existing email columns atomically without resetting other channels.
Older clients using the legacy email endpoint stay compatible. Optional email
delivery requires both the user's email preference and the global event/email flag.

## Timing and queued work

Flags are checked when an event is delivered. The per-recipient delivery ledger
records suppressed channels too, so retrying the same event after re-enabling a
flag cannot replay it. Existing inbox entries and previously granted announcement
access remain; changing a flag does not retract delivered notifications.

Queued pushes carry their exact event as internal metadata, not in the OS-visible
payload. The worker refreshes Pennant state and checks the flag again immediately
before delivery. Disabled jobs complete without sending, and later re-enabling a
flag does not resurrect them. Old queued pushes without an exact event fail closed
instead of guessing which policy applies. Receipt-triggered resends pass through
the same send job and policy check.

Pennant clears its cache between Octane requests and completed worker jobs. Both
fanout and push jobs also refresh state explicitly, including retries after failed
jobs. A currently running fanout can finish its batch using the flags it already read;
every queued push still checks fresh state at execution. Flags cannot recall a
request already accepted by Expo.

## Deployment

This reuses the existing Pennant `features` table and adds no dependency. Refresh
Laravel's cached configuration and run migrations: the platform feature flags have
a one-time initialization migration that preserves existing switch values. Reload
Octane and restart Horizon after deploying the new code. Configure the event/channel
switches in Filament while keeping the `expo-push` feature off until device tests pass.

See [the environment/feature-flag audit](environment-feature-flag-audit.md) for
upgrade defaults and the new administration page. Later feature-flag changes do
not require a restart or deployment.
