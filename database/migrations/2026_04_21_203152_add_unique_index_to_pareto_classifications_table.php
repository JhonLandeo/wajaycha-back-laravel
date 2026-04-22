<?php

declare(strict_types=1);

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
        Schema::table('pareto_classifications', function (Blueprint $table) {
            $table->unique(['user_id', 'name'], 'unq_pareto_classifications_user_id_name');
            $table->comment('Unique classification name per user to avoid duplication');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pareto_classifications', function (Blueprint $table) {
            $table->dropUnique('unq_pareto_classifications_user_id_name');
        });
    }
};
