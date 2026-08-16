<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the sweep LOOKED AT, as opposed to what it said.
     *
     * `coaching_observations` only ever gets a row when the coach spoke, so the
     * absence of a row there is ambiguous in four different ways at once: the
     * month was clean, nothing was spent, the category had no budget, or the
     * coach was switched off. Any streak built on that table would therefore
     * have to guess, and "three months clean" said about a month nobody looked
     * at is exactly the fabricated figure ADR-0009 exists to prevent.
     *
     * The ambiguity is not resolvable after the fact. `categories.monthly_budget`
     * is mutable and carries no history, so today's budget cannot answer what the
     * budget was in June — which is precisely what deciding "was June clean?"
     * requires. The verdict has to be written down when it is known.
     *
     * One row per (user, month, category), rewritten on every sweep rather than
     * appended to: a category clean on the 3rd and over budget on the 18th must
     * end the month recorded as over budget, and last-write-wins gives that for
     * free while keeping the table one row per category-month.
     *
     * Purely additive. No existing column, row, migration, SQL function or view
     * is touched, and nothing in the speaking path reads this table.
     */
    public function up(): void
    {
        Schema::create('coaching_evaluations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Usuario cuyo mes se evaluo.');

            $table->date('period_month')
                ->comment('Primer dia del mes evaluado en la zona horaria de referencia (America/Lima). Etiqueta de calendario, no instante — el mismo criterio que coaching_observations.period_month, y por la misma razon.');

            $table->foreignId('category_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Categoria evaluada. NOT NULL a diferencia de coaching_observations: aca no existe el sujeto "ceguera del coach" agregado, cada categoria ciega se registra por separado con outcome = blind.');

            $table->string('outcome', 20)
                ->comment('Veredicto del mes para la categoria: clean (mirada y sin cruzar ninguna banda), blind (con gasto pero sin presupuesto, imposible de evaluar), o la banda alcanzada — projected_over, over_budget, envelope_consumed, envelope_exceeded. Nunca NULL: un NULL aca volveria a introducir la misma ambiguedad que esta tabla existe para eliminar.');

            $table->string('budget_period', 10)
                ->comment('monthly o yearly al momento de evaluar. Se guarda porque el presupuesto es mutable: sin esto, un sobre anual leido meses despues se interpretaria contra el mes.');

            $table->decimal('budget_amount', 15, 2)
                ->comment('Presupuesto de la categoria al momento de evaluar, EN LA UNIDAD que indica budget_period: mensual para monthly, anual para yearly. No se divide ni se anualiza nada al guardarlo, asi que leerlo sin mirar budget_period da un numero doce veces equivocado. Es la razon principal por la que esta tabla existe: categories.monthly_budget no tiene historia y cambiarlo reescribiria el pasado.');

            $table->decimal('spent_amount', 15, 2)
                ->comment('Gasto acumulado considerado al evaluar. Mensual para budget_period = monthly; acumulado del anio para yearly, igual que la banda que se compara contra el.');

            $table->timestampTz('evaluated_at')
                ->comment('Momento del ultimo barrido que escribio esta fila. Cambia con cada reescritura del mes en curso y queda congelado cuando el mes termina.');

            $table->timestampsTz();

            // Un solo veredicto vigente por categoria-mes. Es tambien la clave que
            // hace posible el upsert: sin este indice, cada barrido nocturno
            // agregaria una fila por categoria y el "ultimo gana" se volveria un
            // GROUP BY caro sobre una tabla que crece treinta veces mas rapido.
            $table->unique(
                ['user_id', 'period_month', 'category_id'],
                'unq_coaching_evaluations_user_period_category'
            );

            // Sin indice para rachas todavia. La consulta que lo justificaria
            // ("cuantos meses seguidos limpio en Transporte") no existe, y este
            // codebase ya decidio una vez que no se agrega superficie sin caller
            // — ver por que SpokenObservationLedger no tiene un hermano bulk.
            // Agregarlo junto con la consulta es aditivo; adivinar su orden de
            // columnas hoy es apostar.

            $table->comment('Lo que el barrido de coaching miro cada mes, hablara o no. Existe para que "no dijo nada" se pueda distinguir de "no lo evaluo", que es la unica forma de afirmar una racha sin inventarla. Solo se registran categorias con gasto en el mes: es el mismo universo que evalua el coach.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coaching_evaluations');
    }
};
