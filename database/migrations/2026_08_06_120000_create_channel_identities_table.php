<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Channel identity replaces users.whatsapp_phone as the way a capture is
     * attributed to a User: a per-channel column would need a new column per channel.
     *
     * The normalisation below is deliberately SELF-CONTAINED. The runtime path gets
     * its own copy of this rule, and the two are duplicated on purpose: a migration
     * is a snapshot and must still run years from now on a fresh database, after the
     * application class has been renamed, moved or deleted. Resolving app code from
     * a migration turns a routine refactor into a broken `migrate` on every new
     * environment.
     */
    private const MIN_DIGITS = 8;

    private const MAX_DIGITS = 15;

    public function up(): void
    {
        Schema::create('channel_identities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Usuario dueño de esta cuenta de canal.');

            $table->string('channel', 20)
                ->comment('Canal de captura: whatsapp, telegram.');

            $table->string('external_id', 64)
                ->comment('Identificador del remitente en su canal: chat id de Telegram, o digitos E.164 sin + en WhatsApp.');

            $table->timestampTz('linked_at')
                ->comment('Momento en que la cuenta de canal quedo ligada al usuario.');

            $table->string('legacy_whatsapp_phone', 20)
                ->nullable()
                ->comment('Valor original de users.whatsapp_phone previo a la normalizacion. Es el camino de rollback y se elimina al archivar el cambio.');

            $table->timestampsTz();

            // Una cuenta de canal pertenece a un solo usuario. La garantia vive en el
            // indice, no en la aplicacion, para que dos procesos concurrentes no
            // puedan insertar la misma identidad.
            $table->unique(['channel', 'external_id'], 'unq_channel_identities_channel_external_id');
            $table->index('user_id', 'idx_channel_identities_user_id');

            $table->comment('Liga una cuenta de un canal de captura con el usuario dueño. Reemplaza users.whatsapp_phone.');
        });

        $this->backfillFromUsers();
    }

    public function down(): void
    {
        // users.whatsapp_phone nunca se toco, asi que los valores originales siguen
        // intactos y basta con soltar la tabla.
        Schema::dropIfExists('channel_identities');
    }

    /**
     * Copies users.whatsapp_phone into channel_identities.
     *
     * Aborts on the first row it cannot resolve with certainty rather than guessing:
     * a misattributed identity routes one person's transactions into another
     * person's ledger, and nothing downstream would notice.
     */
    private function backfillFromUsers(): void
    {
        // Transaccion propia en lugar de confiar en la que el Migrator abre por
        // nosotros: hace explicito el todo-o-nada y permite probarlo.
        DB::transaction(fn () => $this->copyEveryPhone());
    }

    private function copyEveryPhone(): void
    {
        $seen = [];
        $migrated = 0;
        $now = now();

        $users = DB::table('users')
            ->select('id', 'whatsapp_phone')
            ->whereNotNull('whatsapp_phone')
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            $original = (string) $user->whatsapp_phone;
            $externalId = $this->normalise($original, (int) $user->id);

            if (isset($seen[$externalId])) {
                throw new RuntimeException(
                    "Los usuarios {$seen[$externalId]} y {$user->id} normalizan al mismo identificador [{$externalId}]. "
                    .'La migracion aborta para no atribuir una cuenta de canal al usuario equivocado. '
                    .'Corrija users.whatsapp_phone y vuelva a ejecutar la migracion.'
                );
            }

            $seen[$externalId] = (int) $user->id;

            DB::table('channel_identities')->insert([
                'user_id' => $user->id,
                'channel' => 'whatsapp',
                'external_id' => $externalId,
                'linked_at' => $now,
                'legacy_whatsapp_phone' => $original,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $migrated++;

            Log::info("🔁 ChannelIdentity: usuario {$user->id} migrado, [{$original}] -> [{$externalId}].");
        }

        Log::info("✅ ChannelIdentity: backfill completo, {$migrated} identidad(es) creada(s) de {$users->count()} candidata(s).");
    }

    private function normalise(string $phone, int $userId): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $length = strlen($digits);

        if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS) {
            throw new RuntimeException(
                "El telefono [{$phone}] del usuario {$userId} no se puede normalizar a E.164 ({$length} digitos). "
                .'La migracion aborta en lugar de adivinar. Corrija el dato y vuelva a ejecutar la migracion.'
            );
        }

        return $digits;
    }
};
