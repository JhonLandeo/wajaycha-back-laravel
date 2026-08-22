<?php

declare(strict_types=1);

namespace App\Http\Controllers\AuthJWT;

use App\Actions\Auth\ResolveGoogleUserAction;
use App\Exceptions\Auth\GoogleIdentityException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleLoginRequest;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Trades a verified Google identity for this API's own JWT.
 *
 * Separate from {@see JWTAuthController} on purpose. That controller guards a
 * password: it counts attempts, locks accounts and decides when a human has
 * guessed too many times. This one guards a signature, and none of that applies —
 * there is nothing here to guess, so an account-level counter would only punish
 * someone whose browser retried. The trust boundary is different, and auth code
 * that mixes two trust boundaries in one file stops being reviewable.
 *
 * What is identical, and must stay identical, is the response: `{token, user}`,
 * the same shape `login` and `register` return. The client stores whatever comes
 * back under one key and knows nothing about how it was earned.
 */
final class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly GoogleIdTokenVerifier $verifier,
        private readonly ResolveGoogleUserAction $resolveUser,
    ) {}

    public function __invoke(GoogleLoginRequest $request): JsonResponse
    {
        try {
            $identity = $this->verifier->verify($request->credential());
            $user = $this->resolveUser->execute($identity);
        } catch (GoogleIdentityException $e) {
            // The technical reason is logged and never returned. Naming the check
            // that failed would tell a forger which one to fix next.
            Log::warning('Google Sign-In rejected.', [
                'reason' => $e->getMessage(),
                'status' => $e->status,
            ]);

            return response()->json([
                'error' => 'Google sign-in failed',
                'message' => $e->userMessage,
            ], $e->status);
        }

        $token = JWTAuth::fromUser($user);

        return response()->json(compact('token', 'user'));
    }
}
