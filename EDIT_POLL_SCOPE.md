# Editable Polls — Scoping Document

**Status:** Draft for review · No code yet
**Owners:** Backend (PollController/PollService/PollResource) + Mobile (`app/(tabs)/polls/[id].tsx`) + Web (`/polls/[id]` page, `create-poll.tsx` form)
**Tracking task:** #113

---

## 1. Goal (verbatim from user)

> "We want to support [editing polls] but only if the poll did not receive any vote. After receiving the first vote, the poll will no longer be editable."

---

## 2. Decisions already locked in

These were agreed in earlier prompts:

| Decision | Value | Rationale |
|---|---|---|
| **Lock trigger** | First row in `poll_votes` for the poll | Reactions (up/down) and audience-added options do NOT lock. Most literal reading of the user's quote. |
| **Edit UI entry point (mobile)** | Menu / pencil on `app/(tabs)/polls/[id].tsx`, visible only to creator AND only while `is_editable === true` | No new route on mobile, keeps the create flow elsewhere reusable later. |
| **Build order** | This scoping doc → user approval → backend → mobile → web | Avoid the API-vs-UI drift we hit on P3.5/P3.7. |

---

## 3. Open product decisions (need your call)

### 3.1 Which fields are editable while unlocked?

The create-poll FormRequest accepts: `question`, `start_date`, `duration`, `max_selections`, `audience_can_add_options`, `reveal_results`, `voters_are_visible`, `audience_only`, `options[]`, plus all 8 audience-rule axes (`gender[]`, `min_age`, `max_age`, `country[]`, `religious_affiliation[]`, `hometown[]`, `ethnicity[]`, `province[]`, `allowed_voters[]`).

Four levels worth considering:

| Level | Editable | Locked | Notes |
|---|---|---|---|
| **A. Content only** | `question`, `options[]` | Everything else | Smallest surface. Author can fix typos + swap an option, nothing else. |
| **B. Content + schedule** | `question`, `options[]`, `start_date`, `duration` | Audience + visibility + max_selections + reveal_results | Adds "I goofed the schedule." Still safe — schedule doesn't change who can vote. |
| **C. Content + schedule + visibility** | + `voters_are_visible`, `reveal_results`, `max_selections`, `audience_can_add_options` | Audience rules only | Audience stays frozen; everything else can change. |
| **D. Everything (= recreate)** | All create-poll fields | Only immutable identity (`id`, `created_by`, `created_at`) | Functionally equivalent to "delete + recreate, keep URL." |

**Recommended: B.** Audience editability has a privacy edge (an author could narrow the audience post-publish to silence certain voters before they vote — even before anyone has voted, that's a pattern to disallow until we have a clear story). Schedule + content covers ~90% of legit edit reasons (typo, clarification, fix wrong start date). We can promote to C/D later without an API break.

Need your decision on A/B/C/D.

### 3.2 When should `is_editable` flip on the wire?

Server-authoritative. Each `PollResource` response computes `is_editable` from `created_by === auth_user_id && !poll_votes.exists()`. Client treats it as gospel.

Implications:
- Adding a single field to `PollResource`. Free for unauthenticated viewers (they see `is_editable: false`).
- Two cache invalidations needed: the poll-resource cache (already invalidated on vote per `PollService::forgetVoteCaches`) and the audience cache (we'd add a new `forget` on edit).

### 3.3 What about race conditions?

Scenario: user A (creator) opens the edit form. While they're typing, user B casts a vote. User A submits.

**Proposed behavior:** server returns `423 Locked` with body `{code: "poll_received_first_vote", message: "Someone voted while you were editing — this poll can no longer be changed."}`. Mobile shows a translated empty state with a "Back to poll" button; the original poll is unchanged.

No optimistic edit retry, no diff-merge — too complex for the gain. The "window between load and submit" is the same race the user already accepts for poll creation (someone could open the poll just before the start_date).

### 3.4 Audience-rule diffing strategy (only relevant if 3.1 = C or D)

If we ship C or D, audience-rule edits need a diff strategy:
- **Full replace** (recommended): `DELETE FROM poll_audience_rules WHERE poll_id = X` then re-insert the new set inside a transaction. Simple, matches the create-poll insert path, no ordering/identity invariants to track.
- Add/remove diff: identify added vs. removed rules. More code, no measurable win since there's no FK or analytics keyed on `audience_rule.id`.

Pick this only after 3.1 = C/D; skip otherwise.

### 3.5 Options diffing strategy

Even at level A, options need a diff plan because `poll_options` rows are referenced by `poll_votes.poll_option_id`.

For a vote-less poll the FK is empty by definition, so **full replace is safe**: `DELETE FROM poll_options WHERE poll_id = X` then re-insert. This keeps the create-poll insert pattern.

Alternative — preserve unchanged options by ID — is over-engineered for v1 (no analytics keyed on `poll_option.id`, and the immutability invariant ensures no votes refer to the rows we'd delete).

---

## 4. Backend design

### 4.1 Endpoint

```
PUT /polls/{poll}
Auth: sanctum bearer (creator-only)
Middleware: auth:sanctum, recaptcha
Request: { recaptcha_token, ...same shape as POST /polls minus immutable fields per 3.1 }
Responses:
  200: PollResource (with is_editable updated)
  403: not the creator
  423: poll_received_first_vote
  422: validation error (same shape as create)
```

`PUT` chosen over `PATCH` because we re-submit the full editable section (consistent with profile updates we just landed in P3.5).

### 4.2 Lock check

In `PollController::update` (or `EditPollRequest::authorize`):

```php
if ($poll->created_by !== $request->user()->id) {
    abort(403, 'not_poll_creator');
}

if ($poll->votes()->exists()) {
    return ApiService::error(423, 'poll_received_first_vote');
}
```

`exists()` is index-backed on `poll_id` — single-row SELECT, no `count(*)` cost.

### 4.3 New `EditPollRequest`

Subclass of FormRequest. For level B:

```php
public function rules(): array {
    return [
        'question' => ['required', 'string', 'max:255'],
        'start_date' => ['required', 'date'],  // dropped after_or_equal:today — re-scheduling a vote-less poll backward is fine
        'duration' => ['required', 'integer', 'min:1', 'max:365'],
        'options' => ['required', 'array', 'min:2', 'max:100'],
        'options.*' => ['required', 'string', 'max:255'],
    ];
}
```

Note `start_date` drops the `after_or_equal:today` rule — the poll already exists in the past; we just don't want a future-dated edit to be rejected because the original start was last week.

### 4.4 Service method

`PollService::updatePoll(Poll $poll, array $data): Poll`

Wrapped in `DB::transaction`. Replaces `poll_options`, updates the poll row, calls `forgetVoteCaches($poll->id)` AND a new `forgetAudienceCache($poll->id)` (if we add audience editability in C/D — not in B).

Returns the fresh `Poll` with options reloaded.

### 4.5 PollResource changes

Add `is_editable: bool` field, computed:

```php
$isCreator = $userId !== null && $userId === $this->created_by;
$hasVotes = $this->resource->loadCount('votes')->votes_count > 0;
'is_editable' => $isCreator && !$hasVotes,
```

`loadCount` is one extra SELECT but only when `is_editable` would be true (creator viewing own poll). For the 99% case (anyone else viewing) we short-circuit on `!$isCreator` and never touch the votes table.

Drop the "(TBD)" tag in PollResource.php's `$exposeAudience` comment block once this lands and creators can read `allowed_voters` back via the edit endpoint.

### 4.6 Drop the "(TBD)" tags

Three places carry "creator-only edit endpoint (TBD)" comments that should be updated to point at `PUT /polls/{id}` once it lands:
1. `PollResource.php` lines 41-46
2. `PollController::audience()` docblock
3. The mobile `app/(tabs)/polls/[id].tsx` `audience` interface JSDoc lines 119-128

---

## 5. Mobile design

### 5.1 Affordance on `app/(tabs)/polls/[id].tsx`

Add a pencil button to the nav row, visible IFF `poll.is_editable === true`. Tap → pushes to `app/(tabs)/polls/[id]/edit` (new route).

Alternative: inline-edit on the detail page. Rejected — the create-poll wizard is a multi-step form with audience pickers; reusing it via a navigate-to-edit route is simpler than rebuilding it inline.

### 5.2 New `app/(tabs)/polls/[id]/edit.tsx` route

Prefilled clone of the create-poll wizard, scoped to the editable fields per 3.1. On mount: fetch fresh `/polls/{id}` (so we have the latest `is_editable`); if `is_editable === false` on landing, show the "Someone voted — edit window closed" empty state.

On submit:
- `PUT /polls/{id}` with the full editable section + reCAPTCHA token (action `update_poll`).
- 423 response → swap to "edit window closed" view, link back to detail.
- 200 → toast + navigate back to detail; SWR cache for `/polls/{id}` mutates to the response.

### 5.3 Toast / error mapping

| Status | Action |
|---|---|
| 200 | success toast `polls.editSuccess: "Poll updated"`, back to detail |
| 422 | inline field errors (same pattern as create) |
| 423 | full-screen empty state, no toast (toast is too quiet for this transition) |
| 403 | toast + back to detail (shouldn't happen — UI gates on `is_editable`) |
| 5xx | useRequest default toast |

---

## 6. Web design

Parity. The web `create-poll.tsx` form already exists; the edit route reuses it with prefilled state and a `mode: "edit"` prop that:
- Disables locked fields per 3.1
- Swaps the submit handler to `PUT /api/polls/{id}` (Next.js route forwarding to backend, similar to `route.ts` proxy for `/polls/audience`)
- Renders the same `423` empty state on edit-window-closed

---

## 7. Test plan

### Backend (Pest)

`tests/Feature/PollEditTest.php`:

1. Creator can edit a vote-less poll: 200, response reflects new question/options, `is_editable: true` still.
2. Non-creator gets 403 when editing.
3. Once a vote is cast, creator gets 423 with `poll_received_first_vote` code.
4. Vote is cast AFTER the form was loaded but BEFORE submit (simulate by inserting a vote between two requests) — second submit returns 423.
5. `PollResource.is_editable` is `false` for: non-creator viewers, guests, creator viewing their own poll AFTER first vote.
6. Audience cache and vote cache are both cleared after a successful edit.
7. Editing options replaces all option rows.
8. Validation: missing question / empty options / oversized array returns 422 with the same shape as create.

### Mobile (manual smoke)

- Creator + signed in + zero votes → pencil visible → edit screen loads with prefill → save → back to detail with new values.
- Creator + first vote arrived → pencil hidden → tapping back-button on edit form (loaded before vote) on submit → 423 empty state.
- Non-creator → pencil never visible.
- Signed-out → pencil never visible.

---

## 8. Migration / rollout

- No DB migration needed (no new tables, no new columns).
- Backward compatible — `is_editable` is a new field on the response; old clients ignore it.
- Feature-flag: not needed. The UI affordance only renders when `is_editable === true` AND the user is the creator, so even if we ship mobile before backend the worst case is the pencil never shows.

Suggested order:
1. Backend (PR #1): add `EditPollRequest`, `PollService::updatePoll`, `PollController::update`, route, `is_editable` on PollResource, full test suite. Land + tag with `editable-polls-backend` so the API is stable.
2. Mobile (PR #2): edit route + pencil affordance + i18n + error states. References the backend tag in the PR description.
3. Web (PR #3): same on web.

---

## 9. Risks

- **Privacy edge if audience becomes editable later (3.1 = C/D).** Narrowing the audience to silence voters before they vote is a manipulation vector. Worth a separate conversation if we promote past B.
- **Race window on form-load → vote → submit.** Server-authoritative 423 handles this correctly; UX is the only concern (the empty state needs to be friendly, not a "you broke it" error).
- **Cache TTL on PollResource.** `PollService::getPollById` doesn't cache today (the audience cache is separate). If we add a poll-resource cache later, edit must invalidate it.
- **Recovery codes for the edit endpoint reCAPTCHA action.** Add `update_poll` to the suggested vocabulary in `modules/recaptcha/index.ts`.

---

## 10. What's NOT in scope

- Deleting a poll (already exists via `DELETE` route).
- Editing closed polls (`end_date` past). Hard no — would mess with the closed-poll status invariant.
- Bulk edits.
- Edit history / audit log. We don't have one for create today either.

---

## Next action

Need your sign-off on:
1. **3.1 — editable scope (A/B/C/D)**. I recommend B.
2. **3.4 — only if 3.1 lands C/D**.

Everything else is internal-implementation; review and flag if anything looks off.
