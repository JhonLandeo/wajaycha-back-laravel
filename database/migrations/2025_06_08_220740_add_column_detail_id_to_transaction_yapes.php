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
        Schema::table('transaction_yapes', function (Blueprint $table) {
            $table->unsignedBigInteger('detail_id')->nullable()->after('sub_category_id');
            $table->foreign('detail_id')->references('id')->on('details');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaction_yapes', function (Blueprint $table) {
            $table->dropForeign('transaction_yapes_detail_id_foreign');
            $table->dropColumn('detail_id');
        });
    }
};
