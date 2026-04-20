<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Eliminar la vista dependiente
        DB::statement('DROP VIEW IF EXISTS public.v_unified_transactions');

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

        // 4. Recrear la vista
        $this->createUnifiedTransactionsView();
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS public.v_unified_transactions');

        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('date_operation')->change(); // Volver a timestamp sin TZ
        });

        Schema::table('transaction_yapes', function (Blueprint $table) {
            $table->timestamp('date_operation')->change();
        });

        $this->createUnifiedTransactionsView();
    }

    private function createUnifiedTransactionsView(): void
    {
        $sql = <<<SQL
            CREATE VIEW public.v_unified_transactions AS 
            WITH transactions_base AS (
                SELECT t.id, t.message, t.amount, t.date_operation, t.type_transaction,
                       t.category_id, t.detail_id, d.description AS detail_name,
                       t.yape_id AS matched_yape_id, t.user_id, 'transaction'::text AS source_type
                FROM transactions t
                JOIN details d ON d.id = t.detail_id
            ), yapes_unmatched AS (
                SELECT ty.id, ty.message, ty.amount, ty.date_operation, ty.type_transaction,
                       ty.category_id, ty.detail_id, d.description AS detail_name,
                       ty.id AS matched_yape_id, ty.user_id, 'yape_unmatched'::text AS source_type
                FROM transaction_yapes ty
                JOIN details d ON d.id = ty.detail_id
                WHERE NOT EXISTS (SELECT 1 FROM transactions t WHERE t.yape_id = ty.id)
            )
            SELECT * FROM transactions_base UNION ALL SELECT * FROM yapes_unmatched;
        SQL;
        DB::unprepared($sql);
    }
};
