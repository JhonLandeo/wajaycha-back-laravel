<?php

declare(strict_types=1);

use App\Console\PaydaySchedule;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function paydayScheduleEvents(): array
{
    Artisan::call('list');

    $events = [];
    foreach (app(Schedule::class)->events() as $event) {
        /** @var Event $event */
        if (str_contains((string) $event->command, 'payday')) {
            $events[trim((string) preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command))] = $event;
        }
    }

    return $events;
}

it('runs the plan at 09:00 and the reminder at 20:00 Lima', function () {
    $events = paydayScheduleEvents();

    expect(array_keys($events))->toEqualCanonicalizing(['app:send-payday-plan', 'app:send-payday-reminder'])
        ->and($events['app:send-payday-plan']->expression)->toBe('0 9 * * *')
        ->and($events['app:send-payday-reminder']->expression)->toBe('0 20 * * *')
        ->and(config('app.timezone'))->toBe('America/Lima');
});

it('declares the Sentry monitors with written-out slugs, margin 5 and max runtime 15', function () {
    expect(PaydaySchedule::entries())->toBe([
        ['command' => 'app:send-payday-plan', 'cron' => '0 9 * * *', 'monitor' => 'payday-plan', 'margin' => 5, 'runtime' => 15],
        ['command' => 'app:send-payday-reminder', 'cron' => '0 20 * * *', 'monitor' => 'payday-reminder', 'margin' => 5, 'runtime' => 15],
    ]);

    $count = fn (Event $e, string $p): int => count((new ReflectionProperty(Event::class, $p))->getValue($e));

    foreach (paydayScheduleEvents() as $command => $event) {
        expect($count($event, 'beforeCallbacks'))->toBeGreaterThanOrEqual(1, "{$command} sin check-in inicial")
            ->and($count($event, 'afterCallbacks'))->toBeGreaterThanOrEqual(2, "{$command} sin check-in final");
    }
});
