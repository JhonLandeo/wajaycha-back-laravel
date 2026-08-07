<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The spoken-observation memory that lets the scheduled sweep and the
     * capture-time check share one voice.
     *
     * Purely additive. No existing column, row, migration, SQL function or view is
     * modified (design.md §4). The unique index below is the arbiter for "never
     * speak twice about the same band" — application code only decides "never
     * speak downward", and that decision is not correctness-critical (design.md D6).
     */
    public function up(): void
    {
        Schema::create('coaching_observations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Usuario al que se le hablo.');

            $table->date('period_month')
                ->comment('Primer dia del mes coacheado en la zona horaria de referencia (America/Lima). Es una etiqueta de calendario, no un instante: guardarlo como timestamptz reintroduciria justo la ambiguedad que esta columna existe para eliminar.');

            $table->string('subject_key', 40)
                ->comment('Sujeto de la observacion: "category:{id}" o el literal "blindness". Nunca NULL: PostgreSQL considera distintos los NULL en un indice unico, y un category_id nulo dejaria repetir el aviso de ceguera todos los dias.');

            $table->foreignId('category_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete()
                ->comment('Categoria observada. NULL cuando el sujeto es la ceguera del coach. Existe para integridad y consultas; la clave unica usa subject_key, no esta columna.');

            $table->string('band', 20)
                ->comment('Banda de severidad ya hablada: projected_over, over_budget o blind. Solo se vuelve a hablar al cruzar a una banda peor; el indice unico impide repetir la misma banda en el mes.');

            $table->boolean('is_lumpy')
                ->default(false)
                ->comment('True cuando una sola transaccion domina el gasto del mes en la categoria y por eso se reporto nivel en lugar de proyeccion.');

            $table->decimal('budget_amount', 15, 2)
                ->comment('Presupuesto mensual de la categoria al momento de hablar.');

            $table->decimal('spent_amount', 15, 2)
                ->comment('Gasto acumulado del mes en la categoria al momento de hablar.');

            $table->decimal('projected_amount', 15, 2)
                ->nullable()
                ->comment('Cierre proyectado que se comunico. NULL cuando no se proyecto: antes del dia minimo, o cuando el gasto es concentrado en una sola transaccion.');

            $table->smallInteger('day_of_month')
                ->comment('Dia del mes, en la zona horaria de referencia, sobre el que se hablo. Se guarda para poder auditar el mensaje sin recalcular el calendario.');

            $table->string('entry_point', 12)
                ->comment('Que voz hablo: sweep (barrido programado) o capture (chequeo al registrar). Ambas comparten reglas; esto solo permite observarlas por separado.');

            $table->timestampTz('spoken_at')
                ->comment('Momento en que se reclamo la observacion. El reclamo ocurre ANTES del envio: el indice unico solo puede arbitrar entre el barrido y la captura si el insert precede al mensaje.');

            $table->timestampTz('sent_at')
                ->nullable()
                ->comment('Momento en que reply() volvio. NO prueba entrega: el canal registra y se traga los no-2xx, asi que esto solo separa "el worker murio entre reclamar y enviar" de "la llamada volvio".');

            $table->timestampsTz();

            // El arbitro real de "nunca la misma banda dos veces en el mes" vive aca,
            // no en la aplicacion. subject_key es NOT NULL a proposito: PostgreSQL
            // trata los NULL como distintos en un indice unico, y un category_id
            // nulo dejaria repetir el aviso de ceguera todos los dias.
            $table->unique(
                ['user_id', 'period_month', 'subject_key', 'band'],
                'unq_coaching_observations_user_period_subject_band'
            );
            $table->index('spoken_at', 'idx_coaching_observations_spoken_at');

            $table->comment('Observaciones de coaching ya dichas. Existe para que el barrido programado y el chequeo al capturar nunca repitan el mismo mensaje, y para que "no decir nada" sea un resultado verificable.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coaching_observations');
    }
};
