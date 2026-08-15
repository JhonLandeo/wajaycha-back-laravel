<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-use, short-lived proof that the person holding a channel account is the
     * person who was logged into the app when the link was requested.
     *
     * Only the SHA-256 hash is stored: a database leak yields nothing redeemable.
     */
    public function up(): void
    {
        Schema::create('channel_link_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Usuario que pidio vincular una cuenta de canal.');

            $table->string('token_hash', 64)
                ->comment('SHA-256 del token. El token en claro NUNCA se guarda ni se registra en logs.');

            $table->timestampTz('expires_at')
                ->comment('Momento a partir del cual el token ya no sirve.');

            $table->timestampTz('redeemed_at')
                ->nullable()
                ->comment('No nulo significa gastado. Un token vale por una sola vinculacion.');

            $table->timestampsTz();

            $table->unique('token_hash', 'unq_channel_link_tokens_token_hash');
            $table->index('expires_at', 'idx_channel_link_tokens_expires_at');

            $table->comment('Tokens de un solo uso para ligar una cuenta de canal con un usuario.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_link_tokens');
    }
};
