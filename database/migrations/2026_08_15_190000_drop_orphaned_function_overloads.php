<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Borra dos sobrecargas que ninguna migracion crea y que solo existen en las
     * bases que vienen acumulando migraciones desde hace meses.
     *
     * Comparadas una base construida desde cero con `migrate` y la base viva,
     * la primera tiene cuatro funciones de informe con una firma cada una y la
     * segunda tiene seis. Las dos de mas son versiones anteriores que quedaron
     * al lado de las nuevas: `CREATE OR REPLACE` solo reemplaza cuando la firma
     * coincide, y al agregarle un parametro a una funcion crea otra en vez de
     * pisar la vieja. Nadie las borro porque nada avisa.
     *
     * `get_details(integer, integer)` es la que importa. Su cuerpo no filtra por
     * usuario en ningun lado:
     *
     *     FROM details d
     *     WHERE EXISTS (SELECT 1 FROM transactions t WHERE t.detail_id = d.id)
     *
     * Devuelve los comercios de todos los usuarios. Hoy no la llama nadie
     * —`DetailRepository` usa `get_details(?, ?, ?)`, la que si recibe
     * `p_user_id`— pero PostgreSQL elige la sobrecarga por cantidad y tipo de
     * argumentos, asi que un refactor que pierda el tercer parametro no falla:
     * responde, con datos ajenos. Una fuga que no se anuncia como error.
     *
     * `get_monthly_category_budget_report` de cinco argumentos es la version
     * previa a que se agregara la busqueda. Esa si filtra por usuario, asi que
     * solo es peso muerto — pero invita al mismo accidente y se va con la otra.
     *
     * Se borran por firma exacta: las versiones vigentes tienen distinta
     * aridad y no se tocan.
     */
    public function up(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS get_details(integer, integer)');
        DB::statement('DROP FUNCTION IF EXISTS get_monthly_category_budget_report(integer, integer, integer, integer, integer)');
    }

    /**
     * Sin reversa. Reinstalar una funcion que lee los datos de todos los
     * usuarios no es un escenario que valga la pena hacer posible.
     */
    public function down(): void {}
};
