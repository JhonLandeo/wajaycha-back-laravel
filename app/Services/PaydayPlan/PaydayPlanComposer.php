<?php

declare(strict_types=1);

namespace App\Services\PaydayPlan;

use App\DTOs\PaydayPlan\PaydayCallback;
use App\DTOs\PaydayPlan\PaydayMessage;
use App\DTOs\PaydayPlan\PaydayPlanSnapshot;
use App\Enums\PaydaySkipReason;

/**
 * Deterministic Spanish templates for the payday plan (ADR-0011).
 *
 * Every figure is the user's own declaration restated from the snapshot. The
 * wording never claims the salary arrived (nothing verifies it) and never
 * advises: it asks and records.
 */
final class PaydayPlanComposer
{
    public function plan(PaydayPlanSnapshot $plan, int $cycleId): PaydayMessage
    {
        return new PaydayMessage(
            "Según el plan que definiste, tu día de cobro es el {$plan->paydayDay}.\n"
            .$this->commitment($plan)."\n\n"
            .'¿Cómo va este mes?',
            $this->answerKeyboard($cycleId),
        );
    }

    public function reminder(PaydayPlanSnapshot $plan, int $cycleId): PaydayMessage
    {
        return new PaydayMessage(
            "Un recordatorio de tu plan:\n"
            .$this->commitment($plan)."\n\n"
            .'¿Ya lo separaste?',
            $this->answerKeyboard($cycleId),
        );
    }

    public function skipReasonPrompt(int $cycleId): PaydayMessage
    {
        $keyboard = array_map(
            fn (PaydaySkipReason $reason): array => [[
                'text' => $reason->label(),
                'callback_data' => "p:{$cycleId}:r:{$reason->code()}",
            ]],
            PaydaySkipReason::cases(),
        );

        return new PaydayMessage('Anotado. ¿Cuál fue el motivo?', $keyboard);
    }

    public function deferred(): PaydayMessage
    {
        return new PaydayMessage(
            'Está bien. No te escribo hasta que me avises. Cuando cobres, toca el botón.',
            [[['text' => 'Cobré hoy', 'callback_data' => PaydayCallback::PAID]]],
        );
    }

    public function separated(): string
    {
        return 'Anotado: separaste tu ahorro de este mes.';
    }

    public function skipped(PaydaySkipReason $reason): string
    {
        return "Anotado: este mes no ahorras. Motivo: {$reason->label()}.";
    }

    public function stale(): string
    {
        return 'Ese botón ya no aplica.';
    }

    public function alreadyAnswered(): string
    {
        return 'Ya registraste tu respuesta de este mes.';
    }

    public function noPlan(): string
    {
        return 'Todavía no tienes un plan de cobro activo. Puedes definirlo en la web.';
    }

    public function nothingToResume(): string
    {
        return 'No hay nada pendiente de este mes para retomar.';
    }

    public function notLinked(): string
    {
        return '❌ Tu cuenta de Telegram no está vinculada. Abre la app y genera tu enlace de vinculación.';
    }

    private function commitment(PaydayPlanSnapshot $plan): string
    {
        return "Tu compromiso: ahorro {$plan->savingsPercent}% = S/ {$this->format($plan->savingsAmount)} "
            ."(de tu sueldo declarado de S/ {$this->format($plan->declaredSalary)}).";
    }

    /** Whole soles read without decimals; anything else keeps its two. */
    private function format(string $amount): string
    {
        [$whole, $cents] = explode('.', $amount);
        $grouped = number_format((int) $whole, 0, '.', ',');

        return $cents === '00' ? $grouped : "{$grouped}.{$cents}";
    }

    /** @return array<int, array<int, array<string, string>>> */
    private function answerKeyboard(int $cycleId): array
    {
        return [
            [['text' => 'Ya separé', 'callback_data' => "p:{$cycleId}:y"]],
            [['text' => 'Este mes no', 'callback_data' => "p:{$cycleId}:n"]],
            [['text' => 'Todavía no cobré', 'callback_data' => "p:{$cycleId}:w"]],
        ];
    }
}
