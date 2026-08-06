# Delta spec — Ingestion — Telegram capture channel

- **Change:** `telegram-capture-channel`
- **Subdomain:** Ingestion
- **Date:** 2026-08-06

Terms are used exactly as defined in
[`../../../../../../docs/domain/ubiquitous-language.md`](../../../../../../docs/domain/ubiquitous-language.md).
In particular **`Detail` is the normalised counterparty**, not a line item, and one
`Detail` has many `Transaction`s.

New terms introduced by this change:

- **Capture** — one inbound artifact (photo or text) offered by a user through a channel,
  from which at most one `Transaction` is derived.
- **Channel Identity** — the binding between an external channel account and a `User`,
  keyed by `(channel, external_id)`.
- **Link Token** — a signed, single-use, expiring credential that authorises creating a
  Channel Identity.

---

## ADDED Requirements

### Requirement: Webhook authenticity

The system SHALL reject any capture delivery that does not prove it originated from the
channel provider.

*Traces to QA-4 (channel safety).*

#### Scenario: A delivery carrying the registered secret token is accepted

- **Given** a secret token registered with the channel provider
- **When** a delivery arrives with a matching `X-Telegram-Bot-Api-Secret-Token` header
- **Then** the system SHALL process it

#### Scenario: A delivery with a wrong or missing token is rejected

- **Given** the same registered secret token
- **When** a delivery arrives with a mismatched or absent token header
- **Then** the system SHALL reject it, SHALL NOT create a `Transaction`, SHALL NOT enqueue
  any work, and SHALL NOT reply to any chat

#### Scenario: The comparison resists timing analysis

- **When** the header is compared against the configured token
- **Then** the comparison SHALL be constant-time

#### Scenario: No configured token fails closed

- **Given** no secret token is configured
- **When** any delivery arrives
- **Then** the system SHALL reject it and SHALL log the misconfiguration distinctly from a
  token mismatch

---

### Requirement: Exactly-once capture

The system SHALL derive at most one `Transaction` from one channel update, regardless of
how many times that update is delivered.

*Traces to QA-3 (data integrity) and QA-6 (AI cost).*

#### Scenario: A replayed update is ignored

- **Given** an update whose `update_id` has already been accepted
- **When** the same `update_id` is delivered again
- **Then** the system SHALL NOT create a second `Transaction`, SHALL NOT call any AI
  service, and SHALL acknowledge the delivery successfully

#### Scenario: Deduplication survives a restart

- **Given** an accepted `update_id`
- **When** the application is restarted and the update is redelivered
- **Then** the system SHALL still recognise it as already seen

> Rationale: the record MUST be persisted, not held in process memory or a volatile cache.

#### Scenario: Deduplication is decided before any paid work

- **When** a duplicate update arrives
- **Then** the duplicate SHALL be rejected before any queue job is dispatched

---

### Requirement: Complete update processing

The system SHALL process every capture contained in a delivery.

*Traces to QA-3 (data integrity).*

#### Scenario: A batched delivery is fully processed

- **Given** a delivery containing three distinct updates carrying messages
- **When** it is received
- **Then** the system SHALL process all three

#### Scenario: Unsupported update kinds are ignored deliberately

- **Given** a delivery containing an update the system does not handle
- **When** it is received
- **Then** the system SHALL ignore that update, SHALL record that it was ignored, and
  SHALL continue processing the remaining updates

---

### Requirement: Channel identity resolution

The system SHALL resolve the sender of a capture to a `User` through a Channel Identity,
and SHALL NOT trust any user-supplied identifier carried in the message body.

*Traces to QA-4 (channel safety).*

#### Scenario: A linked sender is resolved

- **Given** a Channel Identity binding a channel account to a `User`
- **When** that account sends a capture
- **Then** the system SHALL attribute the resulting `Transaction` to that `User`

#### Scenario: An unlinked sender creates nothing

- **Given** a channel account with no Channel Identity
- **When** it sends a capture
- **Then** the system SHALL NOT create a `Transaction`, and SHALL reply explaining how to
  link the account

#### Scenario: One channel account binds to at most one user

- **Given** a channel account already bound to a `User`
- **When** a Link Token for a different `User` is redeemed from that account
- **Then** the system SHALL refuse the binding

---

### Requirement: Account linking

The system SHALL bind a channel account to a `User` only on redemption of a valid Link
Token.

*Traces to QA-4 (channel safety).*

#### Scenario: A valid token links the account

- **Given** an unexpired, unredeemed Link Token issued for a `User`
- **When** it is redeemed from a channel account
- **Then** the system SHALL create the Channel Identity and SHALL confirm to the sender

#### Scenario: A token cannot be reused

- **Given** a Link Token that has already been redeemed
- **When** it is presented again
- **Then** the system SHALL refuse it

#### Scenario: A token expires

- **Given** a Link Token older than its validity window
- **When** it is presented
- **Then** the system SHALL refuse it

#### Scenario: A forged token is refused

- **Given** a token whose signature does not verify
- **When** it is presented
- **Then** the system SHALL refuse it and SHALL NOT disclose whether the referenced `User`
  exists

---

### Requirement: Photo capture

The system SHALL derive a `Transaction` from a photo sent by a linked user.

*Traces to QA-1 (capture friction) and QA-2 (categorisation accuracy).*

#### Scenario: A recognisable payment screenshot produces a transaction

- **Given** a linked user
- **When** they send a photo of a Yape movement
- **Then** the system SHALL create a `Transaction` attributed to them, resolving the
  counterparty to a `Detail` and applying the categorisation cascade unchanged
- **And** SHALL reply confirming the amount and counterparty

#### Scenario: The highest available resolution is used

- **Given** a photo offered by the channel in several resolutions
- **When** it is retrieved
- **Then** the system SHALL select the largest variant within the channel's size limit,
  and SHALL record which variant was used

> Rationale: selecting a thumbnail silently degrades QA-2 with no visible failure.

#### Scenario: An unrecognisable image creates nothing

- **Given** a linked user
- **When** they send an image that is not a payment document
- **Then** the system SHALL NOT create a `Transaction`, and SHALL reply saying so

---

### Requirement: Text capture

The system SHALL derive a `Transaction` from a message in which a linked user states a
movement in their own words.

*Traces to QA-1 (capture friction).*

#### Scenario: A stated movement produces a transaction

- **Given** a linked user
- **When** they send a message stating an amount and a counterparty
- **Then** the system SHALL create a `Transaction` attributed to them
- **And** SHALL reply confirming it

#### Scenario: An unclear message creates nothing

- **Given** a linked user
- **When** they send a message from which no amount can be determined
- **Then** the system SHALL NOT create a `Transaction`, and SHALL reply saying what was
  missing

---

### Requirement: Every capture is answered

The system SHALL reply to the sender for every capture it accepts, whether it succeeded or
not.

*Traces to QA-1 (capture friction).*

#### Scenario: An unexpected failure is still answered

- **Given** a capture that fails for an unforeseen reason
- **When** processing ends
- **Then** the system SHALL reply that the capture could not be processed

> Silence is the worst outcome: the user cannot distinguish "not received" from "received
> and lost", and will send it again.

---

## MODIFIED Requirements

### Requirement: Channel-independent registration

Registering a captured movement — resolving the `Detail`, applying the categorisation
cascade and persisting the `Transaction` — SHALL be independent of the channel the capture
arrived through.

*Traces to QA-7 (channel modifiability).*

> This behaviour exists today in `RegisterWhatsAppTransactionAction`, whose name is the
> only channel-specific thing about it. This change makes the independence explicit rather
> than accidental.

#### Scenario: The same movement registers identically from any channel

- **Given** the same parsed movement
- **When** it is registered from any capture channel
- **Then** the resulting `Transaction` SHALL be identical apart from its recorded source

#### Scenario: Adding a channel does not change registration

- **When** a new capture channel is added
- **Then** no change SHALL be required in Entity Resolution, Categorisation or Financial
  Analysis

---

## Out of scope

Stated explicitly so a reader does not infer them:

- **Financial Coaching** — no proactive message, no question asked by the system, no
  conversational state. Separate change.
- **Factura and boleta recognition** — `ParsedReceiptDTO` continues to model a
  single-amount movement. Separate change (finding F5).
- **A `KeywordRule` write path** — cascade steps 3 and 4 remain unreachable (finding F7).
  Product decision.
- **Rate limiting per sender.** Recorded as a follow-up.
