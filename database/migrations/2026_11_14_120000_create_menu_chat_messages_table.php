<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial del chat que ajusta el menú semanal (ADR-0020, decisión 6).
     *
     * Una conversación pertenece a un usuario y a una semana. El contenido es
     * texto legible; `payload` guarda las operaciones estructuradas que la IA
     * propuso para poder aplicarlas solo después de confirmar. El chat nunca
     * escribe entradas del menú por sí solo.
     */
    public function up(): void
    {
        Schema::create('menu_chat_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Dueño de la conversación. Límite de autorización.');

            $table->date('week_start')
                ->comment('Lunes de la semana que se está ajustando, en America/Lima.');

            $table->text('role')
                ->comment('user | assistant. Quién emitió el mensaje.');

            $table->text('content')
                ->comment('Texto legible del mensaje.');

            $table->jsonb('payload')
                ->nullable()
                ->comment('Operaciones estructuradas propuestas o aplicadas, y metadatos del turno.');

            $table->timestampsTz();

            $table->index(['user_id', 'week_start', 'created_at'], 'idx_menu_chat_messages_user_week');
            $table->comment('Historial del chat de ajuste del menú semanal (ADR-0020).');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_chat_messages');
    }
};
