<?php

declare(strict_types=1);

use App\Enums\PaydayAnswer;
use App\Enums\PaydaySkipReason;
use App\Services\PaydayPlan\PaydayCallbackParser;

it('parses the three answers with their cycle id', function () {
    $parser = new PaydayCallbackParser;

    $yes = $parser->parse('p:12:y');
    $no = $parser->parse('p:12:n');
    $wait = $parser->parse('p:7:w');

    expect($yes->cycleId)->toBe(12)->and($yes->answer)->toBe(PaydayAnswer::Separated)
        ->and($no->answer)->toBe(PaydayAnswer::Skip)->and($no->cycleId)->toBe(12)
        ->and($wait->answer)->toBe(PaydayAnswer::Wait)->and($wait->cycleId)->toBe(7)
        ->and($yes->isPaid())->toBeFalse()->and($yes->reason)->toBeNull();
});

it('parses each skip reason', function () {
    $parser = new PaydayCallbackParser;

    foreach (['u' => PaydaySkipReason::Unexpected, 'd' => PaydaySkipReason::Debt, 's' => PaydaySkipReason::Short, 'o' => PaydaySkipReason::Other] as $code => $reason) {
        $callback = $parser->parse("p:30:r:{$code}");

        expect($callback->cycleId)->toBe(30)->and($callback->reason)->toBe($reason)->and($callback->answer)->toBeNull();
    }
});

it('parses the cycle-less Cobré hoy callback', function () {
    $callback = (new PaydayCallbackParser)->parse('p:paid');

    expect($callback->isPaid())->toBeTrue()->and($callback->cycleId)->toBeNull()->and($callback->answer)->toBeNull();
});

it('rejects anything malformed with null instead of throwing', function (?string $data) {
    expect((new PaydayCallbackParser)->parse($data))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'other prefix' => ['m:how'],
    'bare prefix' => ['p:'],
    'no action' => ['p:12'],
    'unknown action' => ['p:12:x'],
    'unknown reason' => ['p:12:r:z'],
    'missing reason' => ['p:12:r:'],
    'non numeric id' => ['p:abc:y'],
    'negative id' => ['p:-1:y'],
    'zero id' => ['p:0:y'],
    'decimal id' => ['p:1.5:y'],
    'trailing junk' => ['p:12:y:extra'],
    'leading space' => [' p:12:y'],
    'uppercase action' => ['p:12:Y'],
    'paid with id' => ['p:12:paid'],
    'paid with suffix' => ['p:paid:1'],
    'overflowing id' => ['p:99999999999999999999:y'],
    'newline injection' => ["p:12:y\n"],
]);
