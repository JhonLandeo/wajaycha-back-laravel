<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitacora de ingestas: UNA fila por (fuente, unidad de trabajo). Para Plaza
     * Vea la unidad es un producto (unas 40 filas por corrida semanal); para
     * EMMSA, GMML e INEI es una fila por corrida (`all` o el id de la edicion).
     *
     * El agregado de una corrida ("38 ok, 2 fallidas") se DERIVA consultando
     * filas, no se guarda: asi no hay contadores que se pisen entre jobs
     * paralelos y un fallo se diagnostica por producto.
     *
     * NUNCA guarda precios de terceros: la bitacora se conserva tras un purge
     * y por eso no puede contener lo que el purge borra.
     */
    public function up(): void
    {
        Schema::create('price_ingestion_runs', function (Blueprint $table) {
            $table->id();

            $table->text('source')
                ->comment('Clave de la fuente: plazavea, inei, emmsa o gmml.');

            $table->text('unit_key')
                ->comment('Unidad de trabajo: el slug del producto (Plaza Vea), la edicion (INEI) o `all`.');

            $table->text('status')
                ->comment('running, success, partial, failed, skipped o purged.');

            $table->timestampTz('started_at')
                ->comment('Cuando el job tomo la unidad de trabajo.');

            $table->timestampTz('finished_at')
                ->nullable()
                ->comment('Cuando termino. NULL mientras el estado es running.');

            $table->integer('rows_written')
                ->default(0)
                ->comment('Observaciones escritas por esta unidad de trabajo.');

            $table->integer('rows_rejected')
                ->default(0)
                ->comment('Candidatos o filas descartados (parseo, filtros o cuarentena).');

            $table->text('error')
                ->nullable()
                ->comment('Mensaje del fallo cuando el estado es failed.');

            $table->jsonb('details')
                ->nullable()
                ->comment('Detalle no sensible: motivo, conteos de rechazo, id de edicion, filas borradas. Nunca precios.');

            $table->timestampsTz();

            $table->index(['source', 'started_at'], 'idx_price_ingestion_runs_source_started_at');

            $table->comment('Una fila por fuente y unidad de trabajo de cada ingesta de precios. No contiene precios de terceros.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_ingestion_runs');
    }
};
