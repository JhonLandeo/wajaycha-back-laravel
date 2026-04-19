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
        DB::transaction(function () {
            // 1. For each user, move categories and delete 'Variables No Esenciales'
            $users = \App\Models\User::all();

            foreach ($users as $user) {
                $variablesId = ParetoClassification::where('user_id', $user->id)
                    ->where('name', 'Variables Esenciales')
                    ->value('id');

                $noEssentialId = ParetoClassification::where('user_id', $user->id)
                    ->where('name', 'Variables No Esenciales')
                    ->value('id');

                if ($variablesId) {
                    // Rename 'Variables Esenciales' to 'Variables' and update percentage to 45% (15+30)
                    ParetoClassification::where('id', $variablesId)
                        ->update([
                            'name' => 'Variables',
                            'percentage' => 45
                        ]);

                    if ($noEssentialId) {
                        Category::where('user_id', $user->id)
                            ->where('pareto_classification_id', $noEssentialId)
                            ->update(['pareto_classification_id' => $variablesId]);

                        ParetoClassification::where('id', $noEssentialId)->delete();
                    }
                } else if ($noEssentialId) {
                    // If for some reason they only have 'Variables No Esenciales', rename it to 'Variables'
                    ParetoClassification::where('id', $noEssentialId)
                        ->update([
                            'name' => 'Variables',
                            'percentage' => 45
                        ]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No easy way to reverse this merge without backups of original assignments.
    }
};
