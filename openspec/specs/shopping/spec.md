# Shopping Specification — Weekly grocery planning

- **Change:** `grocery-planning`
- **Amended by:** `grocery-prices` (archived 2026-10-08): weekly plan annotated with prices, estimate and ceiling comparison
- **Subdomain:** Shopping (new, `kind: supporting`)
- **Date:** 2026-09-26

Terms per [ubiquitous-language.md](../../../../docs/domain/ubiquitous-language.md).
`Transaction`/`Category` are read, never redefined.

New terms: **Product** — global, one canonical unit, not a `Detail`. **Pantry Item** —
per-user quantity at home with an `acquisition_source`, never a `Transaction`.
**Consumption Habit** — declared weekly need (product, quantity, unit). **Grocery Budget
Link** — per-user pin of a resolved `category_id` for the ceiling read.

## Purpose

Compute, on read, what a user still needs to buy this week (habit minus pantry) and report
the grocery ceiling from a pinned `Category`, without writing back to Financial Analysis.

## Requirements

### Requirement: Seeded global catalogue with private extension

`products` SHALL carry a nullable `user_id`: seeded rows are global (`user_id IS NULL`) and
visible to everyone, each with one canonical unit. A user MAY add a missing product, which
SHALL be owned by that user and visible only to them. A user's visible catalogue is the
global rows plus their own. *(QA-7, QA-4)*

| Scenario | Given | When | Then |
|---|---|---|---|
| Shared catalogue | seeded global products | any user reads catalogue | all users see the same global list |
| User extends it | product missing | user submits it + unit | created owned by that user |
| Extension stays private | user A added a product | user B reads catalogue | user B does not see it |

### Requirement: Declared consumption habit

A user SHALL declare a weekly quantity and unit per product, scoped to that user; the unit
MUST match the product's canonical unit. *(QA-3)*

| Scenario | Given | When | Then |
|---|---|---|---|
| Habit declared | product, unit `kg` | user declares `2 kg`/week | persisted for that user |
| Unit mismatch | product, unit `kg` | user declares in `l` | rejected, not persisted |

### Requirement: Pantry item recording

A user SHALL record a pantry item — product, quantity, unit, `acquisition_source`
(`purchased \| gift \| harvested`), optional `expires_on` — scoped to that user; unit MUST
match the product's canonical unit. *(QA-3)*

| Scenario | Given | When | Then |
|---|---|---|---|
| Item recorded | product, unit `unidad` | records `1 unidad`, `purchased` | persisted with its source |
| Unit mismatch | product, canonical unit | records in a different unit | rejected, not persisted |

### Requirement: Weekly list derivation

The list SHALL be computed on read only, never persisted: one line per product with a
declared habit, equal to weekly quantity minus available pantry quantity. Each line SHALL
additionally carry a `price` annotation and a `wholesale_trend` annotation (both nullable,
defined in "Per-line price annotation"). Annotations MUST NOT add, remove, filter, reorder
or change the quantity of any line. *(QA-3)*

| Scenario | Given | When | Then |
|---|---|---|---|
| Partial coverage | habit `2 kg`, `0.5 kg` available | list computed | line to_buy `1.5 kg` |
| Full coverage | habit `1 kg`, `≥1 kg` available | list computed | product in `covered`, not in lines; covered entries carry no price |
| Prices never change lines | same habits, pantry and week; run A with no quotes, run B with quotes for every product | both computed | the set, order, to_buy and units of lines are identical in A and B |

### Requirement: Expired items excluded from availability

An item past `expires_on` SHALL NOT count as available and SHALL remain stored — neither
deleted nor auto-archived. *(QA-3)*

| Scenario | Given | When | Then |
|---|---|---|---|
| Expired item reappears | item fully covering a habit, now expired | list computed | product reappears; row still exists |

### Requirement: Gift subtracts from list, not budget

A `gift` item SHALL reduce list quantity like any source but SHALL NOT affect spend or
budget; no pantry item, any source, SHALL touch a `Transaction`. *(QA-4)*

| Scenario | Given | When | Then |
|---|---|---|---|
| Gift removes product | habit fully covered by a `gift` item | list computed | product excluded; no `Transaction` touched |

### Requirement: Ceiling is absent, exceeded, or present — never zero

Ceiling behaviour of phase 1 is preserved: `ceiling_state` is `unlinked | unbudgeted | set`,
and `unlinked` and `unbudgeted` are never reported as 0. With no `grocery_budget_links` row
the ceiling SHALL report absent; linked, `monthly_budget` and spend via
`expenseByCategoryBetween()` SHALL be read by `category_id`; an exceeded ceiling SHALL state
overspend without hiding or reordering lines. The plan SHALL additionally expose `estimate`
(see "Partial-aware estimate"). The ceiling value MUST derive only from the ceiling input,
never from quotes. *(QA-3)*

| Scenario | Given | When | Then |
|---|---|---|---|
| No link | no link row | plan requested | ceiling_state `unlinked`; lines still computed |
| Unbudgeted | linked category with no `monthly_budget` | plan requested | ceiling_state `unbudgeted`, no numeric ceiling; lines computed |
| Ceiling exceeded | linked category over `monthly_budget` | list computed | overspend stated; every needed product shown |
| Ceiling independent of prices | linked, budgeted category | plan computed with and without quotes | ceiling object identical in both |

### Requirement: Lazy-resolve-then-pin lifecycle

On first use with no link, the system SHALL resolve the category by name once and persist
`category_id`; later reads SHALL use the pin, never the name. Deleting it SHALL
cascade-delete the link, returning to no-link. *(QA-3)*

| Scenario | Given | When | Then |
|---|---|---|---|
| First use pins | no link; a matching category | ceiling first requested | `category_id` persisted as pin |
| Rename is irrelevant | a pinned `category_id` | category renamed | ceiling still reads same id |
| No match stays absent | no link; no matching category | ceiling requested | absent, plus a prompt to choose |
| Delete returns to no-link | a pinned `category_id` | category deleted | link cascades away; ceiling absent |

### Requirement: Per-user isolation on scoped resources

`$userId` SHALL be an authorization boundary, not a filter, for `pantry_items`,
`consumption_habits`, `grocery_budget_links`; a caller SHALL NOT reach another user's row
by id. `products` SHALL stay global. *(QA-4)*

| Scenario | Given | When | Then |
|---|---|---|---|
| Cross-user access denied | a pantry item owned by user A | user B requests it by id | not returned |

### Requirement: Per-line price annotation

Each line SHALL expose `price` as either `null` (price context absent: no quotes supplied) or
an object `{unit_price, estimated_cost, state, basis, source, source_label, period_start,
period_end, attribution, alternatives[]}`. `state` MUST be one of `fresh | stale | unknown`.
For `unknown`, `unit_price`, `estimated_cost`, `source` MUST be null and `unknown` MUST NEVER
be rendered as, or serialised as, the number 0. `source_label`, `attribution` and the period
MUST be present whenever `state` is `fresh` or `stale`. `basis` MUST be `measured | equivalence`.
`unit_price` is per the line's canonical unit.

| Scenario | Given | When | Then |
|---|---|---|---|
| Fresh quote | product with fresh Plaza Vea quote 8.90/kg, to_buy 1.5 kg | plan computed | price.state `fresh`, unit_price 8.90, estimated_cost 13.35, source `plazavea`, attribution and period non-empty |
| Stale quote still priced | only a stale quote | plan computed | price.state `stale`, unit_price and estimated_cost set, period shows the old date |
| No quote | product without any usable quote | plan computed | price.state `unknown`; unit_price, estimated_cost null; response contains no `0` price for that line |
| User-created product | product with slug NULL | plan computed | price.state `unknown` (no mapping exists) |
| Equivalence basis | quote derived from a curated equivalence | plan computed | price.basis `equivalence`; a measured quote shows `measured` |
| Unit mismatch ignored | stored row `unit` = kg but the product's unit is now `unidad` | plan computed | that row is not used; line unknown (never mis-scaled) |

### Requirement: Proportional cost arithmetic

`estimated_cost` SHALL equal `round(to_buy * unit_price, 2)`, proportional to the quantity
still needed, NOT pack- or basket-rounded. The spec and API docs MUST state it is proportional.
Rounding is half-up to 2 decimals. Unit prices are computed from the stored (higher-precision)
value; user quantities are never unit-converted.

| Scenario | Given | When | Then |
|---|---|---|---|
| Proportional | to_buy 0.5 kg, unit_price 7.99 | cost computed | estimated_cost 4.00 (3.995 rounded half-up) |
| Not pack-rounded | to_buy 0.1 kg, 1 kg pack priced 13.50/kg | cost computed | estimated_cost 1.35 |
| No precision loss | stored unit_price 4.3333 (3.90 / 0.9 kg), to_buy 3 | cost computed | estimated_cost 13.00 |

### Requirement: Partial-aware estimate

The plan SHALL expose `estimate{total, priced_lines, unpriced_lines, is_partial,
ceiling_comparison}`, or `estimate: null` when there is no price context. `total` SHALL be the
sum of `estimated_cost` over lines whose state is `fresh` or `stale`; `unknown` lines MUST be
excluded and counted in `unpriced_lines`. `is_partial` MUST be true if and only if
`unpriced_lines > 0`. When the list HAS lines but none is priced, `total` MUST be null (not 0).
When the list has NO lines, `total` is 0, both counts are 0, and `is_partial` is false.

| Scenario | Given | When | Then |
|---|---|---|---|
| All priced | 3 lines all priced (10.00, 5.50, 4.50) | plan computed | total 20.00, priced 3, unpriced 0, is_partial false |
| Partial | 3 lines, 1 unknown | plan computed | total = sum of 2 priced, priced 2, unpriced 1, is_partial true |
| Lines but none priced | 2 lines, both unknown | plan computed | total null, priced 0, unpriced 2, is_partial true |
| Empty list | no lines | plan computed | total 0, priced 0, unpriced 0, is_partial false |
| Stale counts | 1 stale + 1 fresh line | plan computed | both included in total |

### Requirement: Ceiling comparison only when the ceiling is set

`estimate.ceiling_comparison` SHALL be `{remaining, remaining_after_estimate, would_exceed}`
only when `ceiling_state = set` AND `total` is not null; otherwise it is null.
`remaining_after_estimate = remaining - total`; `would_exceed = remaining_after_estimate < 0`.
When `is_partial` is true the comparison MUST still be returned; because unpriced lines are
excluded, the figure is a LOWER BOUND on spend, and consumers MUST label it as such (see
shopping-ui). An empty list (total 0) with ceiling set returns the comparison with
`remaining_after_estimate = remaining`.

| Scenario | Given | When | Then |
|---|---|---|---|
| Fits | ceiling set, remaining 100, total 60 | plan computed | remaining_after_estimate 40, would_exceed false |
| Exceeds | remaining 50, total 60 | plan computed | remaining_after_estimate -10, would_exceed true |
| Partial still returned | ceiling set, remaining 100, is_partial true, total 60 | plan computed | comparison present (remaining_after_estimate 40) alongside is_partial true, so the consumer can label it a lower bound |
| Unlinked | ceiling_state unlinked | plan computed | ceiling_comparison null |
| Unbudgeted | ceiling_state unbudgeted | plan computed | ceiling_comparison null |
| No priced lines | ceiling set, lines exist, total null | plan computed | ceiling_comparison null |
| Empty list | ceiling set, no lines, total 0 | plan computed | comparison present, remaining_after_estimate = remaining, would_exceed false |

### Requirement: Wholesale trend annotation

A line MAY carry `wholesale_trend{direction (up|down|flat), change_pct, source, as_of}` or
null. It is informational and MUST NOT affect `unit_price`, `estimated_cost`, `estimate` or
`ceiling_comparison`.

| Scenario | Given | When | Then |
|---|---|---|---|
| Trend present | fresh single-source wholesale series with rise | plan computed | wholesale_trend.direction `up`, change_pct set |
| Trend does not price | product with trend but no retail quote | plan computed | price.state `unknown`; estimate excludes it |

### Requirement: Phase-1 guarantees preserved (q6)

`ShoppingPlanService` SHALL remain pure (values in, decisions out: no models, no `now()`, no
DB; BoundariesTest keeps passing). Quotes SHALL enter as an optional value input. Lines descend
only from (habits, pantry, week); the ceiling only from its ceiling input; prices annotate,
never filter or reorder. The plan endpoint MUST NOT fail or change status because of price
data (all sources off, no rows, or a price repository exception degrades to
`price: null` / unknown). With no quotes supplied the response MUST be the phase-1 shape plus
null price keys.

| Scenario | Given | When | Then |
|---|---|---|---|
| Pure service | quotes passed as values | BoundariesTest runs | service imports no Model, DB or now() |
| All sources off | every source disabled | GET weekly-plan | 200; lines and ceiling identical to phase 1; every price unknown or null; no error |
| Price read fails | price repository throws | GET weekly-plan | 200 with lines and ceiling; price annotations degrade, failure reported to Sentry |
| Lines independent | different quote sets, same inputs | plan computed twice | line list identical |

### Requirement: Cross-user isolation unchanged for prices

Price observations are global. The plan SHALL expose only quotes for products on the
requesting user's lines. A user-created product of user A MUST NOT leak into user B's plan.

| Scenario | Given | When | Then |
|---|---|---|---|
| Private product | user A product with slug NULL | user B requests plan | the product does not appear |

## Out of scope

Seasonality, writing a `Transaction`, receipt basket lines, unit conversion on user quantities,
non-food products. Manual user price entry, basket/pack-rounded cost, price alerts and history
charts are out of this change. "Live prices" was removed from this list by `grocery-prices`
(prices now annotate the weekly plan; see the requirements above).
