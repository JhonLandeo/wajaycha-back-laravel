# Exploration — financial-coaching-pace

- **Change:** `financial-coaching-pace`
- **Date:** 2026-08-06
- **Store:** hybrid — mirrored from Engram `sdd/financial-coaching-pace/explore`

## What the owner wants

The bot makes him conscious of his spending, using the budgets and Pareto that already
exist. It measures **pace against the month**, not level. It speaks from two places with
one voice — proactively on a schedule, and at capture time when the transaction just
registered is the one that crosses the line. It states the fact and its cause and stops
there; no advice.

An earlier change, `financial-coaching-clarify`, was shelved after its proposal: Yape's
own message field and manual entry already let this user set categories, so automating
the categorisation question addressed a pain that does not exist for him.

## Findings

### 1. The reports are trustworthy on isolation and dates — with one blind spot

`get_monthly_category_budget_report` and `get_pareto_monthly_report` both scope correctly
by `user_id` and by `EXTRACT(YEAR/MONTH FROM date_operation)`. Date scoping is on
`date_operation`, not `created_at`, which is what makes pace measurable at all.

**But both hardcode `percentage_spent = 0` when `monthly_budget = 0`.** Zero is the schema
default, and `UserObserver::created()` leaves every seeded category there. So **a category
the user never budgeted can never raise a warning, no matter how much lands in it.** The
coach is blind by default.

### 2. `actual_percentage` is not behaviour

`percentage` is user-set on `ParetoClassification`. But `actual_percentage` is
`SUM(budget of categories in the bucket) / SUM(budget of all non-income categories)` —
**budget-allocation weight, not spending behaviour**. Nothing anywhere computes a bucket's
share of actual monthly spend. Narrating it as "what you really do" would be false.

The genuine declared-versus-real pair per bucket is `percentage` against `percentage_spent`.

### 3. Pace is not computed anywhere, and the one precedent is fake

`get_summary_transaction_by_day()` has the right shape — `avg_expense_day = budget/days`,
`new_expense_day = remaining_budget/remaining_days` — but `v_amount_total NUMERIC := 2000;`
is **hardcoded**, whole-account, and reached by raw `DB::select` from
`SendSummaryTransactionsByDay`, bypassing `FinancialReportService`. It must not be reused
or trusted as validation.

Neither report returns day-of-month or elapsed time. A per-category projection is new PHP
logic over data that already exists; no schema change is required for the arithmetic
itself.

### 4. Lumpy versus linear has no enforced signal

Spending is not uniform: rent lands on day 1, salary on day 30. Projecting linearly from a
lumpy category produces nonsense. `UserObserver` seeds `Fijos` / `Variables` / `Ahorro`
buckets and assigns rent and subscriptions to `Fijos`, food and transport to `Variables` —
a real proxy, but a **naming convention only**. The user can rename or reassign freely and
nothing reads it that way today.

### 5. The cause breakdown has a hole

`get_transactions_by_detail()` groups by merchant within category and month and returns
frequency and amount — exactly the "4 pedidos de delivery" shape. But
`HAVING COUNT(t.id) > 1` **silently drops any merchant with a single transaction**, so a
category blown by one large one-off purchase has no causal breakdown at all. The Pareto
`categories` jsonb is category-level only.

### 6. The outbound port is not the blocker

`TelegramChannel::reply()` is a plain `sendMessage` with no inbound context, and
`WhatsAppChannel::reply()` delegates to `WhatsAppNotificationService`, also context-free.
`CaptureChannelRegistry::for($key)` already resolves an adapter by key. **`reply()` is
reusable for an unprompted send as it stands** — unlike the shelved clarify change, no port
change is needed.

The existing unprompted precedent bypasses all of it: both scheduled summary commands are
hardcoded to `$userId = 1` and a literal phone string. There is no multi-user pattern to
imitate. `SendSummaryTransactionByMonth` also carries a live silent bug —
`$budgetDeviation->sum('real')` sums a field that does not exist, so "Gasto Real" always
renders `S/ 0.00`.

### 7. Reverse lookup is missing

`ChannelIdentityResolver` only goes channel-account → User. A sweep needs User → where do I
reach them. `channel_identities` is unique on `(channel, external_id)`, **not** on
`user_id`, so a user may hold both a WhatsApp and a Telegram identity; picking Telegram
must be deliberate.

### 8. Nothing remembers what was already said

No table, model or cache records "already told this user about food this month". The two
entry points share no state at all today.

### 9. Load is not a concern at this scale

Both report functions are properly indexed. A daily sweep at ~100 users is cheap. Minor
smell: `v_unified_transactions.matched_yape_id` is a per-row correlated subquery, unused by
these reports but always executed.

### 10. The two known live defects do not poison this path

`createExactRule()`'s `firstOrCreate` non-update bug and the broken
`Transaction::category()` `hasOneThrough` relation both stay clear of this data: the SQL
functions join on `transactions.category_id` directly. They remain real defects elsewhere.

## Open forks for propose and design

1. **Where the pace arithmetic lives** — PHP, or a new SQL function. Technical debt item 10
   already flags nine or more SQL functions carrying business rules.
2. **What to do about the two data-model gaps** — the `monthly_budget = 0` blind spot and
   the absent lumpy-versus-linear signal — rather than silently building on top of them.
