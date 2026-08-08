<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Users\SeedDefaultWorkspaceAction;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Reacts to a User being created. Decides nothing.
 *
 * `created()` used to be a hundred and sixty three line method: the blueprint for a new
 * account, the loop that writes it, and raw `DB::table()` inserts into
 * `category_pareto_assignments`, all in one body and none of it in a transaction. The
 * blueprint is now `config/onboarding.php` and the work is
 * `SeedDefaultWorkspaceAction`.
 *
 * `ShouldHandleEventsAfterCommit` is kept: the seeding must not run against a user row
 * that has not committed.
 */
class UserObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly SeedDefaultWorkspaceAction $seedWorkspace,
    ) {}

    public function created(User $user): void
    {
        $this->seedWorkspace->execute($user);
    }

    public function updated(User $user): void {}

    public function deleted(User $user): void {}

    public function restored(User $user): void {}

    public function forceDeleted(User $user): void {}
}
