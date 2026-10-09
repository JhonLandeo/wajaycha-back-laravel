<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Precios observados por fuente, globales (sin user_id): no son datos de
     * nadie, son datos del mercado. Spec "Price observation storage".
     *
     * NO existe una columna `store`: la clave `source` ES la identidad de la
     * tienda (`plazavea`, un futuro `metro`). Una columna aparte solo
     * permitiria que las dos discrepen.
     *
     * Solo se guarda precio, precio de lista, presentacion, fecha y fuente.
     * Nada de fotos, descripciones, marcas ni logos: eso si esta protegido
     * por derecho de autor y por los Terminos de la tienda (ADR-0010).
     */
    public function up(): void
    {
        Schema::create('price_observations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Producto del catalogo al que corresponde el precio. CASCADE: sin producto no hay nada que cotizar.');

            $table->text('source')
                ->comment('Clave de la fuente que observo el precio: plazavea, inei, emmsa o gmml. Identifica tambien a la tienda.');

            $table->text('price_kind')
                ->comment('retail o wholesale. Un precio mayorista nunca valoriza una linea; solo alimenta la tendencia.');

            $table->text('unit')
                ->comment('Unidad canonica del producto (App\\Enums\\Unit) al momento de ingestar; unit_price viene POR esa unidad. Una lectura ignora filas cuya unidad no coincide con la de la linea.');

            // numeric(12,4) y no (15,2): la normalizacion divide (3.90 / 0.9 kg)
            // y el redondeo a dos decimales ocurre solo al mostrar. Sigue siendo
            // numeric, jamas float.
            $table->decimal('unit_price', 12, 4)
                ->comment('Precio por la unidad de la columna unit, en soles. Cuatro decimales para no perder precision al dividir; se redondea a 2 solo en la salida.');

            $table->decimal('reference_price', 12, 4)
                ->nullable()
                ->comment('Precio de lista (sin oferta) por la misma unidad, cuando la fuente lo informa.');

            $table->decimal('price_min', 12, 4)
                ->nullable()
                ->comment('Precio minimo del periodo por la misma unidad, cuando la fuente lo informa.');

            $table->decimal('price_max', 12, 4)
                ->nullable()
                ->comment('Precio maximo del periodo por la misma unidad, cuando la fuente lo informa.');

            $table->smallInteger('sample_size')
                ->default(1)
                ->comment('Cuantos candidatos aceptados produjeron unit_price (la mediana en Plaza Vea).');

            $table->text('basis')
                ->comment('measured si el precio sale de una medida directa; equivalence si hizo falta una equivalencia curada (por ejemplo 1 palta = 0.2 kg).');

            $table->date('period_start')
                ->comment('Inicio del periodo que cubre el precio. Plaza Vea: el dia de la observacion; INEI: primer dia del mes.');

            $table->date('period_end')
                ->comment('Fin del periodo. Es la fecha desde la que se mide la antiguedad (fresca, vieja, vencida).');

            $table->timestampTz('observed_at')
                ->comment('Instante en que el sistema observo el precio.');

            $table->text('source_ref')
                ->nullable()
                ->comment('Referencia no sensible de origen: SKU de la tienda o id de la edicion INEI. Sirve para saber si una edicion ya se ingesto.');

            $table->boolean('is_quarantined')
                ->default(false)
                ->comment('INEI: el salto mensual supero el limite configurado. La fila queda guardada para auditoria pero la lectura la excluye.');

            $table->timestampsTz();

            // Arbitro de la idempotencia: re-ingestar la misma tupla actualiza,
            // no duplica.
            $table->unique(['product_id', 'source', 'period_start'], 'unq_price_observations_product_id_source_period_start');

            // Lectura por fuente y antiguedad (period_end >= corte).
            $table->index(['source', 'period_end'], 'idx_price_observations_source_period_end');

            $table->comment('Precios observados por fuente y producto, globales. Sin fotos, descripciones ni marcas (ADR-0010).');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_observations');
    }
};
