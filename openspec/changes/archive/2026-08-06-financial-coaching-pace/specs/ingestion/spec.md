# Ingestion — delta for `financial-coaching-pace`

Only what this change alters. Everything else in `openspec/specs/ingestion/spec.md`
stands unchanged.

## MODIFIED Requirement: A capture may be followed by one coaching message

A successful bot capture MAY be followed by at most one coaching message on the same
channel. The message is emitted by Financial Coaching, never by the capture path, and
never replaces or alters the capture's own confirmation.

*Traces to [QA-8](../../../../../docs/quality/quality-attributes.md#qa-8--proactive-engagement)
for the coaching itself, and to
[QA-1](../../../../../docs/quality/quality-attributes.md#qa-1--capture-friction) for the
limit of one.*

**Why:** the capture path stays the owner of capture. Coaching consumes the fact that a
transaction was registered and decides on its own whether anything is worth saying. If
capture composed the coaching message, the two subdomains the context map separates would
be fused at their first point of contact.

#### Scenario: A capture that crosses the line is confirmed, then coached

- **Given** a linked sender whose capture registers a `Transaction`
- **And** that transaction is the one that puts its category over the speaking threshold
- **When** the capture completes
- **Then** the sender SHALL receive the capture confirmation unchanged
- **And** SHALL receive at most one coaching message afterwards

#### Scenario: A capture that changes nothing is confirmed only

- **Given** a linked sender whose capture registers a `Transaction`
- **And** no category crosses a speaking threshold as a result
- **When** the capture completes
- **Then** the sender SHALL receive the capture confirmation and nothing else

#### Scenario: Coaching failing never breaks a capture

- **Given** a capture that registers a `Transaction` successfully
- **When** the coaching evaluation or its delivery fails for any reason
- **Then** the `Transaction` SHALL remain registered
- **And** the capture confirmation SHALL still be delivered
- **And** the capture job SHALL NOT be retried on account of the coaching failure

#### Scenario: An unlinked sender is never coached

- **Given** a sender with no Channel Identity
- **When** they message the bot
- **Then** the system SHALL reply with linking instructions only
- **And** SHALL NOT evaluate or emit any coaching message
