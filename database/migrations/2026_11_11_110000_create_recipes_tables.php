<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recetas globales (user_id NULL) y privadas (user_id con valor), con sus
     * ingredientes ligados al catálogo de productos.
     *
     * `meal_types` es JSONB y no una tabla pivote: una receta puede servir para
     * desayuno y cena, y el conjunto es pequeño. La validación vive en el
     * FormRequest; acá se guarda el arreglo tal como lo declaró el usuario.
     */
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete()
                ->comment('NULL para el catálogo global sembrado; con valor, una receta privada del usuario.');

            $table->text('slug')
                ->nullable()
                ->comment('Identificador estable solo para recetas sembradas. NULL en las recetas del usuario.');

            $table->text('name')
                ->comment('Nombre de la receta en español, por ejemplo "Arroz con pollo".');

            $table->unsignedSmallInteger('base_servings')
                ->default(2)
                ->comment('Porciones que rinde la receta tal como está escrita. El menú escala desde acá.');

            $table->jsonb('meal_types')
                ->comment('Comidas compatibles: breakfast, lunch y/o dinner. Se valida en el FormRequest.');

            $table->unsignedSmallInteger('prep_minutes')
                ->nullable()
                ->comment('Tiempo estimado de preparación en minutos. NULL si no se declaró.');

            $table->text('instructions')
                ->nullable()
                ->comment('Pasos de preparación. Opcional: el menú y la compra no dependen de esto.');

            $table->boolean('is_active')
                ->default(true)
                ->comment('Permite retirar una receta del catálogo sin borrar sus ingredientes referenciados.');

            $table->timestampsTz();

            $table->unique('slug', 'unq_recipes_slug');
            $table->unique(['user_id', 'name'], 'unq_recipes_user_id_name');
            $table->comment('Recetario: catálogo global sembrado más las recetas privadas de cada usuario.');
        });

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('recipe_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Receta a la que pertenece el ingrediente.');

            $table->foreignId('product_id')
                ->constrained()
                ->restrictOnDelete()
                ->comment('Producto del catálogo. RESTRICT: un producto usado en una receta no se borra.');

            $table->decimal('quantity', 12, 3)
                ->comment('Cantidad del ingrediente en la unidad declarada, no en la unidad canónica del producto.');

            $table->text('unit')
                ->comment('Unidad declarada por la receta. Debe ser compatible con la unidad del producto.');

            $table->boolean('is_optional')
                ->default(false)
                ->comment('Un ingrediente opcional no debería impedir cocinar la receta.');

            $table->timestampsTz();

            $table->unique(['recipe_id', 'product_id'], 'unq_recipe_ingredients_recipe_product');
            $table->comment('Ingredientes de una receta, enlazados al catálogo de productos.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};
