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
        Schema::create('transaction_yapes', function (Blueprint $table) {
            $table->id();
            $table->string('message')->nullable();
            $table->string('origin');
            $table->string('destination');
            $table->decimal('amount');
            $table->timestamp('date_operation');
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('category_id')->nullable()->after('user_id');
            $table->foreign('category_id')->references('id')->on('categories');
            $table->unsignedBigInteger('detail_id')->nullable()->after('category_id');
            $table->foreign('detail_id')->references('id')->on('details');
            $table->enum('type_transaction', ['income', 'expense']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_yapes');
    }
};
