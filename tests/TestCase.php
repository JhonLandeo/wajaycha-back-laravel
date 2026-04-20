<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{
    /**
     * Genera un token JWT real para el usuario dado y lo agrega
     * a las cabeceras de la petición HTTP de test.
     *
     * @return array<string, string>
     */
    protected function jwtHeaders(User $user): array
    {
        $token = JWTAuth::fromUser($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    /**
     * Retorna un usuario autenticado junto con sus cabeceras JWT.
     * Atajo conveniente para la mayoría de los tests de Feature.
     *
     * @return array{0: User, 1: array<string, string>}
     */
    protected function userWithAuth(?User $user = null): array
    {
        $user ??= User::factory()->create();
        return [$user, $this->jwtHeaders($user)];
    }

    /**
     * Helper para tests: autenticarse como un usuario y devolver las cabeceras
     */
    protected function actingAsJwtUser(?User $user = null): array
    {
        return $this->userWithAuth($user)[1];
    }

    /**
     * Crea un usuario con una jerarquía de categorías para pruebas.
     */
    protected function createUserWithCategories(): User
    {
        $user = User::factory()->create();
        
        $parentCategory = \App\Models\Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Gastos Fijos'
        ]);

        \App\Models\Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Alquiler',
            'parent_id' => $parentCategory->id
        ]);

        return $user;
    }
}
