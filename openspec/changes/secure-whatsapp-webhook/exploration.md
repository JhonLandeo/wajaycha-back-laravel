# Exploration — WhatsApp capture channel

- **Date:** 2026-08-06
- **Scope explored:** the whole WhatsApp capture idea (four input kinds). The change that follows is deliberately narrower — see `proposal.md`.
- **Method:** direct reading of the repository. Every claim below is marked `verified` (read in the code) or `inferred`.

## The idea as stated

A user sends something to a WhatsApp chat and the system registers a `Transaction`. Four input kinds:

1. photo of a **factura**
2. photo of a **boleta**
3. screenshot of a **Yape** movement
4. **manually typed** message stating the transaction

## Existing surface

`verified` — a substantial implementation already exists and is better structured than
`PLAN_REFACTORIZACION.md` claims (that audit is stale, ADR-0003):

| Component | State |
|---|---|
| `WhatsAppController::verify` / `receive` | Webhook endpoints, routed under `whatsapp/` prefix |
| `ProcessWhatsAppImage`, `ProcessWhatsAppMessage` | Queued jobs; both delegate to services and an Action rather than inlining logic |
| `GeminiVisionService::parseReceipt`, `GeminiTextService::parseText` | AI parsing, prompts held in the service |
| `MetaMediaService::downloadMedia` | Media retrieval from the Graph API |
| `RegisterWhatsAppTransactionAction` | Resolves the `Detail`, categorises, persists the `Transaction` |
| `ParsedReceiptDTO`, `IncomingMessageDTO` | Parse results |
| `WhatsAppNotificationService::sendTextMessage` | Outbound replies |
| `users.whatsapp_phone` | `string(20)`, nullable, unique |
| `tests/Feature/WhatsApp/` | Webhook verification + Action tests |

## Findings

### F1 — The webhook has no authentication `verified` · SEVERITY: highest

`routes/api.php` registers `POST whatsapp/webhook` inside a prefix group with **no
middleware**. A repository-wide search for `X-Hub-Signature-256`, `app_secret`, `hmac`
and `sha256=` returns nothing: Meta signs every webhook delivery and the signature is
never verified.

Caller identity comes entirely from the request body:

```php
$from = $message['from'];                                  // WhatsAppController::receive
$user = User::where('whatsapp_phone', $this->from)->first(); // both jobs
```

Consequence: anyone who learns the endpoint URL can POST a forged payload naming any
registered phone number and write `Transaction` rows into that user's account. The `GET`
verification endpoint *is* protected by the verify token; the `POST` that mutates
financial data is not.

Hits **QA-4 (channel safety)** and **QA-3 (data integrity)** — the two highest-ranked drivers.

### F2 — No idempotency `verified`

The Meta payload carries `wamid`, a per-message identifier. Nothing in `app/` or
`database/migrations/` references `wamid`, `message_id` or `messageId`. Meta retries
deliveries on timeout or non-200, so a retried delivery creates a second `Transaction`
for the same real-world movement.

This is not the ambiguous-matching case QA-3 discusses; it is deterministic duplication.

### F3 — Only the first message of a batch is processed `verified`

```php
$message = $body['entry'][0]['changes'][0]['value']['messages'][0] ?? null;
```

`entry`, `changes` and `messages` are all arrays. Meta may batch. Everything past the
first element is silently dropped — no log, no reply.

### F4 — Phone matching is an exact string comparison `verified`

`whatsapp_phone` is validated only as `nullable|string|max:20|unique` at registration and
is never normalised. Meta sends E.164 without a leading `+` (e.g. `51987654321`).

`inferred`: a user who typed `+51 987 654 321` or `987654321` will never match, and will
receive "tu número de WhatsApp no está vinculado" on every message with no way to
diagnose it. This also means the identity that F1 lets an attacker impersonate is itself
stored in an unconstrained format.

### F5 — Facturas and boletas are not actually supported `verified`

`GeminiVisionService` claims to read "recibo o comprobante (Yape/Plin, etc.)" but every
rule in its prompt is Yape semantics: `"¡Yapeaste!"`, `"Te yapeó"`, `"Pago a"`.

`ParsedReceiptDTO` carries exactly seven fields — `isValid`, `amount`, `destination`,
`origin`, `dateOperation`, `type`, `message`. There is no RUC, no IGV, no line items and
no issuer.

A factura is a different document: it has an issuer identified by RUC and legal name, a
tax breakdown, and possibly many line items, and the user is the acquirer rather than a
"destination". `inferred`: sending one today yields an amount plus invented
origin/destination values.

Supporting facturas and boletas is therefore **not a prompt tweak**. It requires deciding
what part of such a document the domain cares about — total plus merchant (the current DTO
nearly suffices) or per-item detail (the domain model changes).

### F6 — Entity Resolution duplication confirmed `verified`

`findExistingDetail()` and the surrounding resolve-or-create-`Detail` block are duplicated
between `RegisterWhatsAppTransactionAction` and `Imports/TransactionYapeImport`, both
hardcoding a trigram threshold of `0.6`, while `CategorizationService` declares an unused
`THRESHOLD_TRIGRAM = 0.4`. Matches `../docs/architecture/technical-debt.md`.

Per ADR-0005 this change touches Ingestion and is therefore the legitimate trigger to
extract it — but only once a change actually modifies those call sites.

### F7 — Cascade steps 3 and 4 are unreachable `verified` · flagged, not fixed here

`KeywordRule` has no write path: no route, controller, seeder or factory creates one.
Steps 3 and 4 of the categorisation cascade can therefore never match in production, so
every uncategorised transaction falls through to a paid Gemini vector lookup — a standing
**QA-6** cost that is invisible today. Product decision, out of scope for this change.

### F8 — The reply contract is already complete `verified`

Both jobs reply to the user on: unlinked number, media download failure, AI failure,
invalid receipt, success, and unexpected failure via `failed()`. This is in better shape
than expected and needs no work.

## Coverage summary

| Input kind | Status |
|---|---|
| Yape screenshot | covered |
| Manually typed message | covered |
| Factura | **not covered** (F5) |
| Boleta | **not covered** (F5) |

## Options considered for sequencing

| Option | Assessment |
|---|---|
| Ship everything as one change | Rejected. Mixes a security fix with a domain-modelling question, and would exceed the review budget |
| Facturas and boletas first | Rejected. Builds document recognition on top of an endpoint anyone can write to |
| **Webhook security first** | **Chosen.** Small, urgent, independently valuable, and unblocks everything after it |

## Next

`proposal.md` — scoped to F1–F4. F5 becomes the following change; F6 rides with it; F7 is
recorded as a product decision for the owner.
