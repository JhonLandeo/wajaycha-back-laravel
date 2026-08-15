<?php

namespace App\Http\Controllers\AuthJWT;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\Capture\ChannelIdentityLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class JWTAuthController extends Controller
{
    // User registration
    public function register(RegisterRequest $request, ChannelIdentityLinker $linker): JsonResponse
    {
        $validatedData = $request->validated();

        $user = User::create([
            'name' => $validatedData['name'],
            'last_name' => $validatedData['last_name'],
            'email' => $validatedData['email'],
            'whatsapp_phone' => $validatedData['whatsapp_phone'] ?? null,
            'password' => Hash::make($validatedData['password']),
        ]);

        // La captura resuelve al remitente por su identidad de canal, no por la
        // columna. Sin esto el usuario queda invisible para el bot.
        $linker->linkWhatsApp($user, $validatedData['whatsapp_phone'] ?? null);

        $token = JWTAuth::fromUser($user);

        return response()->json(compact('token', 'user'));
    }

    /**
     * User login.
     *
     * The throttle sequence is spelled out here rather than delegated: the
     * counter has to bracket `JWTAuth::attempt()` — checked before it, hit on
     * rejection, cleared on success — and this is the only place that call is
     * made. See the class docblock on {@see LoginRequest} for what the previous
     * arrangement did instead, which was nothing.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        if ($request->isRateLimited()) {
            $request->recordLockout();

            return $this->tooManyAttempts($request->secondsUntilRetry());
        }

        $credentials = $request->validated();

        try {
            // Intenta generar el token JWT
            if (! $token = JWTAuth::attempt($credentials)) {
                $request->recordFailedAttempt();

                return response()->json(['error' => 'Invalid credentials'], 401);
            }

            $request->clearRateLimiter();

            // Obtén el usuario autenticado
            $user = auth()->user();

            // Devuelve el token y el usuario en la respuesta
            return response()->json(compact('token', 'user'));
        } catch (JWTException $e) {
            return response()->json(['error' => 'Could not create token', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 429 rather than a 422 validation error, so the client handles the
     * route-level `throttle:` middleware and this account-level lockout through
     * the same branch. `Retry-After` is the standard header for it.
     */
    private function tooManyAttempts(int $retryAfter): JsonResponse
    {
        $minutes = (int) ceil($retryAfter / 60);

        return response()
            ->json([
                'error' => 'Too many attempts',
                'message' => "Demasiados intentos fallidos. Vuelve a intentarlo en {$minutes} minuto(s).",
                'retry_after' => $retryAfter,
            ], 429)
            ->header('Retry-After', (string) $retryAfter);
    }

    // Get authenticated user
    public function getUser(): JsonResponse
    {
        try {
            if (! $user = JWTAuth::parseToken()->authenticate()) {
                return response()->json(['error' => 'User not found'], 404);
            }
        } catch (JWTException $e) {
            return response()->json(['error' => 'Invalid token'], 400);
        }

        return response()->json(compact('user'));
    }

    public function logout(): JsonResponse
    {
        try {
            Auth::guard('api')->logout();

            return response()->json(['message' => 'Successfully logged out']);
        } catch (\Exception $e) {
            Log::error('Logout Error: '.$e->getMessage());

            return response()->json(['error' => 'Failed to logout'], 500);
        }
    }
}
