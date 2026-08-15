<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTOs\Coaching\CoachingScope;
use App\Models\User;
use App\Services\Capture\ChannelIdentityResolver;
use App\Services\Coaching\FinancialCoachingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The scheduled entry point (design.md §5.2). Iterates every user reachable on
 * `config('coaching.channels')`, calls `FinancialCoachingService::speak()` for
 * each, and prints/logs the counters design.md D4 requires so a WhatsApp-only
 * user's exclusion is observable rather than a silent drop (ADR-0007).
 *
 * `--dry-run` composes and logs via `FinancialCoachingService::preview()`
 * instead of `speak()` — it never calls the ledger's `claim()` and never sends
 * (design.md §12 rollout step 2: a preview that burns the bands it exists to
 * preview is worse than no preview).
 *
 * The Command orchestrates; it holds no threshold, no band, no string — every
 * decision is `FinancialCoachingService`'s (`.agents/rules/01-laravel-core.md`).
 */
class RunCoachingSweep extends Command
{
    protected $signature = 'app:run-coaching-sweep {--dry-run : Compose and log without claiming or sending}';

    protected $description = 'Evalua el ritmo de gasto de cada usuario alcanzable y habla, como mucho, una vez por categoria-mes';

    public function handle(ChannelIdentityResolver $identities, FinancialCoachingService $coach): int
    {
        $channels = (array) config('coaching.channels');
        $isDryRun = (bool) $this->option('dry-run');

        $totalUsers = User::query()->count();
        $reachableIds = $identities->userIdsReachableOn($channels)->all();
        $reachableCount = count($reachableIds);
        $noChannelCount = $totalUsers - $reachableCount;

        $spoken = 0;
        $silent = 0;

        foreach ($reachableIds as $userId) {
            /** @var User $user */
            $user = User::query()->findOrFail($userId);

            if ($isDryRun) {
                $preview = $coach->preview($user, CoachingScope::sweep());

                if ($preview === null) {
                    $silent++;

                    continue;
                }

                $spoken++;
                $line = "[dry-run] usuario {$user->id}: {$preview}";
                $this->line($line);
                Log::info($line);

                continue;
            }

            if ($coach->speak($user, CoachingScope::sweep())) {
                $spoken++;
            } else {
                $silent++;
            }
        }

        $channelList = implode('/', $channels);
        $summary = $isDryRun
            ? "Coaching (dry-run): {$totalUsers} usuarios, {$reachableCount} alcanzables por {$channelList}, {$noChannelCount} sin canal de coaching, {$spoken} mensajes enviados, {$silent} silencios."
            : "Coaching: {$totalUsers} usuarios, {$reachableCount} alcanzables por {$channelList}, {$noChannelCount} sin canal de coaching, {$spoken} mensajes enviados, {$silent} silencios.";

        $this->info($summary);
        Log::info($summary);

        return self::SUCCESS;
    }
}
