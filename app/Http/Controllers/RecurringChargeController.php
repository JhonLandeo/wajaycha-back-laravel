<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Recurring\BuildRecurringChargesAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The recurring-charge reading for the authenticated user.
 *
 * Read-only on purpose: nothing here stores a subscription. There is nothing to
 * edit, cancel or confirm — the page narrates what the movements already say
 * (ADR-0009), and the user decides what to do with it.
 */
final class RecurringChargeController extends Controller
{
    public function __construct(
        private readonly BuildRecurringChargesAction $action,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->action->execute((int) Auth::id())->toArray(),
        ]);
    }
}
