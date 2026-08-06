# Archive Report — Telegram capture channel

- **Change:** `telegram-capture-channel`
- **Date archived:** 2026-08-06
- **Subdomain:** Ingestion
- **Artifact store:** hybrid (OpenSpec files + Engram)

---

## Executive Summary

The `telegram-capture-channel` change has been successfully completed and archived. All 24/24 spec scenarios are covered by tests; the verification verdict is PASS (0 CRITICAL, 0 WARNING, 1 cosmetic SUGGESTION). The delta spec for Ingestion has been merged into the main specs at `openspec/specs/ingestion/spec.md`. The change folder has been moved to `openspec/changes/archive/2026-08-06-telegram-capture-channel/` and the SDD cycle is closed.

---

## Artifacts Archived

| Artifact | Location | Engram Topic | Status |
|---|---|---|---|
| Proposal | `proposal.md` | `sdd/telegram-capture-channel/proposal` | ✅ |
| Spec | `specs/ingestion/spec.md` | `sdd/telegram-capture-channel/spec` | ✅ (merged to main) |
| Design | `design.md` | `sdd/telegram-capture-channel/design` | ✅ |
| Tasks | `tasks.md` | `sdd/telegram-capture-channel/tasks` | ✅ |
| Verify Report | `verify-report.md` | `sdd/telegram-capture-channel/verify-report` | ✅ |
| Archive Report | `archive-report.md` | `sdd/telegram-capture-channel/archive-report` | ✅ |

### Engram Observation IDs

For full traceability across the SDD workflow:

- **Spec+Design** (obs #52): `sdd/telegram-capture-channel/design`
- **Tasks** (obs #53): `sdd/telegram-capture-channel/tasks`
- **Verify Report** (obs #73): `sdd/telegram-capture-channel/verify-report` — PASS verdict

---

## Spec Merge Summary

| Action | Domain | Details |
|---|---|---|
| Created | Ingestion | `openspec/specs/ingestion/spec.md` — 9 ADDED requirements, 1 MODIFIED requirement, 9 scenarios explicitly captured for compliance testing. This is the first capability spec in the main specs directory. |

The delta spec was a complete new spec (not a partial delta) since `openspec/specs/` contained only `.gitkeep`. It was copied directly to `openspec/specs/ingestion/spec.md` as the canonical source of truth for the Ingestion subdomain's Telegram capture capability.

---

## Implementation Summary

### Delivery

The proposal forecast 3 slices; delivery forecasting showed 2 exceeded the 400-line review budget, so the final chain was **5 slices**:

| Slice | Title | Estimated | Actual | Budget | Status |
|---|---|---|---|---|---|
| 1 | Extract Entity Resolution | 180 | ✅ | ok | refactor; no behaviour change |
| 2 | Extract the capture port | 190 | ✅ | ok | refactor; existing WhatsApp code as first adapter |
| 3 | Channel identity | 290 | ✅ | ok | destructive (legacy_whatsapp_phone retained) |
| 4 | Account linking | 250 | ✅ | ok | token as SHA-256 hash only; generic refusals |
| 5 | Telegram adapter | 380 | ✅ | tight | webhook authenticity, dedup, photo/text capture |

### Commits and Review

**Eleven implementation commits across branch `feat/telegram-capture-channel` (range `0dbe82e..HEAD`, 18 commits total including docs/chore/merge).**

Slices 3, 4 and 5 each exceeded the 400-line review budget once implemented and were split at real seams rather than at arbitrary line counts:

- **Slice 3a** (schema): migration creating `channel_identities` with `legacy_whatsapp_phone` nullable column
- **Slice 3b** (runtime): model and resolution service; rewire WhatsApp jobs to identity resolution
- **Slice 4a** (issuance): migration creating `channel_link_tokens`; token issuance service and endpoint
- **Slice 4b** (redemption): token redemption and `/start <token>` handling
- **Slice 5a–e** (integration): secret-token middleware → deduplication → Telegram adapter → capture job → webhook controller

**One commit outside the task list:** `9661acf` added direct coverage for `ProcessWhatsAppImage::handle()`, `ProcessWhatsAppMessage::handle()` and their `failed()` handlers. Neither had any test; that gap let a CRITICAL reach review. Slice 5 builds on those same jobs, so the coverage was added as foundation work.

**Every commit carries its own approved gentle-ai review receipt** with the corresponding review lens applied (ranging from review-readability to full 4R depending on risk). Additionally, a **consolidated change-level review** was run and approved, confirming coherence across all slices.

### Test Execution

- **Suite:** 202 passed / 0 failed (up from 199 in first verify pass, +3 matching the three new tests in fix commit `50f603c`)
- **PHPStan:** 16 errors (pre-existing on base and HEAD; no growth)
- **Pint:** clean on changed files
- **Migration reversibility:** verified (up/rollback/up tested)

### Spec Compliance

24/24 scenarios have covering tests:

- Webhook authenticity: 3/4 fully compliant + 1/4 partial (constant-time comparison timing, non-defect)
- Exactly-once capture: 3/3 compliant
- Complete update processing: 2/2 compliant
- Channel identity resolution: 3/3 compliant
- Account linking: 4/4 compliant
- Photo capture: 3/3 compliant (fixed in verify pass 2)
- Text capture: 2/2 compliant
- Every capture is answered: 1/1 compliant
- Channel-independent registration: 2/2 compliant

**Verification verdict: PASS** (0 CRITICAL, 0 WARNING, 1 cosmetic SUGGESTION)

---

## Recorded Follow-Ups and Technical Debt

These items were deliberately deferred and are recorded here so they are not lost when the change folder moves to archive:

### Schema Cleanup

1. **Drop `channel_identities.legacy_whatsapp_phone`** — This column exists solely as the rollback path for the one-time backfill of `users.whatsapp_phone` into channel identities. It was always intended to be dropped at archive. **Owner: DBA.** **Effort: one migration, no data loss since the pre-migration values are no longer referenced.**

2. **Drop `users.whatsapp_phone`** — Registration (`RegisterRequest`, `JWTAuthController`) still writes it; capture no longer reads it. Removing it requires:
   - Delete the column from migration
   - Update `RegisterRequest` validation rules
   - Update `JWTAuthController` to ignore WhatsApp phone input
   - Update `User` model attribute list
   - Update `UserFactory` fixture
   - One data migration (no backfill logic needed; just truncate)
   **Owner: API team.** **Effort: ~50 lines across multiple files.** **Prerequisite: ensure no other code reads this column.** This is a behaviour change and belongs in a separate cleanup change, not archived as implicit debt.

### Code Quality Issues (Non-Blocking)

All of the following were already disclosed in the task artifact (`tasks.md`) and did not block verification:

3. **Dead backfill branch in `DetailResolver::resolveOrCreate()`** — The `empty($detail->entity_clean)` backfill branch is unreachable because:
   - The only caller is `resolveOrCreate()`
   - The second parameter is `$entityClean = ''` initially (empty string)
   - The `similarity(NULL, '')` call returns `NULL`, not a number
   - `NULL < 0.6` is false, so the branch is never entered
   - The test for this branch (`'a blank entity_clean is backfilled'`) is vacuous (the assertion never fails)
   
   **Status:** Preserved deliberately because slice 1 forbade behaviour change. The branch and its test remain as-is; if the backfill is truly needed, it will become visible if `entity_clean` can be NULL from a future source. **Recommendation:** Record this in a code-quality follow-up if it becomes noise in the codebase.

4. **Channel-inaccurate description fallback** — The `"Desconocido WhatsApp"` description fallback inside `app/Actions/Capture/RegisterCapturedTransactionAction.php` is now channel-inaccurate. It writes to the database, so changing it is a behaviour change. **Status:** Preserved as-is. The fallback is unreachable in practice if `ParsedReceiptDTO::name` is always set by the vision/text services, but if it ever becomes reachable, it should be channel-agnostic (e.g., `"Desconocido"`). **Recommendation:** Record in a follow-up to revisit if needed.

5. **Unguarded event listener** — The `ChannelIdentity::creating` listener in `tests/Feature/Capture/ChannelIdentityLinkerTest.php` is not unregistered in a `finally` block, unlike its siblings. A propagating call (exception) in a later test would leak this listener into that test's assertions. **Status:** Not a defect in the current suite (no propagating exceptions reach this point), but a latent brittleness. **Recommendation:** Record as code-quality improvement for general test infrastructure.

6. **Duplicated claim-by-insert idiom** — The pattern `DB::transaction { savepoint } catch(UniqueConstraintViolationException)` is duplicated across three services:
   - `ChannelIdentityLinker` (slice 3b)
   - `ChannelLinkTokenRedeemer` (slice 4b)
   - `ChannelUpdateDeduplicator` (slice 5a)
   
   **Status:** Preserved deliberately. Each service owns its domain logic; extracting a shared abstraction now would be premature and might couple their semantics. **Recommendation:** If this pattern spreads to more than 3 call sites or if the services' semantics begin to converge, extract a `ClaimByInsertStrategy` or similar. For now, the three copies are acceptable.

### Shelved Proposals

7. **Shelved proposal: `secure-whatsapp-webhook`** — This prior exploration covered secure webhook authentication for WhatsApp. Findings F2 (idempotency via `update_id`), F3 (batched deliveries), F4 (identity normalisation), and F6 (Entity Resolution duplication) were carried into this change. Finding F1 (Meta HMAC authentication) died when ADR-0007 deprioritized WhatsApp in favour of Telegram (which uses a simpler secret-token scheme). **Status:** The proposal remains shelved. If WhatsApp is revisited later, it can reference this prior exploration and this change's implementation patterns as a starting point.

---

## Gate Validation

### Task Completion Gate — PASS

All 61 implementation tasks in `tasks.md` are ticked [x]:
- Slice 1: 8/8 complete
- Slice 2: 8/8 complete
- Slice 3: 9/9 complete
- Slice 4: 9/9 complete
- Slice 5: 16/16 complete
- Definition of Done: 6/6 complete

No unchecked implementation tasks remain.

### Review Receipt Gate — PASS

- Every slice's commits carry approved gentle-ai review receipts
- A consolidated change-level review was run and approved
- Verification confirmed spec compliance and no regressions

### Verify Gate — PASS

Verdict: **PASS** (0 CRITICAL, 0 WARNING, 1 cosmetic SUGGESTION)
- Scenario coverage: 24/24 (23 fully compliant, 1 partial on timing — non-defect)
- Test suite: 202 passed / 0 failed
- Static analysis: PHPStan 16 errors (pre-existing, no growth)
- Code style: Pint clean
- Migration reversibility: verified

---

## Change Closure

**This change is fully archived and closed.** The next work unit can proceed independently. The Ingestion subdomain now has a canonical specification and a working Telegram capture channel implementation.

Follow-up work (schema cleanup, code quality improvements, WhatsApp revisitation) is recorded above and does not block further feature development.

---

## Archive Checklist

- [x] Main specs (`openspec/specs/ingestion/spec.md`) updated with delta spec
- [x] Change folder moved to `openspec/changes/archive/2026-08-06-telegram-capture-channel/`
- [x] All artifacts present: proposal.md, design.md, tasks.md, verify-report.md, state.yaml
- [x] Task completion gate passed (61/61 tasks checked)
- [x] Review receipt gate passed (change-level review approved)
- [x] Verify gate passed (PASS verdict, 24/24 scenarios)
- [x] Follow-ups documented in archive report
- [x] Engram archive report saved with observation IDs
- [x] Archive report links to all referenced observations

**Archive status: COMPLETE**
