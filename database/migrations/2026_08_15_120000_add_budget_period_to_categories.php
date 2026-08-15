<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Declares the natural unit of a category's budget, so the coach stops
     * inferring it from the shape of one month's spending.
     *
     * Until now every budget was monthly by assumption, and `PaceEvaluator`
     * compensated with `isLumpy` — a per-month heuristic that asks "did one
     * purchase dominate?". That question detects a SHAPE after the fact; it can
     * never recover the FACT that a category was budgeted for the year. A single
     * yearly expense (a medical check-up, an insurance premium, a vehicle
     * service) therefore triggered "ya pasaste el presupuesto" against a monthly
     * figure that was never the right denominator.
     *
     * Purely additive. Existing rows take 'monthly', which is exactly what they
     * meant before this column existed, so no behaviour changes for them.
     *
     * NAMING DEBT, deliberate: `monthly_budget` keeps holding the amount, and
     * this column reinterprets its unit — a row with budget_period='yearly' and
     * monthly_budget=1200 means "S/ 1200 per year". Renaming the column would
     * force edits to `get_monthly_category_budget_report` and the reporting SQL
     * functions, which the repository rules forbid extending. The mismatch is
     * recorded here rather than hidden.
     *
     * No CHECK constraint, matching how `categories.type` is already handled:
     * the allowed values are enforced by FormRequest validation. Adding one here
     * and not there would invent a convention that half the table follows.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('budget_period', 10)
                ->default('monthly')
                ->after('monthly_budget')
                ->comment('Unidad natural del presupuesto: monthly (se gasta continuo, el mes es la unidad y proyectar tiene sentido) o yearly (es un sobre anual que se consume a saltos, y proyectarlo linealmente no significa nada). Reinterpreta monthly_budget: con yearly, ese monto es anual.');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('budget_period');
        });
    }
};
