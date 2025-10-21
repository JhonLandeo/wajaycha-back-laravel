<?php

namespace App\Http\Controllers\AuthJWT;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Js;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class JWTAuthController extends Controller
{
    // User registration
    public function register(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'last_name' => 'required|string|max:255',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::create([
            'name' => $validatedData['name'],
            'last_name' => $validatedData['last_name'],
            'email' => $validatedData['email'],
            'password' => Hash::make($validatedData['password']),
        ]);

        $paretoClassification =  [
            ['name' => 'Fijos', 'percentage' => 50, 'user_id' => $user['id'], 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Variables', 'percentage' => 30, 'user_id' => $user['id'], 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Ahorro', 'percentage' => 20, 'user_id' => $user['id'], 'created_at' => now(), 'updated_at' => now()],
        ];

        DB::table('pareto_classifications')->insert($paretoClassification);

        $pareto = DB::table('pareto_classifications')
            ->where('user_id', $user['id'])
            ->pluck('id', 'name');

        DB::table('categories')->insert([
            // Fijos
            ['name' => 'Vivienda', 'pareto_classification_id' => $pareto['Fijos'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Telefonia', 'pareto_classification_id' => $pareto['Fijos'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Servicios básicos', 'pareto_classification_id' => $pareto['Fijos'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Seguros', 'pareto_classification_id' => $pareto['Fijos'], 'user_id' => $user['id'], 'created_at' => now()],
            // Variables
            ['name' => 'Salario', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Transporte', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Alimentación', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Entretenimiento', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Prestamos', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Salud', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Vestimenta', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Donaciones', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Favores', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Educacion', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Viajes', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Freelance', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Bienestar Personal', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Alimentacion fuera de casa', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Gastos para mi enamorada', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Intereses', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Transferencia interna', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Regalos y detalles', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => $user['id'], 'created_at' => now()],
            // Ahorro
            ['name' => 'Inversiones', 'pareto_classification_id' => $pareto['Ahorro'], 'user_id' => $user['id'], 'created_at' => now()],
            ['name' => 'Fondo de emergencia', 'pareto_classification_id' => $pareto['Ahorro'], 'user_id' => $user['id'], 'created_at' => now()],
        ]);

        $token = JWTAuth::fromUser($user);

        return response()->json(compact('token', 'user'));
    }

    // User login
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        try {
            // Intenta generar el token JWT
            if (! $token = JWTAuth::attempt($credentials)) {
                return response()->json(['error' => 'Invalid credentials'], 401);
            }

            // Obtén el usuario autenticado
            $user = auth()->user();

            // Devuelve el token y el usuario en la respuesta
            return response()->json(compact('token', 'user'));
        } catch (JWTException $e) {
            return response()->json(['error' => 'Could not create token', 'message' => $e->getMessage()], 500);
        }
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

    // User logout
    public function logout(): JsonResponse
    {
        try {
            Auth::guard('api')->logout();
            return response()->json(['message' => 'Successfully logged out']);
        } catch (\Exception $e) {
            Log::error('Logout Error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to logout'], 500);
        }
    }
}
