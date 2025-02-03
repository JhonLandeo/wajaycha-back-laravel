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
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('extension');
            $table->string('path');
            $table->string('mime');
            $table->string('url');
            $table->bigInteger('size');
            $table->foreignId('user_id')->constrained();
<<<<<<< Updated upstream
=======
            $table->unsignedBigInteger('financial_id')->nullable();
            $table->foreign('financial_id')->references('id')->on('financial_entities');
>>>>>>> Stashed changes
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
