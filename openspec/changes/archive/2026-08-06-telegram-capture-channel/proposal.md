# Proposal — Telegram capture channel

- **Change:** `telegram-capture-channel`
- **Date:** 2026-08-06
- **Owning subdomain:** Ingestion
- **Decided by:** [ADR-0007](../../../../docs/decisions/0007-telegram-primary-conversational-channel.md)
- **Prior exploration:** [`../secure-whatsapp-webhook/exploration.md`](../secure-whatsapp-webhook/exploration.md) — findings F2, F3, F4, F6 carry over

## Problem

Wajaycha has a complete parsing pipeline and no working transport into it. WhatsApp is
not being pursued (ADR-0007), so today a user can only enter a movement by opening the
SPA and typing it, or by exporting a file — the highest-friction paths available.

## Scope boundary — capture only

Financial Coaching is **out of scope** and is a separate change. Coaching requires
deciding what advice is given, when interruption is welcome, and how conversational state
is held. Mixing that with transport work produces a change that cannot be reviewed well.

This change delivers: a user sends a photo or a sentence to the bot, and a `Transaction`
appears in their account.

## What already exists and is reused unchanged

`RegisterWhatsAppTransactionAction(User, ParsedReceiptDTO) → Transaction` is **already
channel-agnostic**; only its name says WhatsApp. The same is true of `ParsedReceiptDTO`,
`GeminiVisionService` and `GeminiTextService`.

Only three responsibilities are genuinely per-channel:

1. downloading the media a message refers to
2. resolving the sender to a `User`
3. replying to the sender

That is the entire port.

## Drivers

| Driver | Rank | How this change serves it |
|---|---|---|
| **QA-4** channel safety | 1 | Telegram Bot API is the official interface. `secret_token` authenticates every delivery |
| **QA-3** data integrity | 2 | `update_id` deduplication; no update silently dropped |
| **QA-8** proactive engagement | 3 | Not exercised here, but the channel chosen must permit it later — Telegram does |
| **QA-7** channel modifiability | 6 | The port is extracted **before** the second adapter, not after |

## Proposed delivery — three chained slices

Forecast: one change would exceed the 400-line review budget and mix a refactor, a schema
change and a new integration. Sliced so each is independently reviewable and independently
revertible.

### Slice 1 — Extract the capture port *(refactor only, no new behaviour)*

- Introduce a `CaptureChannel` contract covering the three per-channel responsibilities.
- Rename `RegisterWhatsAppTransactionAction` → `RegisterCapturedTransactionAction`. Its
  signature does not change.
- **ADR-0005 trigger fires here.** This change touches
  `RegisterWhatsAppTransactionAction`, which is exactly where finding F6 lives:
  `findExistingDetail()` and the resolve-or-create-`Detail` block are duplicated verbatim
  with `Imports/TransactionYapeImport`, both hardcoding trigram `0.6` while
  `CategorizationService` declares an unused `THRESHOLD_TRIGRAM = 0.4`. Extract it into a
  single owning service for Entity Resolution.
- Existing WhatsApp jobs are rewired onto the port and keep working.
- **Success:** the existing suite passes unchanged. No behaviour moves.

### Slice 2 — Channel identity

- Replace the per-channel column with a table keyed by `(channel, external_id) → user`.
  `users.whatsapp_phone` does not survive a second channel.
- Migrate existing `whatsapp_phone` values into it, applying the normalisation from
  finding F4 (E.164 digits, no `+`), preserving the pre-migration value per the rollback
  rule.
- Add the linking flow: a signed, single-use, expiring token issued by the SPA and
  redeemed by the bot.
- **Success:** a linked user is resolved from a Telegram chat id; an unlinked sender is
  handled deliberately.

### Slice 3 — Telegram adapter

- Webhook endpoint verifying `X-Telegram-Bot-Api-Secret-Token` against the value
  registered with `setWebhook` — the F1 equivalent, and simpler than an HMAC.
- Idempotency on `update_id` (F2).
- Iterate every update in the payload; ignore non-message updates explicitly (F3).
- Photo retrieval via `getFile`, feeding the existing `GeminiVisionService`.
- Text messages feeding the existing `GeminiTextService`.
- Reply contract mirroring the existing WhatsApp one, which exploration found already
  complete (F8).
- **Success:** a photo produces a `Transaction`; a sentence produces a `Transaction`; a
  replayed `update_id` produces nothing.

## Risks

| Risk | Mitigation |
|---|---|
| Slice 1 is a refactor across live ingestion paths | No behaviour change is permitted. The existing suite is the contract; it must pass untouched |
| The identity migration is destructive | Copy the pre-migration value into a nullable column and keep it until archive, per the rollback rule already established |
| A linking token that does not expire is a permanent account-takeover primitive | Signed, single-use, short expiry. It is a credential, not a convenience |
| Telegram photos arrive in several resolutions | `photo` is an array of sizes; pick the largest under Telegram's 10 MB bound, and record which was used |
| A missing `TELEGRAM_SECRET_TOKEN` fails every delivery closed | Correct for a security control. Deployment checklist sets it before `setWebhook` is called |

## Rollback

- **Slice 1** reverts by reverting the commit; no persisted state depends on it.
- **Slice 2** is the only destructive step; the preserved column is the recovery path.
- **Slice 3** is additive. Reverting it leaves an unused table and an unregistered webhook.
- To disable the channel in production without a deploy, call `deleteWebhook`.

## Open question for the owner

**How does a user link their Telegram account?** The proposal assumes the lowest-friction
option: the SPA shows a button that deep-links to the bot carrying a signed token, so
linking is one tap and nothing is typed.

The alternative — the bot asks for the account email and a code — works without the SPA
but adds several steps and a second channel to deliver the code. Not a blocker; the deep
link is assumed unless changed.
