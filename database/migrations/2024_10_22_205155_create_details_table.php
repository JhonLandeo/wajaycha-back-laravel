<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector;');
        Schema::create('details', function (Blueprint $table) {
            $table->id();
            $table->string('description');
            $table->foreignId('user_id')->constrained();
            $table->foreignId('merchant_id')->nullable()->constrained();
            $table->vector('embedding', 768)->nullable();
            $table->unsignedBigInteger('last_used_category_id')->nullable();
            $table->timestamps();

            $table->foreign('last_used_category_id')->references('id')->on('categories');
            $table->unique(['user_id', 'description']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('details');
    }
};
