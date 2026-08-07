# Proposal — Clarifying question on uncertain categorisation

- **Change:** `financial-coaching-clarify`
- **Date:** 2026-08-06
- **Owning subdomain:** Financial Coaching (first behaviour) — touches Categorization and Ingestion
- **Decided by:** [ADR-0007](../../../../docs/decisions/0007-telegram-primary-conversational-channel.md)
- **Exploration:** [`exploration.md`](exploration.md) — mirrored from Engram `sdd/financial-coaching-clarify/explore`

## Intent

Financial Coaching exists on paper and owns nothing. This change is its first code, and it
is deliberately the smallest thing that is genuinely coaching rather than notification: the
bot **asks a question and acts on the reply**.

Today, when the cascade is unsure it does the two worst things silently. A weak pgvector
match (distance anywhere under `0.15`) is written straight into a `CategorizationRule` as if
it were certain, and a hard `null` leaves the transaction uncategorised with nobody told.
Both outcomes are invisible at the moment the user could most cheaply correct them — the
second they made the purchase. [QA-2](../../../../docs/quality/quality-attributes.md#qa-2--categorization-accuracy)
says an uncategorised transaction is preferable to a wrong one; the current code does not
honour that.

**And the correction path is broken.** `CategorizationService::createExactRule()` is
`CategorizationRule::firstOrCreate(...)`: it creates and never updates. **No code path in
this repository can change an existing rule's category once created** — not
`UpdateTransactionAction`'s `is_frequent` branch, not `CategoryRuleController::syncRule()`.
Without fixing it, a user confirming or correcting a weak guess is a silent no-op and the
whole feature is hollow. The fix also repairs editing a category from the SPA.

## Scope

### In scope

1. **Detect uncertainty** at two triggers, for bot captures only: `findCategory()` returning
   `null`, and the pgvector branch matching only weakly.
2. **Do not persist an unconfirmed guess.** In the weak case neither the rule nor the
   transaction's `category_id` is written until the user answers (QA-2).
3. **Ask, with buttons.** 2–3 ranked candidate categories plus an "other" escape, answered
   in one tap ([QA-1](../../../../docs/quality/quality-attributes.md#qa-1--capture-friction)).
4. **Receive the answer.** Route Telegram `callback_query` updates, which
   `TelegramWebhookController::dispatchUpdate()` currently drops silently after marking them
   processed.
5. **Persist a pending-question state.** No conversational state exists anywhere today.
6. **Learn.** The answer writes the merchant rule (`CategorizationRule` is keyed on
   `detail_id`, and `DetailResolver` reuses one `Detail` per merchant — so the rule is
   effectively per-merchant) and sets the transaction's category.
7. **Fix `createExactRule()`** so an existing rule's `category_id` can change, with
   regression coverage for the two inheriting call sites.

### Out of scope — must not change behaviour

- **Bulk imports.** `TransactionYapeImport` and `ProcessPdfImport` keep auto-accepting weak
  matches exactly as today. They are never asked about.
- **Any other coaching behaviour** — budget warnings, Pareto narration, scheduled nudges.
- **WhatsApp.** No `WhatsAppChannel` interactive implementation ships here.
- **Reviving `KeywordRule`** (finding F7 — cascade steps 3 and 4 are unreachable).
- **The embedding-source defect and `Transaction::category()`** — recorded as risks below,
  fixed elsewhere.
- **A "needs review" transaction state in the SPA.** Unanswered questions leave the
  transaction uncategorised, which the SPA already shows.

## Capabilities

### New capabilities

- `financial-coaching`: uncertainty triggers, candidate ranking, the pending-question
  lifecycle (ask → answer → learn → expire), and the interruption budget.
- `categorization`: first spec for the cascade — its ordering, the weak-match boundary, and
  **rule write semantics (create *and* update)**.

### Modified capabilities

- `ingestion`: the Telegram adapter must route `callback_query` updates instead of dropping
  them; a bot capture may now complete with its category deliberately deferred.

## Approach

The cascade keeps one implementation. `findCategory(): ?int` stays exactly as it is for the
two bulk-import callers; a **new method on `CategorizationService` returns a richer outcome**
(category, confidence, ranked candidates) and `findCategory()` becomes a thin wrapper over
it. Bulk imports change zero call sites and zero behaviour.

Candidates come from the query that already runs: raising the pgvector `limit(1)` to N and
deduping by `last_used_category_id` yields a relevance-ranked list with no new data.

**Ranking is honestly asymmetric.** That works for the weak-vector trigger. For the hard
`null` trigger there is **no relevance signal at all** — no distance, no keyword match. The
only fallback is per-user rule frequency ("categories this user actually uses"), which is
much weaker. The two triggers will not produce equally good suggestions, and the "other"
escape carries more of the load in the `null` case.

A pending question is a persisted row: user, channel identity, `detail_id`, the offered
options in order, the trigger kind (it decides whether an answer *writes* or *confirms*), and
the sent `message_id` so the keyboard can be edited away on answer.

### Interruption budget (QA-1)

QA-1 is the driver this change puts at risk — every question is friction.

| Rule | Value |
|---|---|
| Questions per capture | At most 1 |
| Outstanding questions per merchant | At most 1 — never re-ask about a `detail_id` already pending |
| Questions per user per day | Capped; over the cap the capture falls back to today's silent behaviour |
| Unanswered | Question expires; transaction stays uncategorised, correctable in the SPA. The bot never nags |
| Undelivered | Logged and swallowed, matching `reply()`. No retry model exists on either channel |

## The open fork — deferred to `sdd-design`

Asking with buttons is a capability `CaptureChannel` does not have. Its three methods
(`key()`, `fetchMedia()`, `reply(externalId, text)`) are plain text only; grepping `app/` for
`interactive|button|reply_markup|quick_reply` returns zero hits. Telegram's
`reply_markup.inline_keyboard` and WhatsApp Cloud API's interactive button/list payload are
structurally different JSON, so no text convention papers over this.

| Option | For | Against |
|---|---|---|
| **A — extend `CaptureChannel`** with `ask()` | ADR-0007's literal "both are adapters behind one capture port"; fewest abstractions; QA-7 trivially satisfied | Puts Coaching's outbound dialogue inside Ingestion's inbound-capture port, which the [context map](../../../../docs/domain/context-map.md) explicitly separates ("Not Notification… Coaching holds a dialogue") |
| **B — a separate Coaching outbound port** | Matches the context map: Coaching *reaches through* the channel, it does not own capture. Keeps `CaptureChannel` at the 3 responsibilities its own docblock calls "genuinely per-channel" | Two ports per adapter to keep in sync; more new code for a subdomain that owns nothing yet |

**Recommendation: B**, with the *same* per-channel adapter class implementing both ports.
Reasoning: the separation is one the project already wrote down and would be undone on the
subdomain's very first commit; the blast radius is smaller, since the capture path and the
bulk-import path never see a new method; and QA-7 survives, because adding a channel still
means touching only that channel's adapter. **The binding decision belongs to `sdd-design`**,
which should weigh how far WhatsApp's coaching path is expected to go given its 24-hour
window. Note either way: WhatsApp caps quick replies at 3 buttons, so cross-channel parity
means 2 candidates + "other".

## Affected areas

| Area | Impact | What changes |
|---|---|---|
| `app/Services/CategorizationService.php` | Modified | `createExactRule()` update semantics; new confidence-carrying method; ranked candidates |
| `app/Actions/Transactions/UpdateTransactionAction.php`, `app/Http/Controllers/CategoryRuleController.php` | Fixed by inheritance | Corrections finally take effect |
| `app/Services/Capture/CaptureChannel.php`, `TelegramChannel.php` | Modified/New | Outbound ask; `answerCallbackQuery`; edit message |
| `app/Http/Controllers/Capture/TelegramWebhookController.php` | Modified | `callback_query` branch. Deduplication is untouched — it keys on the outer `update_id`, present on every update type |
| `app/Actions/Capture/RegisterCapturedTransactionAction.php` | Modified | May finish with the category deferred |
| Migration + model for pending questions | New | Additive |
| `ProcessPdfImport`, `TransactionYapeImport` | **Untouched** | Guarded by tests |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Embeddings are built from raw `description`, not `entity_clean` (technical-debt item 2) — the very distance that judges "weak" is noisier than the cascade's design intends | High | Recorded, **not fixed here**. The weak boundary must be tunable, not hardcoded |
| No unit tests on `CategorizationService`; the cascade has no regression net | High | Slice 1 adds the net before anything else moves |
| Questions become nagging and damage QA-1 | Medium | The interruption budget above; per-day cap; never re-ask a pending merchant |
| Ranking for the hard-`null` trigger is weak, so suggestions look arbitrary | Medium | Stated openly; "other" escape is mandatory, not decorative |
| A silently undelivered ask leaves an orphan pending question | Medium | Expiry sweeps it; the transaction is correctable in the SPA |
| Concurrent captures/answers for one user — no per-user job serialisation exists | Medium | One outstanding question per merchant; answers are idempotent per pending-question id |
| `Transaction::category()` is a broken `hasOneThrough(Category, Category)` | Certain | Recorded, **not fixed here**. Load category names via `category_id` directly |
| Fixing `createExactRule()` changes behaviour for existing correction paths | Medium | That is the point — but it is slice 1, isolated and independently revertible |

## Review budget forecast

**400-line budget risk: High.** Estimated ~1,100 authored lines. Delivered as **separate
commits on one branch** (`auto-chain` / `stacked-to-main`), carrying forward the owner's
explicit feedback that many chained branches are expensive.

| # | Slice | Kind | Est. lines |
|---|---|---|---|
| 1 | Fix `createExactRule()` + cascade regression net | bugfix + tests | ~150 |
| 2 | Confidence-carrying outcome + ranked candidates (bulk imports untouched) | behaviour | ~200 |
| 3 | Pending-question state and lifecycle | schema + behaviour | ~220 |
| 4 | Outbound ask capability (port + Telegram inline keyboard) | integration | ~250 |
| 5 | `callback_query` routing + answer resolution + learning | integration | ~280 |

Slice 1 is independently valuable and shippable on its own: it repairs category editing in
the SPA whether or not the rest lands.

## Rollback

- **Slices 1, 2** revert by reverting the commit; no persisted state depends on them.
- **Slice 3** is the only schema step and is purely additive — drop the table. No existing
  column or row is modified or migrated.
- **Slices 4, 5** are additive. Reverting leaves an unused table and unanswered keyboards.
- **Without a deploy:** the per-day question cap set to `0` disables asking entirely and
  restores today's silent behaviour.
- Nothing here touches the Gemini integration or existing queue payloads.

## Success criteria

- [ ] A bot capture the cascade cannot categorise produces a question with candidates plus "other".
- [ ] A weak vector match writes **neither** the rule nor the transaction category before the user answers.
- [ ] A tapped answer sets the transaction's category and persists the merchant rule.
- [ ] Answering a merchant that already has a rule **changes** it (the defect fix).
- [ ] Correcting a category from the SPA changes the rule (same fix, other path).
- [ ] Bulk imports produce byte-identical categorisation outcomes, proven by test.
- [ ] An unanswered question expires, leaves the transaction uncategorised, and is never re-asked.
- [ ] A replayed `callback_query` `update_id` produces nothing.

## Open questions for the owner

Non-blocking. The proposal assumes the answer stated in each.

1. **How many questions per user per day?** Assumed **3**.
2. **How long does a question stay answerable?** Assumed **48 hours**, then it expires silently.
3. **What counts as "weak"?** Assumed a second, higher band below `THRESHOLD_VECTOR = 0.15` (e.g. `0.05–0.15` asks, under `0.05` auto-accepts). The exact number is design's, but the *shape* — two bands, not one — is a product call.
4. **Does the weak guess also stay off the transaction, not just off the rule?** Assumed **yes**, on QA-2's "prefer uncategorised over wrong". The owner only spoke about the rule.
5. **2 candidates or 3?** Assumed **2 + "other"**, because WhatsApp caps quick replies at 3 and cross-channel parity is cheaper decided now than later.
