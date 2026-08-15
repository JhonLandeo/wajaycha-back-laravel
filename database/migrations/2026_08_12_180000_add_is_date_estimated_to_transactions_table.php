<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether `date_operation` is when the money moved, or a stand-in.
 *
 * A capture only carries the real operation time when Gemini managed to read a
 * date off the receipt. Otherwise `RegisterCapturedTransactionAction` falls back
 * to `now()` — the moment the photo was sent — and until now nothing recorded
 * that the two cases had happened.
 *
 * That distinction stopped being cosmetic when reconciliation began deciding by
 * proximity alone. "Six seconds apart" is decisive evidence between two real
 * timestamps and means nothing between a real one and a stand-in, and a rule
 * that cannot tell them apart would merge on the strength of when the user
 * happened to open Telegram.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('is_date_estimated')
                ->default(false)
                ->after('date_operation')
                ->comment('date_operation es un relleno (hora de captura), no la hora real de la operacion.');
        });

        // Las filas historicas se quedan en `false`. No es que se sepa que su fecha
        // es exacta: es que no hay forma de averiguarlo hacia atras, y marcarlas
        // todas como estimadas seria inventar un dato para el lado contrario.
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('is_date_estimated');
        });
    }
};
