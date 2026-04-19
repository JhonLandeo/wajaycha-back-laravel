<?php

use App\Models\Category;
use App\Models\ParetoClassification;
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
        // 0. Make pareto_classification_id nullable in categories table
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('pareto_classification_id')->nullable()->change();
        });

        DB::transaction(function () {
            // 1. Ensure all 'income' categories have no Pareto classification
            Category::where('type', 'income')
                ->update(['pareto_classification_id' => null]);

            // 2. Define allowed classifications
            $allowedNames = ['Fijos', 'Variables', 'Ahorro'];

            // 3. Cleanup extra classifications for each user
            $extraClassifications = ParetoClassification::whereNotIn('name', $allowedNames)->get();

            foreach ($extraClassifications as $extra) {
                $fijosId = ParetoClassification::where('user_id', $extra->user_id)
                    ->where('name', 'Fijos')
                    ->value('id');

                $variablesId = ParetoClassification::where('user_id', $extra->user_id)
                    ->where('name', 'Variables')
                    ->value('id');

                // Reassign 'Deuda' to 'Fijos' if possible
                if ($extra->name === 'Deuda' && $fijosId) {
                    Category::where('pareto_classification_id', $extra->id)
                        ->update(['pareto_classification_id' => $fijosId]);
                }
                // Reassign others or nullify
                else {
                    Category::where('pareto_classification_id', $extra->id)
                        ->update(['pareto_classification_id' => null]);
                }

                $extra->delete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No rollback provided for structural cleanup.
    }
};
