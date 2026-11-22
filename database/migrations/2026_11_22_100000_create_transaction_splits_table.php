<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un movimiento puede repartirse entre varias categorías por monto.
     *
     * La transacción sigue siendo una sola fila —una línea del banco es una
     * `Transaction`, y la conciliación uno a uno no cambia— pero su atribución
     * deja de ser un único `category_id`. Ver ADR-0050.
     *
     * Invariantes, todos verificados en base de datos porque de ellos depende
     * que ningún reporte cuente dos veces o deje de contar:
     *
     * 1. La suma de los splits iguala el monto del movimiento.
     * 2. Una transacción tiene `category_id` o splits, nunca ambos.
     * 3. Cada monto es positivo y una categoría no se repite en el reparto.
     *
     * Fase 0: esquema, triggers y vista. La tabla queda vacía, así que
     * `v_transaction_allocations` devuelve exactamente lo mismo que hoy para
     * toda transacción con `category_id`. Ningún lector cambia todavía.
     */
    public function up(): void
    {
        // La FK compuesta de los splits necesita una clave única que incluya al
        // dueño, igual que `unq_categories_id_user_id` para `categories`. Sin
        // esto, un split podría apuntar a la transacción de otro usuario.
        Schema::table('transactions', function (Blueprint $table) {
            $table->unique(['id', 'user_id'], 'unq_transactions_id_user_id');
        });

        Schema::create('transaction_splits', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('transaction_id')
                ->comment('Movimiento que se reparte. La FK compuesta con user_id se agrega abajo.');

            $table->unsignedBigInteger('category_id')
                ->comment('Destino del monto asignado. La FK compuesta con user_id se agrega abajo.');

            $table->foreignId('user_id')
                ->comment('Dueño del movimiento. Límite de autorización, no un filtro de conveniencia.')
                ->constrained()
                ->cascadeOnDelete();

            $table->decimal('amount', 10, 2)
                ->comment('Monto asignado a la categoría. La suma de los splits iguala transactions.amount.');

            $table->timestampsTz();

            $table->unique(
                ['transaction_id', 'category_id'],
                'unq_transaction_splits_transaction_category'
            );

            // El reporte agrupa por categoría y filtra por usuario; este índice es
            // el que evita escanear la tabla completa por cada categoría.
            $table->index(
                ['category_id', 'user_id'],
                'idx_transaction_splits_category_user'
            );

            $table->comment('Reparto explícito de un movimiento entre varias categorías. Una fila por categoría y monto. Ver ADR-0050.');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE transaction_splits
              ADD CONSTRAINT fk_transaction_splits_transaction_id
              FOREIGN KEY (transaction_id, user_id) REFERENCES transactions (id, user_id)
              ON DELETE CASCADE
        SQL);

        // Sin cascada, igual que transactions.category_id: borrar una categoría
        // con splits asignados queda bloqueado hasta reasignarlos. Un cascade
        // acá rompería la suma sin que nadie lo note.
        DB::statement(<<<'SQL'
            ALTER TABLE transaction_splits
              ADD CONSTRAINT fk_transaction_splits_category_id
              FOREIGN KEY (category_id, user_id) REFERENCES categories (id, user_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE transaction_splits
              ADD CONSTRAINT chk_transaction_splits_amount_positive
              CHECK (amount > 0)
        SQL);

        // Un único punto de verdad para los dos invariantes que cruzan tablas.
        // Se llama desde triggers diferidos, así que evalúa el estado final de
        // la transacción, no el intermedio de una inserción fila por fila.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_transaction_allocation(p_transaction_id bigint)
            RETURNS void
            LANGUAGE plpgsql
            AS $$
            DECLARE
                v_amount numeric(10,2);
                v_category_id bigint;
                v_splits_sum numeric(10,2);
                v_splits_count bigint;
            BEGIN
                SELECT amount, category_id
                  INTO v_amount, v_category_id
                  FROM transactions
                 WHERE id = p_transaction_id;

                -- La transacción desapareció: el borrado en cascada está
                -- retirando sus splits y no hay invariante que sostener.
                IF NOT FOUND THEN
                    RETURN;
                END IF;

                SELECT COALESCE(SUM(amount), 0), COUNT(*)
                  INTO v_splits_sum, v_splits_count
                  FROM transaction_splits
                 WHERE transaction_id = p_transaction_id;

                -- Sin splits no hay reparto: la transacción puede tener una
                -- categoría o estar sin categorizar, ambos estados válidos.
                IF v_splits_count = 0 THEN
                    RETURN;
                END IF;

                IF v_category_id IS NOT NULL THEN
                    RAISE EXCEPTION
                        'La transaccion % tiene reparto por categoria y category_id a la vez',
                        p_transaction_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_splits_sum <> v_amount THEN
                    RAISE EXCEPTION
                        'Los splits de la transaccion % suman % y el movimiento es %',
                        p_transaction_id, v_splits_sum, v_amount
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_check_transaction_split()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    PERFORM assert_transaction_allocation(OLD.transaction_id);
                ELSIF TG_OP = 'UPDATE' THEN
                    IF OLD.transaction_id IS DISTINCT FROM NEW.transaction_id THEN
                        PERFORM assert_transaction_allocation(OLD.transaction_id);
                    END IF;
                    PERFORM assert_transaction_allocation(NEW.transaction_id);
                ELSE
                    PERFORM assert_transaction_allocation(NEW.transaction_id);
                END IF;

                RETURN NULL;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER trg_transaction_splits_invariant
            AFTER INSERT OR UPDATE OR DELETE ON transaction_splits
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION trg_check_transaction_split();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_check_transaction_allocation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.amount IS NOT DISTINCT FROM OLD.amount
                   AND NEW.category_id IS NOT DISTINCT FROM OLD.category_id THEN
                    RETURN NULL;
                END IF;

                PERFORM assert_transaction_allocation(NEW.id);

                RETURN NULL;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER trg_transactions_allocation_invariant
            AFTER UPDATE ON transactions
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION trg_check_transaction_allocation();
        SQL);

        // La vista que unifica la atribución. Es el único punto que decide si
        // una fila aporta su monto completo o su reparto; los reportes de la
        // Fase 1 leen de acá, nunca de transactions.category_id.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW public.v_transaction_allocations AS
            SELECT
                ut.id AS transaction_id,
                ut.user_id,
                ut.date_operation,
                ut.type_transaction,
                ut.detail_id,
                ut.amount AS transaction_amount,
                ts.category_id,
                ts.amount AS allocated_amount
            FROM v_unified_transactions ut
            JOIN transaction_splits ts ON ts.transaction_id = ut.id

            UNION ALL

            SELECT
                ut.id,
                ut.user_id,
                ut.date_operation,
                ut.type_transaction,
                ut.detail_id,
                ut.amount,
                ut.category_id,
                ut.amount
            FROM v_unified_transactions ut
            WHERE ut.category_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1
                    FROM transaction_splits ts
                   WHERE ts.transaction_id = ut.id
              );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS public.v_transaction_allocations');

        DB::unprepared('DROP TRIGGER IF EXISTS trg_transaction_splits_invariant ON transaction_splits');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_transactions_allocation_invariant ON transactions');

        DB::unprepared('DROP FUNCTION IF EXISTS trg_check_transaction_split()');
        DB::unprepared('DROP FUNCTION IF EXISTS trg_check_transaction_allocation()');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_transaction_allocation(bigint)');

        Schema::dropIfExists('transaction_splits');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('unq_transactions_id_user_id');
        });
    }
};
