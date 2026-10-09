# Shopping Specification — Weekly grocery planning

- **Change:** `grocery-planning`
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
declared habit, equal to weekly quantity minus available pantry quantity. *(QA-3)*

| Scenario | Given | When | Then |
|---|---|---|---|
| Partial coverage | habit `2 kg`, `0.5 kg` available | list computed | shows `1.5 kg` remaining |
| Full coverage | habit `1 kg`, `≥1 kg` available | list computed | product excluded |

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

With no `grocery_budget_links` row the ceiling SHALL report absent; linked,
`monthly_budget` and spend via `expenseByCategoryBetween()` SHALL be read by `category_id`;
an exceeded ceiling SHALL state overspend without hiding or reordering lines. *(QA-3)*

| Scenario | Given | When | Then |
|---|---|---|---|
| No link | no link row | list requested | ceiling absent; list still computed |
| Ceiling exceeded | linked category over `monthly_budget` | list computed | overspend stated; every needed product shown |

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

## Out of scope

Seasonality, live prices, writing a `Transaction`, receipt basket lines, unit conversion,
non-food products — per the proposal's scope boundary.

> **Note (grocery-prices, in progress).** "Live prices" no longer belongs in this list:
> the weekly plan now annotates each line with a price and the plan with an estimate
> (`price`, `wholesale_trend`, `estimate`; cost is proportional to the quantity still
> needed, not pack-rounded). The formal requirement changes are merged into this spec
> when the `grocery-prices` change is archived; the other items stay out of scope.
