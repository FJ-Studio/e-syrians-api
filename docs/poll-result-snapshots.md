# Poll lifecycle: final-result snapshots

## Scope of this review

This change freezes aggregate results when a poll reaches its scheduled end.
It does not implement account-deletion retention, deleted-user placeholders,
client UI changes, or deletion of individual ballots.

The existing Close/Reopen action is a reversible soft-delete/restore operation.
Until a separate final-close policy is approved, it remains reversible and does
not finalize an otherwise active poll. Hidden and private polls are still
captured once their scheduled end is reached, without changing their visibility.

## Capture

`polls:finalize-results` runs independently every minute via the scheduler, with
overlap protection. It processes missing snapshots in chunks of 100 and locks
each poll inside its own transaction. Voting and editing use the same poll lock.
Eligibility is checked again under that lock; a future deadline is not finalized.
At the exact deadline, voting is rejected and capture is eligible.

There is one snapshot per poll, enforced by a unique database constraint. The
service returns an existing snapshot unchanged on retries. Application-level
updates/deletes of a snapshot are rejected. A failure is reported, rolls back
that poll's capture and does not prevent other polls from being processed. A
later scheduler run retries the missing snapshot.

The payload contains the question, scheduled dates, maximum selections, total
selections, distinct participant count, option IDs/text/creation dates, option
counts and percentages. It contains no voter IDs, names, avatars, IP addresses,
user agents or author profiles. Option IDs are content identifiers, not user IDs.
Percentages retain the current API formula: option selections divided by total
selections, rounded to two decimal places. A multi-select ballot counts once in
`unique_voters_count` but contributes multiple selections to `total_votes`.

## Reads and lifecycle guards

Poll detail, public listings and owner listings eagerly load the snapshot. When
present, their question, dates, options and result totals come from that snapshot,
even if live ballot/option rows later change or disappear. Existing `votes_count`
alias semantics are preserved: owner listings use total selections, while public
queries use distinct participants. `results_finalized_at` is an additive capture
timestamp, or null if the snapshot has not yet been created.

GET requests do not create snapshots. Between expiry and successful scheduled
capture, existing live result behavior remains; `results_finalized_at` is null.
Existing audience, private-poll and result-reveal rules still apply. Snapshot
percentages are not exposed where the current reveal policy hides percentages.
Reaction totals, voter lists and the viewer's personal vote history remain live
in this review; their later retention/presentation policy is separate.

Expired/finalized polls cannot be edited or receive new votes/reactions through
the poll service, including if the live deadline is moved after finalization.
No endpoint edits or replaces a final snapshot. A future reopen/recount policy
would require an explicit separately reviewed design.

## Migration and historical polls

The new migration only creates the snapshot table; it does not capture results,
delete ballots or change existing polls. Apply the schema before running this
code against a database. The scheduler must actually run for finalization to occur.

Before any approved rollout, preview the historical cohort with
`php artisan polls:finalize-results --dry-run`. The first real execution will also
capture already-expired polls from their currently available data. It cannot
reconstruct votes removed before capture; `closed_at` records scheduled expiry
and `captured_at` records the actual capture time, so a late capture is not
misrepresented as an on-time historical result.

No production command, migration or backfill is part of this local review.
Once snapshots are authoritative and source ballots have been removed under a
later retention policy, dropping this table would lose those historical results.
Treat snapshot backups and rollback planning accordingly.

Snapshots currently belong to their poll and cascade only on physical poll
removal. Existing account-deletion code is unchanged and still removes authored
polls. Consequently, this first step alone is not the completed deletion policy;
preservation of historical polls during account deletion remains a later review.
