<?php

use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
         $defaultTags = [
            // Por Persona
            'Pareja',
            'Familia',
            'Amigos',
            'Mascotas',
            // Por Evento
            'Vacaciones',
            'Cumpleaños',
            'Aniversario',
            'Celebración',
            // Por Contexto
            'Trabajo',
            'Reembolsable',
            'Gasto Hormiga'
        ];

        $tagsToInsertForUser = [];
        $now = now();

        $userWithoutTags = User::doesnthave('tags')->get();

        foreach ($userWithoutTags as $user) {
            foreach ($defaultTags as $tagName) {
                $tagsToInsertForUser[] = [
                    'name' => $tagName,
                    'user_id' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        if (!empty($tagsToInsertForUser)) {
            Tag::insert($tagsToInsertForUser);
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        
    }
};
