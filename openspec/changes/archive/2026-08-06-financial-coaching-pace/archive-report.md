# Archive report — `financial-coaching-pace`

- **Archived:** 2026-08-07
- **Branch:** `feat/financial-coaching-pace`
- **Range:** `2d7c58a..c3a5ac8` — seven commits
- **Verify:** PASS. 0 CRITICAL, 27 of 27 spec scenarios covered by real tests.
- **Suite:** 306 passed / 0 failed. PHPStan 16 on base and HEAD, none in Coaching. Pint clean.

## What shipped

The first behaviour the Financial Coaching subdomain owns. ADR-0007 created that
subdomain; the context map recorded it as owning nothing.

The coach measures **pace against the month**, not level — 80% of a budget on day 8 and
on day 28 are not the same situation, and a fixed threshold cannot tell them apart. It
speaks from two places through one shared decision: a scheduled sweep, which is what
justified choosing Telegram at all, and a check at capture time when the transaction that
just landed is the one that crossed the line. It states **the fact and its cause** and
stops there.

```
Comida: S/ 340.00 de S/ 400.00 el día 12, a este ritmo cerrás en S/ 878.33.
El 60% son 4 compras de Rappi.
```

It also names what it **cannot** watch. A category with spend and no budget can never
raise a pace warning, and silence about it reads as "you're fine" when it means "I'm not
looking".

## Commits

| Commit | Phase |
|---|---|
| `2d7c58a` | Planning |
| `48f6935` | 1 — pace evaluator, pure PHP, no database |
| `630fc34` | 2 — category-month data access, timezone pin |
| `3e8af3a` | 3 — spoken-observation ledger |
| `e51435c` | 4a — reachability and configuration |
| `c0a396c` | 4b — message composition and blindness |
| `958ab26` | 4c — the service, the sweep, the schedule |
| `8dcb2b4` | 5 — capture-time check |
| `4487cbb` | 6 — retire the daily summary, repair the monthly |
| `c3a5ac8` | Verify report |

Phase 4 split into three commits because it forecast past the 400-line review budget once
tasks added mid-flight were counted. Every commit carries its own approved review receipt
and passed `review validate --gate pre-commit`.

**Phase 6 landed strictly after phase 4**, which was a hard constraint rather than a
preference: retiring the daily summary before the coach was live would have left the
owner with no message at all in between. The 20:00 sweep and the old 20:08 summary
coexisted deliberately for two commits.

## Open follow-ups — these must outlive this folder

### Blocking the owner, not the code

1. **Task 2.3 was never done and must not be quietly closed.** Compare
   `get_monthly_category_budget_report` totals for the current and previous month against
   a **production copy**, before and after the `pgsql.timezone` pin, proving no month
   boundary shifted. The server and the app were both already `America/Lima` when checked
   live, so the pin is *expected* to be a no-op — expected, not proven. This is a release
   gate and this environment cannot perform it.

### Recorded debt

2. **`get_summary_transaction_by_day()` is now an unused SQL function.** Its only consumer
   was deleted. Removing a shipped migration is its own decision.
3. **`resources/views/emails/summary_day.blade.php` is orphaned** for the same reason.
4. **`SendSummaryTransactionByMonth` hardcodes `$userId = 1`** and a recipient address, so
   the monthly report still serves exactly one person. Pre-existing, untouched.
5. **`sent_at` is not proof of delivery.** `TelegramChannel::reply()` returns `void` and
   swallows non-2xx, so the column records only that the call returned. It was renamed
   from `delivered_at` for exactly that reason. Making it real requires changing the
   capture port, which this change deliberately refused.
6. **A WhatsApp-only user is never coached.** A consequence of ADR-0007's 24-hour window,
   accepted, and made observable in the sweep's summary line rather than hidden.
7. **`CategorizationService` still has no unit tests**, and it decides the category every
   coaching message names. A miscategorised transaction becomes a spoken accusation; the
   mitigation is that the message names the merchants behind the number, so a wrong
   reading is visibly wrong.
8. **The merged specs' links to the workspace docs were broken and are now fixed.** Both
   this change's delta and the previous change's already-merged content carried the
   relative depth of their *delta* location rather than their merged one. Every link in
   `openspec/specs/{ingestion,coaching}/spec.md` was verified to resolve on disk. Worth
   checking on the next merge — nothing in the pipeline catches it.

### Carried by the shelved sibling

9. **`financial-coaching-clarify` stays shelved with its reasoning** — the owner already
   sets categories through Yape's message field and manual entry, so automating that
   question addressed a pain he does not have. It carries two live findings of its own:
   `CategorizationService::createExactRule()` is `firstOrCreate`, so **no rule in this
   repository can ever be updated**; and Telegram button taps arrive as `callback_query`,
   which the webhook claims through the deduplicator and then silently drops.

## What this change refused to do

- Narrate `actual_percentage` as behaviour. It is budget-allocation weight, not spending.
  The guard reflects over every DTO in `app/DTOs/Coaching/` from disk, because a
  hand-written list rots and a signature-derived one missed `CoachingCause` entirely.
- Let the composer state something false. It throws on a `projected_over` with no
  projection rather than falling back to "ya pasaste el presupuesto".
- Send before claiming. The unique index can only arbitrate if the insert comes first.
- Let a coaching failure damage a capture. A retried job re-runs the capture and could
  duplicate a transaction.
- Add a tenth business-rule SQL function. The pace arithmetic is PHP and unit-tested
  without a database.


---

## Follow-up status — updated 2026-08-07

Closed in `441b50a` (branch `chore/close-recorded-followups`):

- **`createExactRule()` could never update a rule.** Split by intent:
  `rememberInferredRule()` keeps `firstOrCreate` for the cascade's guesses,
  `setRule()` overwrites for explicit user corrections. Both correction paths moved
  to `setRule()`. This was the live bug the shelved `financial-coaching-clarify`
  change had planned to fix.
- **`CategorizationService` had no tests.** It has a cascade regression net now
  (`CategorizationCascadeTest`, `CategorizationRuleWritesTest`).
- **The unreachable `DetailResolver` backfill branch** and its vacuous test.
- **The unguarded `ChannelIdentity::creating` listener** in `ChannelIdentityLinkerTest`.
- **The orphaned `summary_day` template.**
- **Broken documentation links** in both merged specs.

Still open, each for a stated reason rather than by neglect:

| Item | Why it stays open |
|---|---|
| Task 2.3, production-copy timezone comparison | The owner's release gate. Cannot be performed outside production. |
| `sent_at` is not proof of delivery | Making it real requires changing the capture port, which the design refused. |
| Drop `users.whatsapp_phone` / `legacy_whatsapp_phone` | Registration still writes the former. A change of its own, with a data migration. |
| `SendSummaryTransactionByMonth` hardcodes `$userId = 1` | Un-hardcoding it would start emailing every user. The owner's call. |
| Delete the unused `get_summary_transaction_by_day()` | Removing a shipped migration is its own decision. |
| Claim-by-insert idiom duplicated across three services | Currently identical and identically tested in all three. Extract when a fourth appears. |
| `"Desconocido WhatsApp"` fallback | **Attempted and reverted.** `similarity('desconocido whatsapp','desconocido')` is 0.571 against `DetailResolver`'s 0.6 threshold, so renaming it splits the historical grouping into two `Detail` rows and orphans any rule learned on the old one. Needs a data migration to do safely. |
| A WhatsApp-only user is never coached | Accepted consequence of ADR-0007's 24-hour window; observable in the sweep's summary line. |
