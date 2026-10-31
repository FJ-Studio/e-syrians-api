# Sign in with Apple: account-deletion revocation

## Deployment

Deploy the API, Next.js and mobile changes together. Apple login now requires
`authorization_code` alongside the signed identity `token`; Google is unchanged.

1. Set server-only `APPLE_TEAM_ID`, `APPLE_KEY_ID`, and either `APPLE_PRIVATE_KEY`
   (the Sign in with Apple `.p8` contents) or `APPLE_PRIVATE_KEY_PATH` (an absolute
   path to a readable key file outside the repository, e.g.
   `/run/secrets/apple-sign-in.p8`). Inline key text takes precedence; literal
   `\n` escapes or real newlines work. A file-backed key must remain available
   to both the API and queue-worker users. Restrict filesystem access, keep a
   secure backup, and never commit the `.p8` file or its contents to Git.
   Secrets are signed on demand separately for each configured client ID, so
   there is no manually generated JWT to expire during the deletion grace period.
2. Keep `APPLE_CLIENT_ID` equal to the native bundle ID and `APPLE_WEB_CLIENT_ID`
   equal to the web Services ID. The key must authorize the associated primary
   App ID. Set `APPLE_REDIRECT_URI` to the exact web popup redirect URI, currently
   `https://www.e-syrians.com` (no trailing slash). Register it with Apple.
3. Run the new migration (`php artisan migrate --force`) during deployment,
   refresh cached configuration and restart long-running workers/Octane.
   Do not run migrations against an unintended database.
4. Keep `APP_KEY` secure and stable: refresh tokens use Laravel's encrypted cast.
   Preserve previous encryption keys when rotating it.
5. Run the daily scheduler/queue for `HardDeleteExpiredAccountsJob` and alert on
   its errors. Revocation failures retain the account and encrypted token; the
   next daily sweep retries. Apple returns 200 for already-invalid tokens too.

## Behavior

The server verifies both identity tokens (the client token and Apple's token
exchange response) and requires matching subject/client ID before retaining the
refresh token. A client cannot supply the audience used for exchange. Tokens
are hidden from model serialization and are never returned to clients. Native
and web grants are stored separately; every distinct refresh token is retained,
including repeat logins, rather than assuming that revoking the newest token
also revokes all older sessions. All are revoked at permanent deletion,
before local data is removed. Scheduling/cancelling deletion does not revoke them.

Do not enable raw request/response body logging for Apple's `/auth/token` or
`/auth/revoke`, or for `/users/login/social`. Redact `authorization_code`,
`refresh_token`, `client_secret`, and `token` in any proxy/APM tooling.

## Existing accounts

Apple grants created before this change cannot be reconstructed from an identity
token. Their next successful Apple sign-in saves a revocable grant automatically.
**Do not prevent account deletion just because a legacy grant is missing.** The
deletion service logs this condition without tokens and still deletes local data.
Both deletion screens include a note directing affected users to Apple Account
settings. Until all existing Apple users have signed in again, direct legacy users to
[stop using Sign in with Apple for E-Syrians](https://support.apple.com/102571)
after permanent deletion. This fallback is not a substitute for collecting codes
on every new Apple sign-in.

## Verification before store submission

- Configure the real server credentials before deploying the API requirement.
- Use a dedicated test Apple account, not a real user's account.
- Check native first/repeat sign-in, web popup sign-in and pending-account login.
- Verify the database token is encrypted and absent from API responses/logs.
- Schedule and cancel deletion: the Apple grant remains usable.
- In a controlled non-production test, expire the grace period and run the
  deletion job: Apple authorization and local data are removed.
- Simulate Apple failure: account/token remain and deletion succeeds on retry.

Automated tests use locally signed fake identity tokens and HTTP fakes only;
they do not revoke real Apple authorizations. This change does not implement
Apple's separate server-to-server account-change notification endpoint.

References: [token validation](https://developer.apple.com/documentation/signinwithapplerestapi/generate-and-validate-tokens),
[token revocation](https://developer.apple.com/documentation/signinwithapplerestapi/revoke-tokens),
[TN3194](https://developer.apple.com/documentation/technotes/tn3194-handling-account-deletions-and-revoking-tokens-for-sign-in-with-apple).
