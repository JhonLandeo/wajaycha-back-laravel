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
        $this->down();
        Schema::create('transaction_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('transaction_yape_id')->nullable();
            $table->unsignedBigInteger('tag_id');
            $table->foreign('transaction_id')->references('id')->on('transactions');
            $table->foreign('transaction_yape_id')->references('id')->on('transaction_yapes');
            $table->foreign('tag_id')->references('id')->on('tags');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_tag');
    }
};
