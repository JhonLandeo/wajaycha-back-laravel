<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remembers which channel deliveries were already accepted, so a redelivery does
     * not become a second Transaction.
     *
     * Telegram retries an update until it gets a 200, and a slow response or a
     * restart mid-request is enough to earn a duplicate.
     */
    public function up(): void
    {
        Schema::create('processed_channel_updates', function (Blueprint $table) {
            $table->id();

            $table->string('channel', 20)
                ->comment('Canal de captura que entrego el update: telegram, whatsapp.');

            $table->string('external_update_id', 64)
                ->comment('Identificador de la entrega en su canal: update_id de Telegram.');

            $table->timestampTz('processed_at')
                ->comment('Momento en que se acepto la entrega.');

            $table->timestampsTz();

            // La garantia de no duplicar vive ACA, no en la aplicacion. Un chequeo
            // "ya lo vi?" seguido de un insert deja una ventana en la que dos workers
            // concurrentes pasan ambos y despachan dos veces.
            $table->unique(['channel', 'external_update_id'], 'unq_processed_channel_updates_channel_update');
            $table->index('processed_at', 'idx_processed_channel_updates_processed_at');

            $table->comment('Entregas de canal ya aceptadas. Existe para que un reintento no duplique una transaccion.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_channel_updates');
    }
};
