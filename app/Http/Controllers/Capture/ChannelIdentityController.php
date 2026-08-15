<?php

declare(strict_types=1);

namespace App\Http\Controllers\Capture;

use App\Http\Controllers\Controller;
use App\Services\Capture\ChannelIdentityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChannelIdentityController extends Controller
{
    /**
     * Lists the capture channels the authenticated user is already reachable on.
     *
     * Without this the SPA cannot tell a linked account from an unlinked one, so
     * it offers the same "generate a link" button to both and the user mints a
     * bearer credential they did not need.
     *
     * It reports what the user HAS, not what the system OFFERS. Enumerating the
     * registered adapters instead would advertise `whatsapp`, which has no
     * webhook route and no dispatch point — a capability claim the system cannot
     * honour. See [ADR-0007].
     */
    public function index(Request $request, ChannelIdentityResolver $identities): JsonResponse
    {
        /** @var array<int, array{channel: string, linked_at: string}> $data */
        $data = [];

        foreach ($identities->identitiesFor((int) $request->user()->id) as $identity) {
            $data[] = [
                'channel' => $identity->channel,
                'linked_at' => $identity->linked_at->toIso8601String(),
            ];
        }

        return response()->json(['data' => $data]);
    }
}
