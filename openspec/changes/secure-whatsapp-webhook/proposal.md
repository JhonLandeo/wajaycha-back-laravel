# Proposal — Secure the WhatsApp webhook

- **Change:** `secure-whatsapp-webhook`
- **Date:** 2026-08-06
- **Owning subdomain:** Ingestion
- **Depends on:** [ADR-0006](../../../../docs/decisions/0006-whatsapp-cloud-api-dedicated-number.md) (accepted — official Meta Cloud API on a dedicated number)
- **Source:** [`exploration.md`](exploration.md), findings F1–F4

## Problem

`POST whatsapp/webhook` accepts unauthenticated requests and derives the acting user
entirely from the request body. Anyone who learns the endpoint URL can write
`Transaction` rows into any registered user's account by naming their phone number.

Three further defects compound it: retried deliveries duplicate transactions, batched
deliveries are silently dropped, and the phone number that serves as identity is stored
in an unnormalised format that may never match.

This is the ingestion channel for real users' financial data, and the capability cannot
be launched in this state.

## Why now, and why alone

The user's stated goal is recognising facturas, boletas, Yape screenshots and typed
messages. Recognition work is worthless on an endpoint that anyone can write to, so this
change is sequenced first and kept separate — a security fix and a domain-modelling
question do not belong in one reviewable unit.

## Drivers

Traced to `../docs/quality/quality-attributes.md`:

| Driver | Rank | How this change serves it |
|---|---|---|
| **QA-4** channel safety | 1 | Only Meta can deliver into the channel |
| **QA-3** data integrity | 2 | One real-world message yields at most one `Transaction`; no message is silently lost |
| **QA-1** capture friction | 4 | A correctly linked number stops failing to match, removing a dead end the user cannot diagnose |

## Scope

### In

1. **Verify Meta's request signature.** Reject any `POST whatsapp/webhook` whose
   `X-Hub-Signature-256` does not match an HMAC-SHA256 of the raw body computed with the
   Meta app secret. Constant-time comparison. Requires a new `services.whatsapp.app_secret`.
2. **Idempotency by `wamid`.** Persist the Meta message identifier and refuse to process
   a message already seen. Survives process restarts, so it is stored, not cached.
3. **Process the whole payload.** Iterate every `entry`, `change` and `message` rather
   than reading index `0`. Non-message events such as delivery `statuses` are ignored
   explicitly rather than by accident.
4. **Normalise `whatsapp_phone`.** One canonical format (E.164 digits, no `+`, no
   spaces) applied on write and on lookup, with a migration normalising existing rows.

### Out — deliberately

| Excluded | Why |
|---|---|
| Factura and boleta recognition | Finding F5. A domain-modelling question, not a security one. It is the next change |
| Extracting the Entity Resolution service | Finding F6. ADR-0005 permits it only when a change already touches those call sites; this change does not |
| A `KeywordRule` write path | Finding F7. A product decision for the owner, with a standing QA-6 cost |
| Rate limiting per sender | Real, but distinct from authentication. Record as follow-up |

## Cross-subdomain note

`openspec/config.yaml` requires justifying a change that spans owning services. Items 1–3
are Ingestion. Item 4 touches `users.whatsapp_phone`, owned by Access.

It is included because the security model of items 1–3 rests on the phone number being a
reliable identity. Verifying that a request genuinely came from Meta accomplishes little
if the number it carries cannot be matched to the right user. The Access-side change is
one normalisation rule plus a data migration; it introduces no behaviour to that subdomain.

## Approach

Signature verification belongs in **middleware**, not the controller: it is a boundary
concern, it must run before any body parsing decision, and expressing it as middleware
keeps `WhatsAppController` an orchestrator per the architectural rule.

Idempotency is checked **at dispatch**, in the controller, so a duplicate never reaches a
queue worker and never spends a Gemini call (QA-6).

Both jobs already resolve the user themselves; normalisation must be applied at both the
write site and the lookup site or the two will disagree.

## Risks

| Risk | Mitigation |
|---|---|
| A missing or wrong `WHATSAPP_APP_SECRET` in production makes every delivery fail closed | Correct default for a security control. Deployment checklist must set it before the webhook is subscribed. Failures are logged distinctly from signature mismatches |
| The normalisation migration mangles an existing malformed number | The column is `unique`; a collision aborts the migration. Log every row changed, and report rows that cannot be normalised instead of guessing |
| Meta's signature covers the exact raw body; any middleware that re-serialises breaks it | Compute the HMAC over `$request->getContent()`, never over `$request->all()` |
| A new table for `wamid` grows unbounded | Store only identifier and timestamp, and prune on a schedule. Size the retention window to Meta's retry window, not forever |

## Rollback

Required by `openspec/config.yaml` because this touches a migration and the queue path.

- **Middleware and controller changes** revert by reverting the commit; no persisted state depends on them.
- **The `wamid` table** is additive. Rolling back the code leaves an unused table; dropping it loses only replay protection for messages already processed.
- **The phone normalisation migration is the only destructive step.** Its `down()` cannot restore the original strings, so the migration must first copy the pre-normalisation value into a nullable column and keep it until the change is archived.
- If verification must be disabled urgently in production, removing the middleware from the route restores current behaviour without a database change.

## Success

- A request without a valid signature never creates a `Transaction`.
- Delivering the same `wamid` twice yields exactly one `Transaction`.
- A payload carrying three messages produces three dispatches.
- A user whose stored number differs only in formatting is matched.
- Existing behaviour for a legitimate, correctly signed message is unchanged.

## Open question for the owner

Currently an unlinked number receives "actualiza tu perfil en la app". Once signature
verification lands, that reply is only reachable by someone who genuinely messaged the
business number but never linked it — a real prospective user. Should the system stay
silent, keep the current reply, or start a linking flow? This does not block the change;
the current reply is retained unless decided otherwise.
