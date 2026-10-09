<?php

declare(strict_types=1);

use App\Console\PriceSchedule;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Where and when the price commands run (design D10, D12). Times are Lima
 * (the application timezone); every entry reports to a Sentry cron monitor.
 */
function priceScheduleEvents(): array
{
    // Boots the console kernel, which loads routes/console.php.
    Artisan::call('list');

    $events = [];
    foreach (app(Schedule::class)->events() as $event) {
        /** @var Event $event */
        if (str_contains((string) $event->command, 'prices:')) {
            $events[trim((string) preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command))] = $event;
        }
    }

    return $events;
}

it('schedules every ingest and its canary at the planned Lima times', function () {
    $events = priceScheduleEvents();

    $expected = [
        'prices:ingest plazavea' => '0 5 * * 1',
        'prices:canary plazavea' => '0 6 * * 1',
        'prices:ingest inei' => '0 7 * * 1',
        'prices:canary inei' => '0 8 * * 1',
        'prices:ingest emmsa' => '0 10 * * *',
        'prices:canary emmsa' => '0 11 * * *',
        'prices:ingest gmml' => '30 11 * * *',
        'prices:canary gmml' => '30 12 * * *',
    ];

    expect(array_keys($events))->toEqualCanonicalizing(array_keys($expected));

    foreach ($expected as $command => $cron) {
        expect($events[$command]->expression)->toBe($cron, "cron de {$command}")
            ->and($events[$command]->timezone ?? config('app.timezone'))->toBe('America/Lima');
    }
});

it('puts a Sentry cron monitor on every price entry, with its own slug', function () {
    $entries = PriceSchedule::entries();

    expect(array_column($entries, 'monitor'))->toEqualCanonicalizing([
        'prices-plazavea', 'prices-canary-plazavea',
        'prices-inei', 'prices-canary-inei',
        'prices-emmsa', 'prices-canary-emmsa',
        'prices-gmml', 'prices-canary-gmml',
    ]);

    // The Sentry macro hooks a check-in before the command and one after it.
    $callbacks = fn (Event $event, string $property): int => count((new ReflectionProperty(Event::class, $property))->getValue($event));

    foreach (priceScheduleEvents() as $command => $event) {
        expect($callbacks($event, 'beforeCallbacks'))->toBeGreaterThanOrEqual(1, "{$command} sin check-in inicial")
            ->and($callbacks($event, 'afterCallbacks'))->toBeGreaterThanOrEqual(2, "{$command} sin check-in final");
    }
});

it('runs each canary after the ingest it watches', function () {
    $entries = collect(PriceSchedule::entries())->keyBy('command');

    foreach (['plazavea', 'inei', 'emmsa', 'gmml'] as $source) {
        $ingest = $entries["prices:ingest {$source}"]['cron'];
        $canary = $entries["prices:canary {$source}"]['cron'];

        [$im, $ih] = explode(' ', $ingest);
        [$cm, $ch] = explode(' ', $canary);

        expect(((int) $ch * 60 + (int) $cm) - ((int) $ih * 60 + (int) $im))->toBeGreaterThanOrEqual(30);
    }
});
