<?php

declare(strict_types=1);

namespace App\Http\Controllers\Capture;

use App\Http\Controllers\Controller;
use App\Services\Capture\ChannelLinkTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChannelLinkController extends Controller
{
    /**
     * Issues a link token for the authenticated user and returns the deep link the
     * SPA renders. The token is bound to whoever is logged in, never to an id the
     * client supplies: that is the whole point of doing this behind auth.
     */
    public function issue(Request $request, ChannelLinkTokenIssuer $issuer): JsonResponse
    {
        $issued = $issuer->issue($request->user());

        return response()->json([
            'deep_link' => $issued->deepLink,
            'expires_at' => $issued->expiresAt,
        ]);
    }
}
