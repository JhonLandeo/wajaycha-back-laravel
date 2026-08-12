<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the schema nothing reads and nothing writes.
 *
 * Each object below was checked against the code AND against 7,460 real
 * transactions before being listed here; an empty table is not on its own
 * evidence of anything.
 *
 *   transactions.is_subscription  Appears once in the whole workspace: the
 *                                 migration that created it. No model, no
 *                                 query, no frontend reference, and false in
 *                                 all 7,460 rows.
 *   details.merchant_id           NULL in all 2,284 rows, referenced by no code.
 *   merchants                     Empty, no `Merchant` model, reachable only
 *                                 through the column above.
 *   details_yape                  Empty. Appears only in its own migration —
 *                                 a leftover of the yape/transaction split that
 *                                 `2026_05_01_203833_unify_transactions_and_yapes_tables`
 *                                 finished.
 *   accounts + imports.account_id The `Account` model exists but is never
 *                                 instantiated anywhere in `app/`, and
 *                                 `imports.account_id` is NULL in all 73 rows.
 *
 * Two tables that look equally dead are deliberately NOT here:
 *
 *   keyword_rules                 Empty, but `CategorizationService` reads it on
 *                                 every categorisation. It is an unfinished
 *                                 feature — no write path was ever built — not
 *                                 dead schema. Dropping it would delete steps 3
 *                                 and 4 of the cascade.
 *   details.ai_reviewed_at,       Written by nothing today, but populated in 967
 *   details.ai_verdict            of 2,284 rows. Whatever produced that is gone;
 *                                 the data it left is not, and it is not this
 *                                 migration's call to discard it.
 *
 * Every drop is guarded. `merchants` and `details.merchant_id` predate the
 * current migration set and no longer exist in a freshly migrated database, so
 * an unguarded drop would break `migrate:fresh` while working on the machines
 * that still carry them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transactions', 'is_subscription')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn('is_subscription');
            });
        }

        if (Schema::hasColumn('details', 'merchant_id')) {
            Schema::table('details', function (Blueprint $table) {
                $table->dropForeign(['merchant_id']);
                $table->dropColumn('merchant_id');
            });
        }

        // Dropped before `accounts`, which it references.
        if (Schema::hasColumn('imports', 'account_id')) {
            Schema::table('imports', function (Blueprint $table) {
                $table->dropForeign(['account_id']);
                $table->dropColumn('account_id');
            });
        }

        Schema::dropIfExists('merchants');
        Schema::dropIfExists('details_yape');
        Schema::dropIfExists('accounts');
    }

    /**
     * Recreates what the migration set knows how to create.
     *
     * `merchants` and `details.merchant_id` are absent here on purpose: no
     * migration in this repository ever created them, so there is no definition
     * to restore. Writing one from the shape of a legacy database would be
     * inventing schema, not reversing a change.
     */
    public function down(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('user_id')->constrained();
            $table->enum('type', ['savings', 'checking', 'credit_card', 'cash', 'other']);
            $table->timestamps();
        });

        Schema::create('details_yape', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->foreign('category_id')->references('id')->on('categories');
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('imports', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable();
            $table->foreign('account_id')->references('id')->on('accounts');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('is_subscription')->default(false)->nullable();
        });
    }
};
