<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Planificación semanal: configuración del hogar y una entrada por fecha y
     * comida.
     *
     * La entrada guarda porciones comidas y porciones cocinadas por separado:
     * cocinar 4 para 2 personas deja 2 porciones para una entrada de sobras, y
     * los ingredientes se cuentan una sola vez. `leftover_of_entry_id` enlaza
     * esas sobras con la comida que las produjo.
     */
    public function up(): void
    {
        Schema::create('meal_plan_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Dueño de la configuración. Límite de autorización.');

            $table->unsignedTinyInteger('household_size')
                ->default(2)
                ->comment('Personas para las que se cocina por defecto.');

            $table->jsonb('meal_types')
                ->default(DB::raw("'[\"breakfast\",\"lunch\",\"dinner\"]'::jsonb"))
                ->comment('Comidas que el usuario planifica: breakfast, lunch y dinner.');

            $table->jsonb('plan_weekdays')
                ->default(DB::raw("'[1,2,3,4,5,6,7]'::jsonb"))
                ->comment('Días ISO que el usuario planifica: 1=lunes ... 7=domingo.');

            $table->unsignedSmallInteger('max_prep_minutes')
                ->nullable()
                ->comment('Tiempo máximo de preparación tolerado, opcional.');

            $table->timestampsTz();

            $table->unique('user_id', 'unq_meal_plan_settings_user_id');
            $table->comment('Configuración del menú semanal por usuario.');
        });

        Schema::create('meal_plan_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Dueño de la entrada. Límite de autorización.');

            $table->date('plan_date')
                ->comment('Día del menú, en America/Lima.');

            $table->text('meal_type')
                ->comment('breakfast | lunch | dinner. Se valida en el FormRequest.');

            $table->text('kind')
                ->comment('recipe | leftover | eat_out | free.');

            $table->foreignId('recipe_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete()
                ->comment('Receta planificada. NULL en comer fuera o comida libre.');

            $table->unsignedSmallInteger('servings')
                ->default(0)
                ->comment('Porciones que se comen en esta entrada.');

            $table->unsignedSmallInteger('cook_servings')
                ->default(0)
                ->comment('Porciones que se cocinan. Para una receta normal coincide con servings; si es mayor deja sobras.');

            $table->foreignId('leftover_of_entry_id')
                ->nullable()
                ->constrained('meal_plan_entries')
                ->cascadeOnDelete()
                ->comment('Entrada que produjo estas sobras. Solo para kind=leftover.');

            $table->text('notes')
                ->nullable();

            $table->timestampsTz();

            $table->unique(['user_id', 'plan_date', 'meal_type'], 'unq_meal_plan_entries_user_date_meal');
            $table->index(['user_id', 'plan_date'], 'idx_meal_plan_entries_user_date');
            $table->comment('Entradas del menú semanal, una por fecha y comida.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plan_entries');
        Schema::dropIfExists('meal_plan_settings');
    }
};
