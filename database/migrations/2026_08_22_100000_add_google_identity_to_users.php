<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets Google in.
 *
 * Two changes that only make sense together:
 *
 * - `password` becomes nullable. An account born from Google has no password,
 *   and filling it with a random hash would be worse than leaving it empty: that
 *   is a real credential nobody knows and nobody can rotate. `null` states the
 *   truth, and Laravel already refuses it — `EloquentUserProvider::validateCredentials()`
 *   returns false on a null stored hash before the hasher is ever reached.
 * - `google_id` stores the ID token's `sub`, which is the stable identifier of a
 *   Google account. The email is NOT: a user can change it. That is why the link
 *   is persisted by `sub` even when the user was found by email.
 *
 * The UNIQUE index on `google_id` is the only real guarantee that one Google
 * account cannot end up linked to two users. The PHP-side lookup is a race.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('google_id')
                ->nullable()
                ->unique()
                ->after('email')
                ->comment('Claim `sub` del ID token de Google: identificador permanente de la cuenta. El email no lo es.');
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Rolling back can fail, and it should. If accounts created through Google
     * already exist, forcing `password` back to NOT NULL would lock those people
     * out. Decide what happens to them before running this.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
            $table->string('password')->nullable(false)->change();
        });
    }
};
