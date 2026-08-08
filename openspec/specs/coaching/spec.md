# Financial Coaching Specification — Pace-aware spending coach

- **Change:** `financial-coaching-pace`
- **Subdomain:** Financial Coaching (first capability — owns nothing before this change)
- **Date:** 2026-08-06

Terms are used exactly as defined in
[ubiquitous-language.md](../../../../docs/domain/ubiquitous-language.md). In particular
`monthly_budget`, `percentage` and `percentage_spent` are read, never redefined.

New terms introduced by this change:

- **Spoken Observation** — a record that the coach has told a user something about a
  category-month, holding its `kind`, `band`, and month, used to prevent repetition.
- **Pace** — the projected month-end total for a category, extrapolated from spend-to-date
  and elapsed days of the current month.
- **Band** — the severity of a pace observation, ordered `on_pace` < `projected_over` <
  `already_over`.
- **Level** — the current spend-to-budget ratio for a category, used instead of pace when
  projection would be misleading.
- **Blindness** — the state of a category whose `monthly_budget` is `0`, which structurally
  cannot raise a pace or level observation.

## Purpose

Financial Coaching turns the budget and Pareto data that Financial Analysis already computes
into an unprompted, factual statement about spending pace — never a rendering of numbers
already visible in a report, and never advice. This is the subdomain's first behaviour
([ADR-0007](../../../../docs/decisions/0007-telegram-primary-conversational-channel.md)).

## Requirements

### Requirement: Pace, not level, drives an observation

The system SHALL evaluate each budgeted expense category by projecting its month-end total
from spend-to-date and elapsed days, not from the current spend level alone.

*Traces to QA-8.*

#### Scenario: Same level, different day, opposite outcome

- **Given** a category at 340 of a 400 budget on day 12 of a 31-day month
- **When** the evaluation runs
- **Then** the system SHALL produce an observation naming the day, the spend, and the
  projected close

#### Scenario: Same level late in the month produces nothing

- **Given** the same category at the same 340/400 level on day 28
- **When** the evaluation runs
- **Then** the system SHALL NOT produce a pace observation

### Requirement: One decision, two entry points, one voice

The scheduled sweep and the capture-time check SHALL consult the same evaluation and SHALL
NOT hold separate rules; neither SHALL repeat what the other already said this month.

*Traces to QA-8, QA-1.*

#### Scenario: Capture-time check only concerns the just-registered category

- **Given** a transaction just registered in category "Comida"
- **When** the capture-time check runs
- **Then** the system SHALL evaluate only "Comida", and SHALL send at most one message,
  only if that transaction is what crossed the speaking threshold

#### Scenario: The sweep does not repeat a category already spoken today

- **Given** the capture-time check already sent a message about "Comida" in its current
  band this month
- **When** the scheduled sweep runs later the same day
- **Then** the system SHALL NOT include "Comida" in the sweep's message

#### Scenario: The sweep bounds how much it says

- **Given** five categories worth speaking about in one run
- **When** the sweep composes its message
- **Then** the system SHALL include at most 3 observations, ordered by severity, in a
  single message

### Requirement: The message states fact and cause, then stops

An observation SHALL state the category, the spend, the day, the projected close (or level,
for a lumpy category) and, when available, the merchants behind it. It SHALL NOT contain
advice, a suggestion, or a next step.

*Traces to QA-1, QA-2, QA-3.*

#### Scenario: A message includes cause when it exists

- **Given** a category over its pace threshold with 4 transactions at the same merchant
  covering 60% of its spend
- **When** the observation is composed
- **Then** the message SHALL name the merchant, the count, and the share, and SHALL NOT
  recommend any action

#### Scenario: No cause is never invented

- **Given** a category over its pace threshold with no repeated merchant
- **When** the observation is composed
- **Then** the message SHALL state the level and day without a merchant breakdown

#### Scenario: Declared allocation is never narrated as behaviour

- **When** any message is composed
- **Then** it SHALL NOT contain a Pareto bucket's `actual_percentage`; a
  declared-versus-real comparison, if present, SHALL only pair `percentage` with
  `percentage_spent`

### Requirement: A category with no budget is reported as unwatchable

A category whose `monthly_budget` is `0` SHALL NOT produce a pace or level observation;
spend inside it SHALL instead be surfaced as a distinct blindness observation.

*Traces to QA-8 (silence must not read as "you're fine").*

#### Scenario: Spend accrues where the coach cannot watch

- **Given** a category with `monthly_budget = 0` and spend recorded this month
- **When** the evaluation runs
- **Then** the system SHALL produce a blindness observation naming the category, and
  SHALL NOT attempt to compute pace or level for it

#### Scenario: Blindness speaks at most once a month

- **Given** a blindness observation already sent to a user this month
- **When** further spend lands in the same or another zero-budget category before the
  month ends
- **Then** the system SHALL NOT send a second blindness message that month

### Requirement: A dominant single transaction overrides projection

When one transaction is at least half of a category's month-to-date spend, the system
SHALL report the category's current level and name that transaction, and SHALL NOT project
a month-end close for it.

*Traces to QA-2, QA-3 (a lumpy category makes a linear projection false).*

#### Scenario: One large purchase suppresses projection

- **Given** a category where a single transaction is 70% of month-to-date spend and spend
  already exceeds budget
- **When** the evaluation runs
- **Then** the system SHALL report the level and identify that transaction, and SHALL NOT
  state a projected close
- **And** the largest-transaction share SHALL be computed from raw transaction data, never
  from a query that drops single-occurrence merchants

#### Scenario: A dominant transaction under budget stays silent

- **Given** a category where a single transaction is 70% of month-to-date spend but spend
  has not yet reached budget
- **When** the evaluation runs
- **Then** the system SHALL NOT produce an observation for that category

### Requirement: Speaking thresholds

The system SHALL only speak about a category when spend already exceeds `monthly_budget`,
or — on or after day 5 of the month, and only when the category is not lumpy — when the
projected close exceeds `monthly_budget` by at least 10%.

*Traces to QA-1 (noise risk).*

#### Scenario: Early-month overspend still speaks

- **Given** day 3 of the month and spend already exceeding budget
- **When** the evaluation runs
- **Then** the system SHALL produce an observation

#### Scenario: Early-month projection is suppressed

- **Given** day 3 of the month, spend under budget, and a projection that would exceed
  budget by more than 10%
- **When** the evaluation runs
- **Then** the system SHALL NOT produce an observation

#### Scenario: A projection under the margin stays silent

- **Given** day 12 of the month and a projected close that exceeds budget by 4%
- **When** the evaluation runs
- **Then** the system SHALL NOT produce an observation

### Requirement: Re-speaking only on escalation

For a given user, category and month, the system SHALL speak again only when the band
worsens (`on_pace` → `projected_over` → `already_over`), and SHALL NOT speak twice about
the same band.

*Traces to QA-1.*

#### Scenario: Same band, no repeat

- **Given** a category already spoken about in the `projected_over` band this month
- **When** the category is evaluated again and remains in `projected_over`
- **Then** the system SHALL NOT send another message

#### Scenario: Worse band, speaks again

- **Given** the same category, previously spoken about in `projected_over`
- **When** spend later crosses into `already_over`
- **Then** the system SHALL send a new message for the worse band

### Requirement: Expense categories only

The system SHALL evaluate pace, level and blindness only for categories of type `expense`;
only categories whose `type` is `expense` SHALL be evaluated. The column is an enum of `income`, `expense` and `transfer` — there is no `savings` value in this schema, so filtering positively on `expense` is both stricter and truthful.

*Traces to the owner's stated scope.*

#### Scenario: An income category is never evaluated

- **Given** a category of type `income` over any threshold
- **When** the evaluation runs
- **Then** the system SHALL NOT produce an observation for it

### Requirement: User-facing strings are in Spanish

Every message the coach sends SHALL be composed in Spanish, matching the register already
used by existing bot replies.

*Traces to product consistency with existing capture confirmations.*

#### Scenario: A pace message is rendered in Spanish

- **When** an observation is composed into a message
- **Then** the text SHALL be in Spanish

### Requirement: Delivery is best-effort, never retried

Since `reply()` on both channels logs and swallows send failures with no retry, the system
SHALL mark an observation as spoken once it decides to speak, independent of whether the
send succeeds, and SHALL record the delivery outcome without automatically retrying it.

*Traces to QA-1 (a silently retried message is a second, undecided message).*

#### Scenario: A failed send still counts against the speaking budget

- **Given** an observation the system has decided to speak
- **When** the send to the channel fails
- **Then** the system SHALL still record the observation as spoken for that band and
  month, and SHALL NOT attempt it again automatically

### Requirement: Reach is limited to a linked Telegram identity

The system SHALL only send a coaching message to a user with a Telegram channel identity;
a user reachable only through WhatsApp SHALL NOT be coached.

*Traces to QA-4, [ADR-0007](../../../../docs/decisions/0007-telegram-primary-conversational-channel.md).*

#### Scenario: A WhatsApp-only user is skipped without error

- **Given** a user with a WhatsApp channel identity and no Telegram identity
- **When** the sweep or the capture-time check considers that user
- **Then** the system SHALL skip them silently, SHALL NOT raise an error, and SHALL NOT
  attempt delivery on WhatsApp

#### Scenario: A user with both identities is reached on Telegram

- **Given** a user with both a WhatsApp and a Telegram channel identity
- **When** a message is due
- **Then** the system SHALL deliver it on Telegram

### Requirement: The sweep and the capture-time check never both speak

Because no per-user job serialisation exists, the system SHALL guarantee that only one
message is ever sent for the same user, category, and band within the same month,
regardless of which entry point evaluates it or how close in time.

*Traces to QA-1, QA-3.*

#### Scenario: Concurrent evaluation produces one message, not two

- **Given** the sweep and the capture-time check both decide, at nearly the same moment,
  to speak about the same user's category in the same band
- **When** both attempt to record the observation
- **Then** the system SHALL send exactly one message, and the second attempt SHALL be
  recognised as already spoken

## Out of scope

Stated explicitly so a reader does not infer them:

- **Any advice, suggestion, or next step.** The coach narrates; it does not recommend.
- **Budget warnings by any measure other than pace, level, or blindness.**
- **Pareto narration as a conversation.** Reading the Pareto shape aloud is a separate
  change.
- **Clarifying questions about categorisation.** Shelved with `financial-coaching-clarify`.
- **Interactive buttons or reply handling of any kind.** The coach speaks and stops.
- **WhatsApp coaching.** Telegram only, per ADR-0007.
- **Reviving `KeywordRule`.** Unrelated to this capability.
