<?php

declare(strict_types=1);

use App\DTOs\PaydayPlan\PaydayMessage;
use App\DTOs\PaydayPlan\PaydayPlanSnapshot;
use App\Enums\PaydaySkipReason;
use App\Services\PaydayPlan\PaydayPlanComposer;

function paydaySnapshot(string $salary = '3000.00', int $percent = 10, int $day = 3): PaydayPlanSnapshot
{
    return PaydayPlanSnapshot::fromDeclaration($day, $salary, $percent);
}

/** @return array<int, string> */
function paydayCallbacks(PaydayMessage $message): array
{
    $data = [];
    foreach ($message->keyboard as $row) {
        foreach ($row as $button) {
            $data[] = $button['callback_data'];
        }
    }

    return $data;
}

it('computes the savings amount from the declaration in whole cents', function () {
    expect(paydaySnapshot('3000.00', 10)->savingsAmount)->toBe('300.00')
        ->and(paydaySnapshot('2500.50', 15)->savingsAmount)->toBe('375.08')   // 375.075 rounds half up
        ->and(paydaySnapshot('1000.00', 33)->savingsAmount)->toBe('330.00')
        ->and(paydaySnapshot('999.99', 1)->savingsAmount)->toBe('10.00')       // 9.9999 -> 10.00
        ->and(paydaySnapshot('0.01', 50)->savingsAmount)->toBe('0.01')         // 0.005 rounds half up
        ->and(paydaySnapshot('3000', 100)->savingsAmount)->toBe('3000.00');
});

it('round-trips the snapshot through its array form', function () {
    $snapshot = paydaySnapshot('3000.00', 10, 3);

    expect($snapshot->toArray())->toBe([
        'payday_day' => 3,
        'declared_salary' => '3000.00',
        'savings_percent' => 10,
        'savings_amount' => '300.00',
    ])->and(PaydayPlanSnapshot::fromArray($snapshot->toArray())->toArray())->toBe($snapshot->toArray());
});

it('states the exact amount the user committed to', function () {
    $message = (new PaydayPlanComposer)->plan(paydaySnapshot('3000.00', 10), 12);

    expect($message->text)->toContain('ahorro 10% = S/ 300');
});

it('formats cents only when there are any, with thousands separators', function () {
    $composer = new PaydayPlanComposer;

    expect($composer->plan(paydaySnapshot('2500.50', 15), 1)->text)->toContain('ahorro 15% = S/ 375.08')
        ->and($composer->plan(paydaySnapshot('30000.00', 10), 1)->text)->toContain('ahorro 10% = S/ 3,000')
        ->and($composer->plan(paydaySnapshot('30000.00', 10), 1)->text)->toContain('S/ 30,000');
});

it('never claims that the salary arrived', function () {
    $texts = [
        (new PaydayPlanComposer)->plan(paydaySnapshot(), 1)->text,
        (new PaydayPlanComposer)->reminder(paydaySnapshot(), 1)->text,
    ];

    foreach ($texts as $text) {
        expect(mb_strtolower($text))
            ->not->toContain('ya cobraste')
            ->not->toContain('te pagaron')
            ->not->toContain('llegó tu sueldo')
            ->not->toContain('llego tu sueldo')
            ->not->toContain('depositaron');
    }
});

it('offers the three answers with callbacks that carry the cycle id', function () {
    $message = (new PaydayPlanComposer)->plan(paydaySnapshot(), 12);

    expect(paydayCallbacks($message))->toBe(['p:12:y', 'p:12:n', 'p:12:w'])
        ->and(array_map(fn (array $row) => $row[0]['text'], $message->keyboard))
        ->toBe(['Ya separé', 'Este mes no', 'Todavía no cobré']);
});

it('keeps every callback under the 64 byte Telegram ceiling even for huge ids', function () {
    $composer = new PaydayPlanComposer;
    $id = 999_999_999_999_999_999;

    $all = [
        ...paydayCallbacks($composer->plan(paydaySnapshot(), $id)),
        ...paydayCallbacks($composer->reminder(paydaySnapshot(), $id)),
        ...paydayCallbacks($composer->skipReasonPrompt($id)),
        ...paydayCallbacks($composer->deferred()),
    ];

    foreach ($all as $data) {
        expect(strlen($data))->toBeLessThanOrEqual(64);
    }
});

it('asks for the skip reason with the four neutral options', function () {
    $message = (new PaydayPlanComposer)->skipReasonPrompt(12);

    expect(paydayCallbacks($message))->toBe(['p:12:r:u', 'p:12:r:d', 'p:12:r:s', 'p:12:r:o'])
        ->and(array_map(fn (array $row) => $row[0]['text'], $message->keyboard))
        ->toBe(['Gasto imprevisto', 'Deuda', 'No me alcanzó', 'Otro']);
});

it('offers Cobré hoy after a deferral and promises silence', function () {
    $message = (new PaydayPlanComposer)->deferred();

    expect(paydayCallbacks($message))->toBe(['p:paid'])
        ->and($message->keyboard[0][0]['text'])->toBe('Cobré hoy')
        ->and(mb_strtolower($message->text))->toContain('no te escribo');
});

it('restates stored values in the reminder instead of advising', function () {
    $message = (new PaydayPlanComposer)->reminder(paydaySnapshot('3000.00', 10), 7);

    expect($message->text)->toContain('ahorro 10% = S/ 300')
        ->and(paydayCallbacks($message))->toBe(['p:7:y', 'p:7:n', 'p:7:w']);
});

it('uses no advice vocabulary in any message', function () {
    $composer = new PaydayPlanComposer;
    $texts = [
        $composer->plan(paydaySnapshot(), 1)->text,
        $composer->reminder(paydaySnapshot(), 1)->text,
        $composer->skipReasonPrompt(1)->text,
        $composer->deferred()->text,
        $composer->separated(),
        $composer->skipped(PaydaySkipReason::Debt),
        $composer->stale(),
        $composer->alreadyAnswered(),
        $composer->noPlan(),
        $composer->nothingToResume(),
        $composer->notLinked(),
        $composer->expired(),
    ];
    $advice = ['deberías', 'debes', 'recomend', 'sugier', 'conviene', 'te aconsej', 'invierte', 'tendrías', 'mejor que', 'lástima', 'lastima', 'fallaste', 'culpa'];

    foreach ($texts as $text) {
        foreach ($advice as $word) {
            expect(mb_strtolower($text))->not->toContain($word);
        }
    }
});

it('answers the polite edge cases without leaking anything', function () {
    $composer = new PaydayPlanComposer;

    expect($composer->stale())->toBe('Ese botón ya no aplica.')
        ->and($composer->noPlan())->toContain('web')
        ->and($composer->skipped(PaydaySkipReason::Debt))->toContain('Deuda');
});

it('tells politely that an expired plan is no longer available', function () {
    expect((new PaydayPlanComposer)->expired())->toContain('ya no está disponible')
        ->and((new PaydayPlanComposer)->expired())->toContain('día de cobro');
});
