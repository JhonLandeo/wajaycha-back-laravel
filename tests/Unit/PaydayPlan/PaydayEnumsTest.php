<?php

declare(strict_types=1);

use App\Enums\PaydayCycleStatus;
use App\Enums\PaydaySkipReason;

it('names every cycle status with the persisted value', function () {
    expect(array_map(fn (PaydayCycleStatus $s) => $s->value, PaydayCycleStatus::cases()))
        ->toBe(['pending', 'separated', 'skipped', 'deferred', 'expired', 'send_failed']);
});

it('marks only separated, skipped and expired as terminal for answering', function () {
    expect(PaydayCycleStatus::Pending->isOpen())->toBeTrue()
        ->and(PaydayCycleStatus::Deferred->isOpen())->toBeTrue()
        ->and(PaydayCycleStatus::SendFailed->isOpen())->toBeFalse()
        ->and(PaydayCycleStatus::Separated->isOpen())->toBeFalse()
        ->and(PaydayCycleStatus::Skipped->isOpen())->toBeFalse()
        ->and(PaydayCycleStatus::Expired->isOpen())->toBeFalse();
});

it('maps each skip reason to its one-letter callback code and back', function () {
    expect(PaydaySkipReason::fromCode('u'))->toBe(PaydaySkipReason::Unexpected)
        ->and(PaydaySkipReason::fromCode('d'))->toBe(PaydaySkipReason::Debt)
        ->and(PaydaySkipReason::fromCode('s'))->toBe(PaydaySkipReason::Short)
        ->and(PaydaySkipReason::fromCode('o'))->toBe(PaydaySkipReason::Other)
        ->and(PaydaySkipReason::fromCode('x'))->toBeNull()
        ->and(PaydaySkipReason::fromCode(''))->toBeNull();

    foreach (PaydaySkipReason::cases() as $reason) {
        expect(PaydaySkipReason::fromCode($reason->code()))->toBe($reason);
    }
});

it('stores the reason values and labels them without judgement', function () {
    expect(PaydaySkipReason::Debt->value)->toBe('debt')
        ->and(PaydaySkipReason::Unexpected->label())->toBe('Gasto imprevisto')
        ->and(PaydaySkipReason::Debt->label())->toBe('Deuda')
        ->and(PaydaySkipReason::Short->label())->toBe('No me alcanzó')
        ->and(PaydaySkipReason::Other->label())->toBe('Otro');
});
