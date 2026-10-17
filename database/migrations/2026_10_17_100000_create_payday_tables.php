<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payday Plan: the commitment a user declared (`payday_plans`) and one row per
 * month the system acted on it (`payday_cycles`).
 *
 * `status` and `skip_reason` carry no CHECK — the rule lives in the
 * `PaydayCycleStatus` / `PaydaySkipReason` enums, as with
 * `grocery_budget_links.resolved_by`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payday_plans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Dueno del plan. Limite de autorizacion: una fila por usuario.');

            $table->smallInteger('payday_day')
                ->comment('Dia del mes (1-28) en que el usuario declara cobrar. Tope 28: todos los meses tienen esa fecha.');

            $table->decimal('declared_salary', 15, 2)
                ->comment('Sueldo que el usuario declaro. Es una declaracion, no un dato verificado.');

            $table->smallInteger('savings_percent')
                ->comment('Porcentaje (1-100) del sueldo declarado que el usuario se comprometio a separar.');

            $table->boolean('is_active')->default(true)
                ->comment('Un plan inactivo nunca se envia ni se reclama.');

            $table->timestampsTz();

            $table->unique('user_id', 'unq_payday_plans_user_id');

            $table->comment('Compromiso de ahorro declarado por el usuario el dia de cobro. Una fila por usuario.');
        });

        Schema::create('payday_cycles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Dueno del ciclo. Limite de autorizacion de toda respuesta del bot.');

            $table->date('period_month')
                ->comment('Primer dia del mes (America/Lima) al que pertenece el ciclo.');

            $table->date('payday_on')
                ->comment('Fecha de cobro de ese mes segun el plan vigente al reclamar el ciclo.');

            $table->jsonb('plan_snapshot')
                ->comment('payday_day, declared_salary, savings_percent y savings_amount al reclamar. Todo mensaje, reintento y reenvio del ciclo usa esto, no el plan vivo.');

            $table->text('status')->default('pending')
                ->comment('pending | separated | skipped | deferred | expired | send_failed. Sin CHECK: la regla vive en PaydayCycleStatus.');

            $table->text('skip_reason')->nullable()
                ->comment('unexpected | debt | short | other. Solo cuando status = skipped.');

            $table->smallInteger('send_attempts')->default(0)
                ->comment('Intentos de enviar el mensaje del plan, contando los reintentos del dia siguiente.');

            $table->timestampTz('sent_at')->nullable()
                ->comment('Ultima entrega aceptada por Telegram. NULL con status pending = reclamado pero nunca enviado.');

            $table->timestampTz('answered_at')->nullable()
                ->comment('Momento de la ultima respuesta del usuario.');

            $table->timestampTz('reminded_at')->nullable()
                ->comment('Cuando se mando el unico recordatorio. Se intenta una sola vez, nunca se reintenta.');

            $table->date('reminder_due_on')->nullable()
                ->comment('Dia (America/Lima) en que corresponde el recordatorio: el dia siguiente a la ultima entrega.');

            $table->timestampsTz();

            $table->unique(['user_id', 'period_month'], 'unq_payday_cycles_user_id_period_month');

            $table->comment('Un ciclo por usuario y mes: lo que el sistema envio y lo que el usuario respondio.');
        });

        DB::statement(<<<'SQL'
            CREATE INDEX idx_payday_cycles_reminder_due_on
              ON payday_cycles (reminder_due_on)
              WHERE status = 'pending'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payday_cycles');
        Schema::dropIfExists('payday_plans');
    }
};
