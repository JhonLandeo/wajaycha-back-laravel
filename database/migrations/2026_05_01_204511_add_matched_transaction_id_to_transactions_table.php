<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Migrate clean records from transaction_yapes to transactions
        if (Schema::hasTable('transaction_yapes')) {
            $yapes = DB::table('transaction_yapes')->get();
            foreach ($yapes as $yape) {
                // Check if it was already matched in the old system
                $oldMatchId = DB::table('transactions')
                    ->where('yape_id', $yape->id)
                    ->value('id');

                $newTransactionId = DB::table('transactions')->insertGetId([
                    'message' => $yape->message,
                    'detail_id' => $yape->detail_id,
                    'amount' => $yape->amount,
                    'date_operation' => $yape->date_operation,
                    'type_transaction' => $yape->type_transaction,
                    'category_id' => $yape->category_id,
                    'user_id' => $yape->user_id,
                    'financial_entity_id' => 1, // BCP
                    'payment_service_id' => 1, // Yape
                    'source_type' => 'import_app',
                    'old_yape_id' => $yape->id,
                    'created_at' => $yape->created_at,
                    'updated_at' => $yape->updated_at,
                    'is_manual' => false,
                ]);

                // Update tags to point to the new unified transaction ID
                DB::table('transaction_tag')
                    ->where('transaction_yape_id', $yape->id)
                    ->update(['transaction_id' => $newTransactionId]);
            }
        }

        // 2. Clear existing matches to start fresh
        DB::table('transactions')->update(['matched_transaction_id' => null]);

        // 3. Stage 1: Match by explicit old link (yape_id -> old_yape_id)
        DB::statement("
            UPDATE transactions t_app
            SET matched_transaction_id = t_bank.id
            FROM transactions t_bank
            WHERE t_app.old_yape_id = t_bank.yape_id
            AND t_app.source_type = 'import_app'
            AND t_bank.source_type = 'import_statement'
            AND t_app.matched_transaction_id IS NULL
        ");

        // 4. Stage 2: Sequential Matching (Same day)
        DB::statement("
            UPDATE transactions t_app
            SET matched_transaction_id = sub.statement_id
            FROM (
                WITH StatementNumbered AS (
                    SELECT id, user_id, amount, type_transaction, date_operation::date as op_date,
                           ROW_NUMBER() OVER (
                               PARTITION BY user_id, amount, type_transaction, date_operation::date 
                               ORDER BY created_at ASC, id ASC
                           ) as rn
                    FROM transactions
                    WHERE source_type = 'import_statement'
                    AND (yape_id IS NULL OR matched_transaction_id IS NULL)
                ),
                AppNumbered AS (
                    SELECT id, user_id, amount, type_transaction, date_operation::date as op_date,
                           ROW_NUMBER() OVER (
                               PARTITION BY user_id, amount, type_transaction, date_operation::date 
                               ORDER BY created_at ASC, id ASC
                           ) as rn
                    FROM transactions
                    WHERE source_type = 'import_app'
                    AND matched_transaction_id IS NULL
                )
                SELECT s.id as statement_id, a.id as app_id
                FROM StatementNumbered s
                JOIN AppNumbered a ON s.user_id = a.user_id 
                    AND s.amount = a.amount 
                    AND s.type_transaction = a.type_transaction 
                    AND s.op_date = a.op_date 
                    AND s.rn = a.rn
            ) as sub
            WHERE t_app.id = sub.app_id
        ");

        // 5. Stage 3: Fuzzy Matching (+/- 1 day)
        DB::statement("
            UPDATE transactions t_app
            SET matched_transaction_id = sub.statement_id
            FROM (
                WITH StatementPool AS (
                    SELECT id, user_id, amount, type_transaction, date_operation::date as op_date
                    FROM transactions
                    WHERE source_type = 'import_statement'
                    AND matched_transaction_id IS NULL
                ),
                AppPool AS (
                    SELECT id, user_id, amount, type_transaction, date_operation::date as op_date
                    FROM transactions
                    WHERE source_type = 'import_app'
                    AND matched_transaction_id IS NULL
                )
                SELECT DISTINCT ON (a.id) a.id as app_id, s.id as statement_id
                FROM AppPool a
                JOIN StatementPool s ON a.user_id = s.user_id 
                    AND a.amount = s.amount 
                    AND a.type_transaction = s.type_transaction 
                    AND ABS(a.op_date - s.op_date) = 1
                ORDER BY a.id, s.id ASC
            ) as sub
            WHERE t_app.id = sub.app_id
        ");

        // 6. Stage 4: Atomic Auto-generation & Linking using CTE + RETURNING
        // This ensures 100% reconciliation for valid Yape records by creating bank counterparts
        DB::statement("
            WITH inserted_rows AS (
                INSERT INTO transactions (
                    message, detail_id, amount, date_operation, type_transaction, 
                    category_id, user_id, financial_entity_id, payment_service_id, 
                    source_type, created_at, updated_at, is_manual,
                    old_yape_id -- Bridge column to track app_id
                )
                SELECT 
                    'Rescate (Auto): ' || message, 
                    detail_id, amount, date_operation, type_transaction, 
                    category_id, user_id, 1, 1, 
                    'import_statement', now(), now(), false,
                    id -- Current ID of the import_app record
                FROM transactions 
                WHERE source_type = 'import_app' 
                AND matched_transaction_id IS NULL
                RETURNING id as statement_id, old_yape_id as app_id
            )
            UPDATE transactions t_app
            SET matched_transaction_id = ins.statement_id
            FROM inserted_rows ins
            WHERE t_app.id = ins.app_id
        ");

        // 7. Cleanup: Remove temporary columns and table
        if (Schema::hasColumn('transactions', 'yape_id')) {
            DB::statement('ALTER TABLE transactions DROP COLUMN IF EXISTS yape_id CASCADE');
        }
        if (Schema::hasColumn('transactions', 'old_yape_id')) {
            DB::statement('ALTER TABLE transactions DROP COLUMN IF EXISTS old_yape_id CASCADE');
        }
        if (Schema::hasColumn('transaction_tag', 'transaction_yape_id')) {
            DB::statement('ALTER TABLE transaction_tag DROP COLUMN IF EXISTS transaction_yape_id CASCADE');
        }
        DB::statement('DROP TABLE IF EXISTS transaction_yapes CASCADE');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
