# Exploration — First Financial Coaching behaviour: clarifying question on uncertain categorisation

> Mirrored from Engram `sdd/financial-coaching-clarify/explore` (observation #77) to satisfy
> `artifact_store: hybrid`. Content is verbatim; only this header was added.

## Current State

`CategorizationService::findCategory()` (app/Services/CategorizationService.php:22-100) is a cascade:

1. exact `CategorizationRule` by `detail_id` (line 26-32)
2. `Category.name ilike %message%` (line 38-49) — reuses the raw message text
3. `KeywordRule` on message (line 53-59) — dead per F7, no write path
4. `KeywordRule` on entity (line 63-70) — same dead path; on match calls `createExactRule()` and persists immediately
5. pgvector nearest `Detail` by `embedding <=> ?`, `distance < THRESHOLD_VECTOR (0.15)` (line 82-96) — on match ALSO calls `createExactRule()` and persists immediately, with no distinction between distance 0.001 and distance 0.149
6. `null` (line 99)

`createExactRule()` (line 129-135) is `CategorizationRule::firstOrCreate(['user_id','detail_id'], ['category_id'])` — **it never updates an existing row**, only creates when none exists.

`CaptureChannel` port (app/Services/Capture/CaptureChannel.php) has exactly 3 methods: `key()`, `fetchMedia()`, `reply(string $externalId, string $text)`. `TelegramChannel::reply()` calls Telegram `sendMessage` (plain text only). `WhatsAppChannel::reply()` calls `WhatsAppNotificationService::sendTextMessage` (also plain text only). Grepped the whole `app/` tree for `interactive|button|reply_markup|quick_reply` — zero hits. No interactive/button message capability exists anywhere in the codebase today, on either channel.

`TelegramWebhookController::dispatchUpdate()` (app/Http/Controllers/Capture/TelegramWebhookController.php:94-131) only reads `$update['message']`; there is no branch for `$update['callback_query']` — a callback update falls through to "sin mensaje utilizable" and is silently dropped after being marked processed.

`ChannelUpdateDeduplicator::claim()` (app/Services/Capture/ChannelUpdateDeduplicator.php) keys on `(channel, external_update_id)` = Telegram's top-level `update_id`, which is present on every Telegram Update regardless of whether it wraps a `message` or a `callback_query`. The claim happens in the controller loop **before** `dispatchUpdate()` is called (line 64-68), so deduplication already covers callback deliveries with zero changes — only the routing inside `dispatchUpdate()` is missing.

`DetailResolver::resolveOrCreate()` (app/Services/DetailResolver.php) reuses one `Detail` row per merchant via exact match or trigram similarity > 0.6 (`THRESHOLD_TRIGRAM`). This means a `CategorizationRule` keyed on `detail_id` is effectively **keyed on merchant** and applies to every future transaction with that merchant, not just the one that created it.

## Affected Areas

- `app/Services/CategorizationService.php` — cascade; return shape of `findCategory()`; `createExactRule()` non-overwrite bug
- `app/Services/Capture/CaptureChannel.php`, `TelegramChannel.php`, `WhatsAppChannel.php` — outbound capability gap (no buttons)
- `app/Http/Controllers/Capture/TelegramWebhookController.php` — no `callback_query` routing
- `app/Jobs/ProcessTelegramCapture.php` — constructor only models `(chatId, text, mediaReference)`, nothing for a callback answer
- `app/Actions/Capture/RegisterCapturedTransactionAction.php` — the in-scope caller of `findCategory()`
- `app/Jobs/ProcessPdfImport.php:162`, `app/Imports/TransactionYapeImport.php:97-101` — out-of-scope bulk-import callers of the **same** `findCategory()` method; any signature change ripples here even though behavior must not
- `app/Actions/Transactions/UpdateTransactionAction.php` — the only place that writes to `CategorizationRule` after creation, and it inherits the non-overwrite bug via `createExactRule()`
- `app/Http/Controllers/CategoryRuleController.php:100` (`syncRule`) — same non-overwrite bug from the manual-correction UI path
- `app/Models/Detail.php`, `Category.php`, `CategorizationRule.php` — data available for ranking candidates
- No existing table/model for conversational state (verified absent — see Finding 1)

## Findings (answering the 7 questions)

**1. Conversational state — confirmed absent.** Grepped `app/` for `conversation|awaiting|pending_question|Conversation|PendingClarification` and migrations for `conversation|awaiting|pending` — no matches outside unrelated `Import`/`jobs` tables. Nothing today persists "a question is outstanding for this user/chat, awaiting one of N answers." A new persisted state is required: at minimum, per pending question — user/chat identity, the `detail_id` (or `transaction_id`) it concerns, the offered category options in order, whether it's a "hard null" or "weak vector" case (governs whether accepting persists a rule immediately or only on confirm), and enough of a Telegram identifier (chat id, and ideally the sent message's `message_id`) to edit/answer the right message. Also need a policy for what happens if a second capture arrives while one question is still outstanding — nothing in the job pipeline serializes per-user processing today (`ProcessTelegramCapture` is a bare `ShouldQueue` job).

**2. Outbound capability gap — confirmed a real port change, not a workaround.** `CaptureChannel::reply()` takes only `(externalId, text)`; inline keyboards are Telegram-specific (`reply_markup.inline_keyboard`) and WhatsApp's Cloud API has a structurally different interactive-message shape (button/list, max 3 quick-reply buttons or a 10-item list) — not the same JSON shape, so this cannot be a text convention. Two real options exist: (a) extend `CaptureChannel` with a 4th method (e.g. `ask(string $externalId, string $question, array $options): void`), which every implementation (Telegram, and later WhatsApp) must satisfy, keeping one capture port as ADR-0007's "both are adapters behind one capture port" already assumes; or (b) a separate, smaller outbound port scoped to Financial Coaching specifically (since ADR-0007/context-map say Coaching "emits through the conversational channel" as its own subdomain, not through Ingestion's capture port). QA-7 ("adding/replacing a capture channel requires zero changes outside the ingestion subdomain") is only satisfied by (a) if this capability is still considered part of Ingestion; the context-map explicitly frames Coaching as a *separate* subdomain that merely *reaches through* the channel, which leans toward (b). This is a real architectural fork with no existing precedent in the codebase to resolve it by convention — left for `sdd-propose`.

**3. How an answer arrives back — confirmed, concrete gap.** Telegram delivers a button tap as `update.callback_query`, not `update.message`. `TelegramWebhookController::dispatchUpdate()` never inspects `callback_query`; today such an update is claimed by the deduplicator (so it's marked processed) and then silently dropped by the "no usable message" branch. A new branch is needed that reads `callback_query.data` (the short payload identifying which option was tapped), `callback_query.message.chat.id` (or `callback_query.from.id`) for identity, and dispatches a job that resolves the pending question. Two extra Telegram-side needs the current `TelegramChannel` doesn't model: `answerCallbackQuery` (Telegram requires this or the tap spinner hangs client-side) and, for good UX, editing the original message to remove the keyboard once answered — neither exists in `TelegramChannel` today, which only wraps `getFile`, file download, and `sendMessage`. Deduplication itself needs **no change** — it already keys on the outer `update_id`, present on every update type (confirmed by the dedup migration's own comment describing `update_id` generically, not as message-specific).

**4. Ranking candidates — concrete data exists, but asymmetric between the two trigger cases.** For the **weak-vector case**: the existing pgvector query (`CategorizationService.php:82-90`) already computes ranked distances; changing `limit(1)` to a higher limit and taking the first N *distinct* `last_used_category_id` values yields a relevance-ranked candidate list with no new data. For the **hard-null case** (cascade exhausted, no vector match at all): there is no relevance signal available — no distance, no keyword match (KeywordRule is dead per F7), only the raw-message `ilike` check which already ran and failed. The only fallback signal available today is user-level category frequency — `Category::categorizationRules()` (a `hasMany`) lets you count rules per category per user, i.e., "categories this user actually uses" — a much weaker signal than merchant-specific similarity. `Category.monthly_budget` is a budget config, not a categorization relevance signal, and is not useful here. **Caveat that weakens confidence in the vector-based ranking**: technical debt item 2 records that the embedding is generated from `detail.description` (raw text) rather than `entity_clean` (the normalized field the earlier cascade steps deliberately search), contradicting its own code comment — meaning the distance values used to decide both "is this weak" and "what are the runner-up candidates" are noisier than the cascade's own design intends.

**5. Where the uncertainty signal would live — `findCategory(): ?int` cannot express confidence, and there are 3 real callers, only 1 in scope.** Callers: `RegisterCapturedTransactionAction::execute()` (line 46, in scope), `ProcessPdfImport::handle()` (line 162, bulk import, explicitly out of scope), `TransactionYapeImport` (lines 97-101, bulk import, explicitly out of scope). Both bulk-import callers assign the return value directly to `category_id` and expect a plain `?int`. Any change to the return shape (e.g., a DTO carrying category + confidence) breaks both out-of-scope call sites unless either (a) a new method is added alongside `findCategory()` for the bot path only, leaving the signature untouched for bulk imports, or (b) the signature does change everywhere and a decision is made — explicitly, since the owner didn't make it — about whether bulk imports should silently keep auto-accepting weak vector matches (today's behavior) with no clarifying step. No dedicated test exists for `CategorizationService` itself (only `RegisterCapturedTransactionActionTest`, which mocks `findCategory` to return a plain int in 3 places) — there is no unit-level regression net for the cascade's decision boundary today.

**6. Undo — confirmed structurally broken today, not just "not built."** `TransactionsController::update()` allows editing `category_id` on any transaction where `is_manual = true` (bot captures set `is_manual => true` in `RegisterCapturedTransactionAction.php:66`, so this UI path is reachable for bot-captured transactions). `UpdateTransactionAction::execute()` branches on `is_frequent`: the `true` branch calls `categorizationService->createExactRule()` (line 93-97) — which, per the `firstOrCreate` semantics above, **does nothing if a `CategorizationRule` already exists for that `detail_id`**, silently leaving a wrong rule in place even after the user explicitly corrects it. The `false` branch never touches `CategorizationRule` at all — it only dispatches `GenerateEmbeddingForDetail`, which updates `Detail.embedding`/`Detail.last_used_category_id` (correcting the *seed* used by future vector searches against *other* merchants) but does nothing to fix the exact-rule cascade step (step 1) for *this* merchant, which is checked first and will keep winning. The manual correction UI, `CategoryRuleController::syncRule()` (line 100), calls the same `createExactRule()` and has the identical non-overwrite bug. **Net finding: no code path anywhere in the repository can currently change an existing `CategorizationRule`'s `category_id` once created.** The owner's accepted tradeoff ("a mis-tap becomes a permanent rule") is not a limitation specific to this new feature — it is the system's actual current behavior for every existing correction path.

**7. Obstacles beyond the above:**

- Financial Coaching "owns nothing yet" per the context map — this change is the subdomain's first code, so there's no established pattern in this codebase to extend; every piece (state, outbound capability, callback routing, ranking, confirm-vs-auto-persist) is new.
- QA-2 ("prefer leaving a transaction uncategorized over assigning a wrong category") is a stated architectural driver, and directly validates the premise behind decision 2 (don't persist an unconfirmed weak-vector guess) — this is grounded in an existing ADD driver, not just an ad hoc product call.
- `Transaction::category()` is a confirmed-broken `hasOneThrough(Category, Category)` relation (`app/Models/Transaction.php:57-60`, recorded in technical-debt.md item 1) — any code building the question text from `$transaction->category` will silently get nothing; category names must be loaded by `category_id` directly.
- Both channels' `reply()` implementations swallow send failures into a log line only, with an explicit comment that this is deliberate (retrying would risk duplicating an already-saved transaction). The same pattern would apply to a failed "ask" send — there is no existing retry/escalation model for an undelivered outbound message, so a silently-undelivered clarifying question has no built-in fallback today.
- No per-user concurrency control exists on the Telegram job pipeline; a pending-question model needs to define what happens if a new capture arrives (or the user answers late) while a question is outstanding — nothing in the current queue setup orders this today.

## Approaches (scoped to the one genuine fork found: outbound "ask" capability)

1. **Extend `CaptureChannel` with a 4th port method** (e.g. `ask()`), implemented by `TelegramChannel` with inline keyboards and eventually by `WhatsAppChannel` with interactive buttons.
   - Pros: keeps "one capture port, N adapters" intact per ADR-0007; satisfies QA-7's zero-changes-outside-ingestion framing if Coaching is treated as living inside/adjacent to the port; minimal new abstractions.
   - Cons: conflates Ingestion's inbound-capture responsibility with Coaching's outbound-conversation responsibility inside one interface, which the context map explicitly separates ("Not Notification... Coaching holds a dialogue"); WhatsApp's interactive-message JSON shape is structurally different from Telegram's, so the port method's parameters would need to be channel-agnostic in a way that hasn't been designed yet.
   - Effort: Medium.

2. **A separate, smaller outbound port for Financial Coaching**, consumed alongside (not instead of) `CaptureChannel`.
   - Pros: matches the context map's own framing of Coaching as a distinct subdomain that "emits through the conversational channel" rather than being folded into Ingestion; keeps `CaptureChannel` at exactly the 3 responsibilities its own docblock claims are "genuinely per-channel."
   - Cons: two channel-identity lookups/ports to keep in sync per adapter; more new code for a subdomain that owns nothing yet.
   - Effort: Medium-High.

Recommendation: not selected here — this is a real fork with no established precedent in the codebase either way; it should be decided explicitly in `sdd-propose`, informed by how far WhatsApp's coaching path is expected to go given its 24-hour-window limitation (ADR-0007).

## Risks

- The vector-search "weak match" boundary used to decide "ask vs. auto-accept" sits on an embedding known to be generated from noisier raw text than the cascade's own design intends (technical-debt item 2) — tuning or trusting `THRESHOLD_VECTOR` for this new purpose inherits that pre-existing inaccuracy.
- `createExactRule()`'s `firstOrCreate` non-overwrite behavior means that even after this feature ships, a user confirming a *different* category than a previously (wrongly) auto-persisted exact rule for the same merchant will not actually change future auto-categorization for that merchant, unless this bug is fixed as part of (or explicitly deferred alongside) this change.
- No dedicated unit tests exist for `CategorizationService`'s cascade; changing its return shape or adding a ranking method has no existing regression net.
- Bulk-import callers of `findCategory()` will be affected by any signature change even though their behavior is explicitly out of scope — this must be resolved by an explicit decision, not left implicit.
- No per-user job serialization exists today; concurrent captures/answers for the same user during an outstanding question are unhandled by anything currently in place.

## Ready for Proposal

Yes, with two decisions `sdd-propose` must make explicitly (not re-litigating the owner's 5 decisions, but resolving gaps the owner's decisions didn't cover): (a) extend `CaptureChannel` vs. a separate Coaching outbound port for the "ask with buttons" capability, and (b) whether `findCategory()`'s signature changes for all 3 callers (with bulk imports pinned to their current auto-accept behavior) or a new method is added solely for the bot path.
