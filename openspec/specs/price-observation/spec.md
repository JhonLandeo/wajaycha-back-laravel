# Price Observation Specification — Product price quotes from isolated sources

- **Change:** `grocery-prices`
- **Capability:** `price-observation` (backend, new)
- **Subdomain:** Prices (`kind: supporting`, upstream of Shopping; see [context-map.md](../../../../docs/domain/context-map.md))
- **Date:** 2026-10-08
- **Decision:** [ADR-0010](../../../../docs/decisions/0010-fuentes-de-precios-en-tres-capas.md)

Terms per [ubiquitous-language.md](../../../../docs/domain/ubiquitous-language.md).

Terms used here: **Quote** — a resolved price for one product. **Retail source** — Plaza Vea, INEI.
**Wholesale source** — EMMSA, GMML. **Fresh / Stale / Expired** — per the staleness windows below.
**asOf** — the plan week's reference date (Lima). **Source key** — identity of the store or provider
(`plazavea`, `inei`, `emmsa`, `gmml`; a future `metro` would follow the same rule).

RFC 2119 keywords apply. Every scenario is Given/When/Then and testable at the layer named.

## Purpose

Collect, store, rank and expose product price quotes from isolated sources. Every quote is shown with
its source and date. When data is missing or old, the system degrades to `unknown` rather than
guessing. Each source has an off switch that takes effect on both fetch and read, and a purge command
supports takedown.

## Requirements

### Requirement: Price observation storage

The system SHALL store global (no `user_id`) `price_observations`: `product_id`, `source`,
`price_kind` (`retail | wholesale`), `unit`, `unit_price`, `reference_price` (nullable),
`price_min` / `price_max` (nullable), `sample_size`, `basis` (`measured | equivalence`),
`period_start`, `period_end`, `observed_at` (timestamptz), `source_ref`. There MUST be NO `store`
column: the `source` key identifies the store (`plazavea`, a future `metro`). `unit` MUST be the
product's catalogue unit at ingest time and `unit_price` MUST be per that unit. The tuple
(`product_id`, `source`, `period_start`) MUST be unique; re-ingesting the same tuple MUST update,
not duplicate. Money columns MUST be `numeric` (never float) with enough scale (>= 4 decimals) that
unit prices derived by division (e.g. 3.90 / 0.9 kg) do not lose precision; rounding to 2 decimals
happens only at output. Stored data MUST be limited to price, list price, presentation, date and
source (no photos, descriptions, brands or logos). Tables and principal columns MUST have comments.

| Scenario | Given | When | Then |
|---|---|---|---|
| Idempotent rerun | observation for (p, plazavea, 2026-10-05) | same tuple ingested again with new price | one row, price updated |
| Distinct periods | same product and source | different `period_start` | two rows |
| Money type | schema | migration inspected | `unit_price` is numeric with >= 4 decimals, not float |
| No store column | schema | columns listed | no `store` column; `source` and `unit` columns exist |
| Unit recorded | product with catalogue unit `kg` | observation ingested | row.unit = `kg` and unit_price is per kg |
| Minimal fields | schema | columns listed | no image, description or brand column |

### Requirement: Source registry and kill switch on fetch AND read

Each source (`inei`, `emmsa`, `gmml`, `plazavea`) SHALL be individually enable-able by configuration.
A disabled source MUST (a) not be fetched: ingestion is skipped and recorded `skipped`, checked both
before dispatch and again inside each job; and (b) be ignored by every read path (resolver, trend,
plan) on the very next request after config reload, without deleting data. Plaza Vea MUST default to
disabled until explicitly enabled by environment.

| Scenario | Given | When | Then |
|---|---|---|---|
| Fetch skipped | plazavea disabled | its schedule fires | no HTTP request; run row `skipped` |
| Job re-checks | plazavea disabled after jobs were queued | a queued job executes | no HTTP request; row `skipped` |
| Read ignores | plazavea disabled, rows exist | plan read | no plazavea quote used; falls to next source or unknown |
| Immediate | source flipped off | next request | quote not used (no cache lag) |
| Default off | fresh install env | plazavea config read | disabled |
| Re-enable | source re-enabled | plan read | existing non-expired rows are used again |

### Requirement: Purge command for takedown

`prices:purge {source}` SHALL delete every observation of that source and report the count. It SHALL
write one run-log row with status `purged`. An unknown source name MUST fail with non-zero exit and
delete nothing. The command MUST be idempotent and MUST warn when the source is still enabled. Run-log
rows are retained (they hold no third-party prices). Purge MUST be sufficient to remove a source's
data within the 24-48h takedown commitment, with no other copy kept.

| Scenario | Given | When | Then |
|---|---|---|---|
| Purge deletes | 40 plazavea + 20 inei rows | `prices:purge plazavea` | 0 plazavea rows, 20 inei rows remain, count 40 printed, one `purged` run row for plazavea |
| Unknown source | no such source | `prices:purge bogus` | exit non-zero, nothing deleted |
| Idempotent | already purged | run again | exit 0, count 0 |
| Warns when enabled | source still enabled | purge runs | warning shown that the source is still enabled |
| Plan after purge | purged source was the only quote | plan read | line unknown, not error |
| Run log kept | prior run rows exist | purge | run rows still present |

### Requirement: Curated mapping, never fuzzy

The mapping from catalogue slug to source-specific identifier or terms SHALL be curated configuration
keyed by slug. Products without a mapping for a source MUST get no quote from that source. Every
mapped slug MUST exist in the catalogue and every configured pattern MUST compile. The Plaza Vea
mapping includes category and search terms, must-match and must-not-match name patterns, and an
optional unit equivalence.

| Scenario | Given | When | Then |
|---|---|---|---|
| Mapping integrity | mapping config | test iterates slugs | every slug exists in the seeded catalogue; every regex compiles |
| Unmapped | slug without plazavea entry | ingestion | no row written for it |
| No fuzzy | query returns "olla arrocera" for arroz | filters applied | row rejected by must_not_match |

### Requirement: Layer 1 - INEI Cuadro N.19 (retail Lima monthly anchor)

Weekly, the system SHALL check the INEI collection and ingest only editions not yet ingested (the
edition id is kept in `source_ref`). The period MUST be read from the table header, never the file
name. The system SHALL parse using the existing PDF library (no new composer dependency; a
system-binary fallback only if the spike demands it, with argv-array invocation, a timeout, and a
named failure on non-zero exit). It MUST repair `4,,86`-style artefacts and mixed `,` / `.` decimals,
reject unparseable rows (e.g. `3.330`), and quarantine (not usable for pricing) any value whose
month-over-month change exceeds a configurable bound. The run status is `partial` when rows were
rejected. Rows are stored as retail with period_start / period_end equal to the month.

| Scenario | Given | When | Then |
|---|---|---|---|
| New edition | unseen edition for 2026-08 | weekly check | rows stored with period 2026-08-01..31 |
| Seen edition | edition already ingested | weekly check | no PDF download, run row `skipped` |
| Period from header | file named for a different month | parsed | period equals header month |
| Malformed number | cell `4,,86` | parsed | stored as 4.86 |
| Unparseable row | garbage price cell | parsed | row rejected, run `partial`, other rows stored |
| Jump quarantine | price jumps beyond bound vs previous month | parsed | row not usable for pricing, flagged |
| Unit conversion | INEI unit differs from catalogue unit | normalised | converted only inside the normalizer, or rejected if dimensions mismatch |

### Requirement: Layer 2 - wholesale trend source with fallback

Daily, the system SHALL ingest EMMSA D-1 wholesale min / max / avg per kg via one request. If EMMSA has
no successful run for D-1, GMML (converted using "Equiv. en kg") MAY fill that day only. EMMSA and GMML
MUST NOT be mixed for the same product and day. TLS MUST be verified using a shipped CA bundle for the
profile; verification MUST NOT be disabled. Wholesale rows have `price_kind` `wholesale`.

| Scenario | Given | When | Then |
|---|---|---|---|
| EMMSA ok | EMMSA returns D-1 table | daily run | rows stored, kind wholesale, per kg |
| Fallback | no successful EMMSA run for D-1, GMML available | daily run | GMML rows stored for D-1 only |
| No mixing | EMMSA row exists for product and day | GMML also has it | GMML row not stored |
| GMML skipped | EMMSA succeeded for D-1 | GMML schedule fires | no GMML fetch, run row `skipped` |
| GMML conversion | row 25 kg sack at S/ 50, equiv 25 kg | normalised | 2.00 per kg |
| TLS | EMMSA profile | config inspected | CA bundle path set, no verify=false |

### Requirement: Layer 3 - Plaza Vea weekly retail

Weekly (Monday 05:00 America/Lima) the system SHALL fetch Plaza Vea prices for mapped products only: one
job per product, requests spaced >= 5 s apart, an identifying User-Agent, no evasion of blocks, about 40
requests in total. A candidate MUST be rejected unless Price > 0, the item is available (IsAvailable and
AvailableQuantity > 0), and it passes the mapping filters. Items priced per kg are used directly.
Per-unit items require a pack size parsed from the name (g to kg and ml to l conversion only inside the
price normalizer). A dimension mismatch without curated equivalence is rejected. Items using a curated
equivalence get basis `equivalence`. The stored `unit_price` is the MEDIAN of accepted candidates,
`reference_price` is the median list price, and `sample_size` is the count. Zero accepted candidates
writes no observation row.

| Scenario | Given | When | Then |
|---|---|---|---|
| Per-kg item | measurementUnit kg, Price 8.90 | normalised | unit_price 8.90 per kg, basis measured |
| Pack parsed | "Arroz Costeno 750g", Price 4.50 | normalised | 6.00 per kg, basis measured |
| Unavailable | Price 0.0 / no stock | filtered | rejected |
| Median | accepted candidates 4.0, 6.0, 20.0 per kg | aggregated | unit_price 6.0, sample_size 3 |
| No candidate | all rejected | aggregated | no observation row written |
| Dimension mismatch | volume item for a mass product, no equivalence | normalised | rejected |
| Spacing | ~40 queued jobs | schedule dispatched | consecutive requests >= 5 s apart |
| Equivalence | palta, unit `unidad`, curated 1 unidad = 0.2 kg | normalised | basis equivalence |
| Outbound | job | executes | uses `OutboundHttp::to` profile; job timeout >= profile worst case (budget test) |

### Requirement: Resolution precedence for a line price

A pure `PriceResolver` (values in, decisions out) SHALL choose, per product, among ENABLED retail quotes
in the line's unit: the best-ranked FRESH quote; if none, the best-ranked STALE quote; otherwise
`unknown`. A fresh lower-ranked quote MUST therefore beat a stale higher-ranked one. Rank is configurable,
default Plaza Vea > INEI. Expired quotes MUST be ignored. Wholesale quotes MUST NEVER be selected as a
line price. Non-selected usable retail quotes SHALL be returned as `alternatives`.

| Scenario | Given | When | Then |
|---|---|---|---|
| Rank wins | fresh plazavea and fresh inei | resolved | plazavea chosen, inei in alternatives |
| Fresh beats rank | stale plazavea, fresh inei | resolved | inei chosen (fresh over stale), state fresh; plazavea in alternatives |
| Stale fallback | only stale plazavea and stale inei | resolved | plazavea (best rank), state stale |
| Expired ignored | plazavea 30 days old, no other | resolved | unknown |
| Wholesale never prices | only fresh EMMSA quote | resolved | unknown |
| Disabled excluded | plazavea disabled, inei fresh | resolved | inei |
| Unit filter | quote unit differs from line unit | resolved | quote ignored |
| Purity | resolver class | boundary test | no DB, no clock, no models |

### Requirement: Staleness windows

Windows SHALL be configuration, evaluated against the plan `asOf` (Lima date), not `now()`. Defaults:
Plaza Vea fresh <= 8 d, stale 9-21 d, expired > 21 d (age from period_end); INEI fresh <= 75 d, stale
76-135 d, expired > 135 d (from period_end); wholesale trend usable <= 4 d, otherwise no trend.

| Scenario | Given | When | Then |
|---|---|---|---|
| Plaza Vea boundary fresh | observed 8 d before asOf | classified | fresh |
| Plaza Vea stale | 9 d | classified | stale |
| Plaza Vea expired | 22 d | classified | expired (ignored) |
| INEI from period_end | period_end 75 d before asOf | classified | fresh |
| INEI stale | 76 d | classified | stale |
| INEI expired | 136 d | classified | expired |
| Config driven | thresholds changed in config | classified | new thresholds apply |

### Requirement: Wholesale trend

`wholesale_trend` SHALL compare the latest usable wholesale value (age <= 4 d) with the same source's value
about a week earlier (window 5-10 d). If no such earlier point exists in the same source (including a
source switch), the latest is older than 4 days, or fewer than two points exist, the trend MUST be null.
`direction` is `flat` when |change| is below the configured epsilon (default 3%).

| Scenario | Given | When | Then |
|---|---|---|---|
| Rise | EMMSA 2.00 a week ago, 2.20 now | computed | up, change_pct 10 |
| Source switch | EMMSA a week ago, GMML now | computed | null |
| Too old | latest 6 days old | computed | null |
| Single point | one observation | computed | null |
| Flat | change within epsilon | computed | direction flat |

### Requirement: Attribution and date always present

Every exposed quote (line price, alternative) SHALL carry a source label, attribution text and its
date or period. Texts: Plaza Vea "Precio online de Plaza Vea al dd/mm"; INEI "Promedio Lima INEI,
mmm aaaa". No store logos or product images SHALL be exposed. A quote lacking a date or attribution
MUST NOT be exposed (treated as unknown).

| Scenario | Given | When | Then |
|---|---|---|---|
| Plaza Vea text | observed 2026-10-05 | plan serialised | attribution "Precio online de Plaza Vea al 05/10" |
| INEI text | period 2026-08 | plan serialised | attribution "Promedio Lima INEI, ago 2026" |
| No assets | any quote | payload inspected | no logo or image field |

### Requirement: Ingestion run log (one row per source and unit of work), observability, canary

`price_ingestion_runs` SHALL hold ONE row per (source, unit of work). For Plaza Vea the unit is a product
(about 40 rows per weekly run). For EMMSA, GMML and INEI, one row per run (unit key `all` or the edition).
Status MUST be one of `running | success | partial | failed | skipped | purged`. A row carries source, unit
key, started_at, finished_at, rows_written, rows_rejected, error (nullable) and non-price details (e.g.
rejection reasons). It MUST NOT store third-party prices. A per-run aggregate (e.g. "plazavea: 38 success,
2 failed") is DERIVED by querying rows, not stored. Each schedule SHALL use a Sentry monitor check-in. A
freshness canary SHALL fail its check-in when an enabled source's latest observation is older than its
fresh window, or its latest run row is `failed`. A source failure MUST NOT raise to the user and MUST NOT
delete existing observations.

| Scenario | Given | When | Then |
|---|---|---|---|
| One row per product | weekly Plaza Vea run over 40 mapped products, all accepted | run completes | 40 run rows (unit key = slug), each `success`, each rows_written 1; derived aggregate = 40 success; no single "rows_written 40" row exists |
| Mixed outcomes | 38 products ok, 1 fails with HTTP 500, 1 has zero accepted candidates | run completes | 38 `success`; 1 `failed` with error; 1 `partial` or `success` with rows_written 0 per rule; failed row does not stop other rows; old observations untouched |
| Running state | job picked up | job starts | its row is `running` with started_at, then finished with finished_at set |
| Per-product diagnosis | one product failing | rows queried | failing slug identifiable by unit key |
| Single-run sources | EMMSA daily run | completes | exactly one run row for that day |
| Skipped logged | source disabled | schedule fires | one `skipped` row for the source |
| Partial | INEI with some rows rejected | run | one row `partial` with rows_rejected > 0 |
| Purge logged | purge command | runs | one `purged` row |
| Canary stale | enabled source latest observation older than fresh window | canary runs | check-in fails (non-zero) |
| Canary failed run | latest run row of an enabled source `failed` | canary runs | check-in fails |
| Canary healthy | fresh data, last run success | canary runs | exit 0 |
| Aging | source down for 10 days | plan read | quotes age fresh to stale to unknown per windows, no error |
| No prices in log | any run row | inspected | no price columns or price values in details |

### Requirement: Outbound HTTP discipline

All source calls SHALL go through `OutboundHttp::to(profile)`, with one profile per source in config.
Job timeouts MUST be tied to the profile budget (OutboundHttpBudgetTest updated). Each job MUST have a
single attempt (tries 1). No source may be called from a web request.

| Scenario | Given | When | Then |
|---|---|---|---|
| Budget test | profiles for vtex, emmsa, gob.pe | OutboundHttpBudgetTest | job timeout >= worst case per profile |
| No inline fetch | GET weekly-plan | executed | zero outbound HTTP |
