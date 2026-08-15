<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Vuelve a crear `get_transactions_by_detail` porque en produccion quedo
     * una version que referencia una tabla borrada.
     *
     * La funcion se definio en `2025_10_20_205214_fn_get_transactions_by_detail`,
     * cuyo cuerpo leia `transaction_yapes`. Ese archivo se EDITO despues, en
     * `e12da3b`, para consolidar Yape dentro de `transactions` — pero Laravel ya
     * tenia la migracion registrada como ejecutada y no volvio a correrla.
     * Editar el archivo cambio el repositorio y no cambio la base.
     *
     * Cuando `2026_05_01_204511` borro `transaction_yapes`, la funcion quedo
     * apuntando a una relacion inexistente. Desde entonces cualquier peticion
     * con `recurring=true` devuelve `SQLSTATE[42P01]`, y solo esa: el resto de
     * la pantalla usa `get_transactions`, que si nacio con el cuerpo correcto.
     *
     * Aca esta el costo real de definir reglas de negocio dentro de migraciones,
     * que `CLAUDE.md` ya prohibe extender: una migracion es un evento que ocurre
     * una vez, y una funcion es un estado que tiene que ser cierto siempre. Los
     * dos no se sincronizan solos, y la deriva no avisa — se descubre con un 500
     * meses despues.
     *
     * Se DROPEA antes de crear, y por firma exacta no alcanza: no sabemos con
     * que tipos de argumento quedo la version rancia en cada entorno, y
     * `CREATE OR REPLACE` falla si el tipo de retorno cambio, mientras que una
     * firma distinta crearia una sobrecarga silenciosa junto a la vieja. El
     * bloque de abajo borra todas las variantes, sea cual sea su firma.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $do$
            DECLARE
                fn record;
            BEGIN
                FOR fn IN
                    SELECT oid::regprocedure AS signature
                    FROM pg_proc
                    WHERE proname = 'get_transactions_by_detail'
                      AND pronamespace = 'public'::regnamespace
                LOOP
                    EXECUTE 'DROP FUNCTION ' || fn.signature;
                END LOOP;
            END
            $do$;
        SQL);

        // Copia literal del cuerpo vigente en
        // `2025_10_20_205214_fn_get_transactions_by_detail`. Se duplica en vez de
        // importarse: una migracion tiene que seguir describiendo lo que hizo el
        // dia que corrio, y leer el archivo de otra la volveria dependiente de
        // ediciones futuras — exactamente el defecto que esta migracion repara.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION get_transactions_by_detail(p_per_page integer, p_page integer, p_year integer, p_month integer, p_type type_transaction, p_amount numeric, p_search character varying, p_category character varying, p_user_id integer, p_recurring boolean, p_weekend boolean, p_workday boolean)
            RETURNS TABLE(detail_id bigint, detail_name character varying, child_transactions jsonb, frequency bigint, amount numeric, total_count bigint)
            LANGUAGE plpgsql
            AS $fn$
                BEGIN
                    RETURN QUERY
                    WITH FilteredData AS (
                        SELECT
                            t.detail_id,
                            d.description AS detail_name,
                            jsonb_agg(
                                jsonb_build_object(
                                    'id', t.id,
                                    'message', t.message,
                                    'amount', t.amount,
                                    'date_operation', TO_CHAR(
                                        t.date_operation::timestamp,
                                        'Dy DD Mon YYYY HH12:MI AM'
                                    ),
                                    'type_transaction', t.type_transaction,
                                    'category_id', t.category_id,
                                    'detail_id', t.detail_id,
                                    'detail_name', d.description,
                                    'source_type', t.source_type,
                                    'is_manual', t.is_manual
                                )
                            ) AS child_transactions,
                            COUNT(t.id) AS frequency,
                            SUM(CASE WHEN t.type_transaction::type_transaction = 'income' THEN t.amount ELSE 0 END)
                            - SUM(CASE WHEN t.type_transaction::type_transaction = 'expense' THEN t.amount ELSE 0 END) AS amount
                        FROM
                            transactions AS t
                        JOIN
                            details AS d ON d.id = t.detail_id
                        WHERE
                            t.user_id = p_user_id
                            AND t.matched_transaction_id IS NULL -- Exclude reconciled children
                            AND (p_year IS NULL OR EXTRACT(YEAR FROM t.date_operation) = p_year)
                            AND (p_month IS NULL OR EXTRACT(MONTH FROM t.date_operation) = p_month)
                            AND (p_weekend = FALSE OR EXTRACT(DOW FROM t.date_operation) IN (0, 6))
                            AND (p_workday = FALSE OR EXTRACT(DOW FROM t.date_operation) BETWEEN 1 AND 5)
                            AND (p_type IS NULL OR t.type_transaction::type_transaction = p_type)
                            AND (p_amount IS NULL OR p_amount = 0.00 OR t.amount = p_amount)
                            AND (CASE
                                WHEN p_category = 'without_category' THEN t.category_id IS NULL
                                WHEN p_category IS NOT NULL AND p_category <> 'without_category' THEN t.category_id = p_category::BIGINT
                                ELSE TRUE
                            END)
                            AND (p_search IS NULL OR d.description ILIKE '%' || p_search || '%' OR t.message ILIKE '%' || p_search || '%')
                        GROUP BY
                            t.detail_id, d.description
                        HAVING COUNT(t.id) > 1
                    )
                    SELECT
                        fd.*,
                        COUNT(*) OVER() AS total_count
                    FROM
                        FilteredData fd
                    ORDER BY
                        fd.frequency DESC NULLS LAST
                    LIMIT p_per_page
                    OFFSET (p_page - 1) * p_per_page;
                END;
            $fn$;
        SQL);
    }

    /**
     * Sin reversa a proposito. Esta migracion repara una deriva entre el
     * repositorio y la base; deshacerla solo podria significar reinstalar una
     * funcion rota que lee una tabla que ya no existe.
     */
    public function down(): void {}
};
