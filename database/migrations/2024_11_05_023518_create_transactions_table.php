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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('message')->nullable();
            $table->foreignId('detail_id')->constrained();
            $table->decimal('amount', 10, 2);
            $table->timestamp('date_operation');
            $table->enum('type_transaction', ['income', 'expense']);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->foreign('category_id')->references('id')->on('categories');
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('yape_id')->nullable()->after('user_id');
            $table->foreign('yape_id')->references('id')->on('transaction_yapes');
            $table->boolean('is_subscription')->default(false)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
