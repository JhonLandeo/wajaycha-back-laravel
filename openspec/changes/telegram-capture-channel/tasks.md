# Tasks — Telegram capture channel

- **Change:** `telegram-capture-channel`
- **Date:** 2026-08-06
- **Spec:** [`specs/ingestion/spec.md`](specs/ingestion/spec.md) · **Design:** [`design.md`](design.md)
- **Strict TDD:** enabled. Every behavioural task pairs a failing test with its implementation.
- **Test command:** `php artisan test` · **Static analysis:** `./vendor/bin/phpstan analyse --memory-limit=1G`

## Review workload forecast

The proposal assumed three slices. Estimating authored lines showed two of them over the
400-line review budget, so the chain is **five slices**. Delivery strategy is `auto-chain`;
this is that split.

| Slice | Authored lines (est.) | Budget | Kind |
|---|---|---|---|
| 1 — Entity Resolution extraction | ~180 | ok | refactor |
| 2 — Capture port | ~190 | ok | refactor |
| 3 — Channel identity | ~290 | ok | schema + behaviour |
| 4 — Account linking | ~250 | ok | behaviour |
| 5 — Telegram adapter | ~380 | tight | integration |

Slice 5 is close to the ceiling. If it exceeds during implementation, split the webhook
intake (middleware, deduplication, update iteration) from the media path.

> **Note for reviewers of slices 1 and 2:** these are refactors, which
> [ADR-0005](../../../../docs/decisions/0005-opportunistic-hexagonal-refactor.md) forbids
> as *standalone changes*. They are not standalone — they are the first slices of a change
> that touches these files for a functional reason. The ADR trigger is satisfied.

---

## Slice 1 — Extract Entity Resolution

**Goal:** one owning service for resolve-or-create-`Detail`. No behaviour changes.
**Contract:** the existing suite passes untouched.

- [x] 1.1 Write failing tests for `DetailResolver`: exact `entity_clean` match reuses a
      `Detail`; a name above the trigram threshold reuses it; a dissimilar name creates a
      new one; resolution is scoped per user; a blank `entity_clean` is backfilled
- [x] 1.2 Create `app/Services/DetailResolver.php` with `resolveOrCreate()` and
      `findExisting()`, holding the trigram threshold as one constant
- [x] 1.3 **Preserve the effective value `0.6`.** Both current call sites hardcode it
- [x] 1.4 Delete the unused `CategorizationService::THRESHOLD_TRIGRAM = 0.4`. Do **not**
      adopt it — that would silently change matching inside a no-behaviour-change slice
- [x] 1.5 Rewire `RegisterWhatsAppTransactionAction` to the resolver; delete its private
      `findExistingDetail()`
- [x] 1.6 Rewire `Imports/TransactionYapeImport` likewise; delete its duplicate
- [x] 1.7 Run the full suite. Any change in existing test results means the refactor
      altered behaviour and must be corrected, not accepted
- [x] 1.8 Run PHPStan; do not grow `phpstan-baseline.neon`

---

## Slice 2 — Extract the capture port

**Goal:** the three per-channel responsibilities behind one contract, with the existing
WhatsApp code as its first implementation.

- [x] 2.1 Write a failing contract test asserting any `CaptureChannel` exposes `key()`,
      `fetchMedia()` and `reply()`, driven by a fake implementation
- [x] 2.2 Create the `CaptureChannel` interface and the `CapturedMedia` DTO
- [x] 2.3 Write failing tests for `WhatsAppChannel` with HTTP faked
- [x] 2.4 Implement `WhatsAppChannel`, delegating to the existing `MetaMediaService` and
      `WhatsAppNotificationService`. No new transport code
- [x] 2.5 Register channels by key in a service provider so an adapter is resolvable
      without a `match` statement in the caller
- [x] 2.6 Rename `RegisterWhatsAppTransactionAction` → `RegisterCapturedTransactionAction`.
      **Signature unchanged**
- [x] 2.7 Point `ProcessWhatsAppImage` and `ProcessWhatsAppMessage` at the port and the
      renamed action
- [x] 2.8 Full suite green, unchanged. PHPStan clean

---

## Slice 3 — Channel identity

**Goal:** identity keyed by `(channel, external_id)`, replacing `users.whatsapp_phone`.
**This is the only destructive slice.**

- [x] 3.1 Write a failing test: a `ChannelIdentity` resolves a channel account to its
      `User`; an unknown account resolves to nothing; the same `external_id` on two
      channels is two identities
- [x] 3.2 Migration creating `channel_identities` per `design.md`: `timestamptz`
      throughout, table and column comments, `unique (channel, external_id)`,
      `index (user_id)`
- [x] 3.3 Include the nullable `legacy_whatsapp_phone` column. **It is the rollback path
      for 3.5 and must not be omitted**
- [x] 3.4 `ChannelIdentity` model plus its resolution service
- [x] 3.5 Data migration copying `users.whatsapp_phone` into `channel_identities`,
      normalising to E.164 digits without `+`, preserving the original in
      `legacy_whatsapp_phone`
- [x] 3.6 Write a failing test proving the migration **aborts** on a row it cannot
      normalise, and on a normalisation collision, rather than guessing
- [x] 3.7 Log every row the migration changes
- [x] 3.8 Point both WhatsApp jobs at identity resolution instead of
      `User::where('whatsapp_phone', ...)`
- [x] 3.9 Full suite green. PHPStan clean. Verify `down()` restores the original values

---

## Slice 4 — Account linking

**Goal:** bind a channel account to a `User` only on redemption of a valid token.

- [x] 4.1 Write failing tests: a valid token links; a redeemed token is refused; an expired
      token is refused; a forged token is refused; an account already bound is refused
- [x] 4.2 Write a failing test asserting **every refusal returns the same generic
      response**, so token existence cannot be probed
- [x] 4.3 Migration creating `channel_link_tokens`: `unique (token_hash)`,
      `index (expires_at)`, `timestamptz`, comments
- [x] 4.4 `ChannelLinkToken` model plus issuing and redemption services
- [x] 4.5 **Store only the SHA-256 hash. Never persist or log the token itself.** Compare
      in constant time
- [x] 4.6 Authenticated endpoint issuing a token for the current user and returning the
      deep link
- [x] 4.7 Write a failing test asserting an expired token is refused after its window,
      using a controlled clock rather than a real sleep
- [x] 4.8 Scheduled command pruning expired and redeemed tokens
- [x] 4.9 Full suite green. PHPStan clean

---

## Slice 5 — Telegram adapter

**Goal:** a photo or a sentence sent to the bot produces a `Transaction`.
**Tightest slice. Split the webhook intake from the media path if it exceeds budget.**

- [x] 5.1 Write failing tests for the secret-token middleware: a matching header passes;
      a mismatched one is rejected; a missing one is rejected; an unconfigured token
      rejects everything and logs distinctly
- [x] 5.2 Implement the middleware with a constant-time comparison. **Middleware, not
      controller** — it must run before any decision about the body
- [x] 5.3 Write a failing test proving a rejected delivery enqueues nothing and replies to
      nobody
- [x] 5.4 Migration creating `processed_channel_updates`:
      `unique (channel, external_update_id)`, `index (processed_at)`, `timestamptz`,
      comments
- [x] 5.5 Write a failing test: a replayed `update_id` creates no second `Transaction` and
      makes no AI call; deduplication survives a restart; two concurrent inserts of the
      same id do not both succeed
- [x] 5.6 Implement deduplication **in the controller, before dispatch**, relying on the
      unique index rather than a read-then-write check
- [x] 5.7 Write a failing test: a delivery carrying three updates dispatches three jobs;
      an unsupported update kind is skipped and recorded while the rest continue
- [x] 5.8 Webhook controller iterating every update
- [x] 5.9 Write failing tests for `TelegramChannel::fetchMedia` with HTTP faked, including
      that the **largest** variant under the size limit is chosen and recorded
- [x] 5.10 Implement `TelegramChannel` — `getFile`, file download, `sendMessage`
- [x] 5.11 Write failing tests for the capture job: a photo produces a `Transaction`; a
      sentence produces a `Transaction`; an unlinked sender produces none and gets linking
      instructions; an unparseable capture produces none and gets a reply
- [x] 5.12 Implement the capture job against the port and `RegisterCapturedTransactionAction`
- [x] 5.13 Handle `/start <token>` by delegating to slice 4's redemption service
- [x] 5.14 Add `services.telegram.{bot_token,secret_token,bot_username}` and the webhook route
- [x] 5.15 Scheduled command pruning `processed_channel_updates` beyond the retry window
- [x] 5.16 Full suite green. PHPStan clean. Pint clean on changed files

---

## Definition of done

- [x] Every spec scenario in `specs/ingestion/spec.md` has a covering test
- [x] `php artisan test` green
- [x] `./vendor/bin/phpstan analyse --memory-limit=1G` clean with no baseline growth
- [x] `./vendor/bin/pint --test` clean on changed files
- [x] No coaching behaviour was added
- [x] `ParsedReceiptDTO` unchanged
- [x] `legacy_whatsapp_phone` still present, with dropping it recorded as an archive task

## Deferred, recorded so they are not forgotten

| Item | Where it goes |
|---|---|
| Drop `legacy_whatsapp_phone` | Archive task for this change |
| Rate limiting per sender | Follow-up; real once the bot is public |
| Outbound counterpart of `CaptureChannel` | The coaching change. Not guessed here |
| Factura and boleta modelling (F5) | Separate change |
| `KeywordRule` write path (F7) | Product decision for the owner |


---

## Deviations recorded at apply

1. **Ten commits, not five slices.** Slices 3, 4 and 5 each exceeded the 400-line
   review budget once implemented, and were split at real seams rather than at the
   line count: 3a/3b (schema vs runtime), 4a/4b (issuance vs redemption), and slice 5
   in five commits (secret boundary → deduplication → adapter → capture job → ingress).
2. **One commit outside this list.** `9661acf` added direct coverage for
   `ProcessWhatsAppImage::handle()`, `ProcessWhatsAppMessage::handle()` and their
   `failed()` handlers. Neither had any test; that gap let a CRITICAL reach review, and
   slice 5 builds on those same jobs.
3. **Task 5.9 landed slightly differently.** Choosing the largest photo variant lives in
   `TelegramChannel::largestPhotoUnder()` and is called by the job before `fetchMedia`,
   because the capture port takes a single media reference. Same behaviour, port intact.
4. **`users.whatsapp_phone` was not dropped.** Registration still writes it and no task
   covered changing that path; capture no longer reads it. Removal belongs to archive,
   alongside `legacy_whatsapp_phone`.
