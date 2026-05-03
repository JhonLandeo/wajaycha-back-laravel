<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Eliminar la vista que depende de las columnas a modificar
        DB::unprepared("DROP VIEW IF EXISTS public.v_unified_transactions CASCADE;");

        // 2. Modificar la tabla 'transactions'
        Schema::table('transactions', function (Blueprint $table) {
            $table->comment('Registra cada transacción financiera del usuario (gastos e ingresos)');
            $table->decimal('amount', 10, 2)->comment('Monto de la operación.')->change();
            $table->timestampTz('date_operation')->comment('Fecha con zona horaria.')->change();
            $table->boolean('is_manual')->default(false)->comment('Registro manual?')->change();
        });

        // 3. Modificar la tabla 'transaction_yapes' (IMPORTANTE para el UNION)
        Schema::table('transaction_yapes', function (Blueprint $table) {
            $table->timestampTz('date_operation')->change();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('date_operation')->change(); // Volver a timestamp sin TZ
        });

        Schema::table('transaction_yapes', function (Blueprint $table) {
            $table->timestamp('date_operation')->change();
        });
    }
};
