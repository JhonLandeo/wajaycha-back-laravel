<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Capture\ChannelIdentityResolver;
use App\Services\Coaching\BudgetDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The morning entry point: one status board per reachable user.
 *
 * Mirrors {@see RunCoachingSweep}'s shape — same reachability source, same
 * counters, same `--dry-run` — because an operator debugging a silent morning
 * should not have to learn a second vocabulary. The counters exist for the same
 * reason: a user with no Telegram identity has to be visibly excluded rather
 * than silently dropped (ADR-0007).
 *
 * `--dry-run` here is cheaper than the coach's. `BudgetDigestService` claims
 * nothing, so previewing burns no state and the flag only suppresses the send.
 *
 * The Command orchestrates; it holds no threshold, no band and no string
 * (`.agents/rules/01-laravel-core.md`).
 */
class SendBudgetDigest extends Command
{
    protected $signature = 'app:send-budget-digest {--dry-run : Compose and print without sending}';

    protected $description = 'Envia el parte matutino de presupuestos: lo que ya se paso y lo que esta por pasarse';

    public function handle(ChannelIdentityResolver $identities, BudgetDigestService $digest): int
    {
        $channels = (array) config('coaching.channels');
        $isDryRun = (bool) $this->option('dry-run');

        $totalUsers = User::query()->count();
        $reachableIds = $identities->userIdsReachableOn($channels)->all();
        $reachableCount = count($reachableIds);
        $noChannelCount = $totalUsers - $reachableCount;

        $sent = 0;
        $silent = 0;

        foreach ($reachableIds as $userId) {
            /** @var User $user */
            $user = User::query()->findOrFail($userId);

            if ($isDryRun) {
                $message = $digest->compose($user);

                if ($message === null) {
                    $silent++;

                    continue;
                }

                $sent++;
                $this->line("[dry-run] usuario {$user->id}:");
                $this->line($message);

                continue;
            }

            if ($digest->send($user)) {
                $sent++;
            } else {
                $silent++;
            }
        }

        $prefix = $isDryRun ? 'Parte de presupuestos (dry-run)' : 'Parte de presupuestos';
        $channelList = implode('/', $channels);
        $summary = "{$prefix}: {$totalUsers} usuarios, {$reachableCount} alcanzables por {$channelList}, "
            ."{$noChannelCount} sin canal, {$sent} partes enviados, {$silent} silencios.";

        $this->info($summary);
        Log::info($summary);

        return self::SUCCESS;
    }
}
