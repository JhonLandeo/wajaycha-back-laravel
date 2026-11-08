<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ingreso planificado: una fuente (sueldo, negocio, otro) y sus versiones de
 * monto con vigencia mensual.
 *
 * La version no guarda el mes como numero: guarda un rango de fechas. El cliente
 * manda meses ("2026-11") y la accion los normaliza al primer/ultimo dia. Asi una
 * fuente puede cambiar de monto sin reescribir los meses que ya pasaron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_sources', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Dueno de la fuente. Limite de autorizacion: todas las consultas filtran por el.');

            $table->string('name', 120)
                ->comment('Nombre que el usuario le da a la fuente: Sueldo, Negocio, Freelance.');

            $table->string('kind', 20)
                ->comment('salary | business | other. Sin CHECK: la regla vive en IncomeSourceKind.');

            $table->timestampsTz();

            $table->unique(['user_id', 'name'], 'unq_income_sources_user_id_name');

            $table->comment('Fuentes de ingreso recurrente del usuario. Cada una tiene una o mas versiones de monto.');
        });

        Schema::create('income_source_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('income_source_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Fuente a la que pertenece esta version de monto.');

            $table->decimal('amount', 15, 2)
                ->comment('Monto mensual esperado de la fuente en esta version.');

            $table->date('effective_from')
                ->comment('Primer dia del mes desde el que rige esta version.');

            $table->date('effective_to')
                ->nullable()
                ->comment('Ultimo dia del mes en que rige. NULL = version abierta.');

            $table->timestampsTz();

            $table->index(['income_source_id', 'effective_from'], 'idx_income_source_versions_source_from');

            $table->comment('Versiones de monto de una fuente de ingreso, con vigencia mensual.');
        });

        DB::statement(
            'ALTER TABLE income_source_versions ADD CONSTRAINT chk_income_source_versions_amount CHECK (amount >= 0)'
        );

        DB::statement(
            'ALTER TABLE income_source_versions ADD CONSTRAINT chk_income_source_versions_dates CHECK (effective_to IS NULL OR effective_to >= effective_from)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('income_source_versions');
        Schema::dropIfExists('income_sources');
    }
};
