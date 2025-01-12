<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_services', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Nombre del servicio (Yape, Plin, etc.)
            $table->unsignedBigInteger('operator_id')->nullable(); // Relación con financial_entities
            $table->string('type'); // Tipo: Billetera Digital, Servicio de Pago
            $table->string('website')->nullable(); // Sitio web

            // Clave foránea para operator_id
            $table->foreign('operator_id')->references('id')->on('financial_entities')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_services');
    }
};
