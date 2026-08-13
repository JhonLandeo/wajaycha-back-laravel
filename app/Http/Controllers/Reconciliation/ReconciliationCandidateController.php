<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reconciliation;

use App\Actions\Reconciliation\ResolveReconciliationCandidateAction;
use App\Http\Controllers\Controller;
use App\Models\ReconciliationCandidate;
use App\Models\Transaction;
use App\Repositories\Contracts\ReconciliationCandidateRepositoryContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The surface the SPA needs to answer "are these two the same payment?".
 *
 * Nothing here is resolved by route model binding on its own. A candidate names
 * two transactions and settling it changes which of them counts, so it is
 * fetched from the authenticated user's own candidates and a stranger's id is a
 * 404, not a 403 — the second answer would confirm the row exists.
 */
class ReconciliationCandidateController extends Controller
{
    /** Recent enough to still be worth auditing. */
    private const AUTO_MERGED_LIMIT = 50;

    public function index(Request $request): JsonResponse
    {
        $candidates = $this->ownedBy($request)
            ->pending()
            ->with([
                'transaction.detail:id,description',
                'candidateTransaction.detail:id,description',
            ])
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $candidates->map(fn (ReconciliationCandidate $candidate): array => [
                'id' => $candidate->id,
                'detected_at' => $candidate->created_at?->toIso8601String(),
                'transaction' => $this->present($candidate->transaction),
                'candidate' => $this->present($candidate->candidateTransaction),
            ])->all(),
        ]);
    }

    /**
     * What the system merged on its own, so the user can audit it.
     *
     * Deciding without asking is only acceptable while this list exists. Capped
     * rather than paginated: it answers "did we get anything wrong recently",
     * and a reconciliation from four months ago is history, not a review queue.
     */
    public function autoMerged(Request $request, ReconciliationCandidateRepositoryContract $candidates): JsonResponse
    {
        $merged = $candidates->autoMergedFor((int) $request->user()->id, self::AUTO_MERGED_LIMIT);

        return response()->json([
            'data' => $merged->map(fn (ReconciliationCandidate $candidate): array => [
                'id' => $candidate->id,
                'resolved_at' => $candidate->resolved_at?->toIso8601String(),
                'transaction' => $this->present($candidate->transaction),
                'candidate' => $this->present($candidate->candidateTransaction),
            ])->all(),
        ]);
    }

    public function confirm(Request $request, int $candidate, ResolveReconciliationCandidateAction $resolve): JsonResponse
    {
        return $this->settle(fn (ReconciliationCandidate $found) => $resolve->confirm($found), $request, $candidate);
    }

    /** Separates a pair the system merged, putting both movements back in the totals. */
    public function undo(Request $request, int $candidate, ResolveReconciliationCandidateAction $resolve): JsonResponse
    {
        return $this->settle(fn (ReconciliationCandidate $found) => $resolve->undo($found), $request, $candidate);
    }

    public function reject(Request $request, int $candidate, ResolveReconciliationCandidateAction $resolve): JsonResponse
    {
        return $this->settle(fn (ReconciliationCandidate $found) => $resolve->reject($found), $request, $candidate);
    }

    /** @param  callable(ReconciliationCandidate): ReconciliationCandidate  $decision */
    private function settle(callable $decision, Request $request, int $candidateId): JsonResponse
    {
        $candidate = $this->ownedBy($request)->findOrFail($candidateId);

        try {
            $resolved = $decision($candidate);
        } catch (RuntimeException $e) {
            // Dos pestañas abiertas sobre la misma sospecha. La segunda no es un
            // fallo del servidor y no debe presentarse como tal.
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'data' => [
                'id' => $resolved->id,
                'status' => $resolved->status->value,
                'resolved_at' => $resolved->resolved_at?->toIso8601String(),
            ],
        ]);
    }

    /** @return Builder<ReconciliationCandidate> */
    private function ownedBy(Request $request): Builder
    {
        return ReconciliationCandidate::query()
            ->where('user_id', (int) $request->user()->id);
    }

    /** @return array<string, mixed> */
    private function present(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'amount' => $transaction->amount,
            'type_transaction' => $transaction->type_transaction,
            'date_operation' => $transaction->date_operation,
            'source_type' => $transaction->source_type,
            'description' => $transaction->detail?->description,
            'message' => $transaction->message,
        ];
    }
}
