<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes it impossible for a transaction to reference another user's merchant or
 * category, and repairs the rows where it already happened.
 *
 * The audit found 29 transactions pointing at a `details` row owned by someone
 * else and 21 pointing at a `categories` row owned by someone else. The single
 * -column foreign keys could not have prevented it: they assert that the id
 * EXISTS, never that it BELONGS to the same user. Every guard against that lived
 * in PHP, so each new query path had to remember it, and the ones that forgot
 * are what produced these rows.
 *
 * The fix moves the rule into the schema. A composite foreign key over
 * (id, user_id) can only be satisfied by a row owned by the same user, so the
 * database rejects the write no matter which code path attempts it.
 *
 * `category_id` stays nullable and uncategorised transactions keep working:
 * under MATCH SIMPLE — PostgreSQL's default — a composite foreign key is not
 * enforced when any of its columns is NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // 1. Repair, because a constraint cannot be added over rows that
        //    already violate it.
        // ---------------------------------------------------------------

        // A category belonging to someone else says nothing true about this
        // transaction, so it is dropped rather than translated. Guessing the
        // equivalent category in the owner's own tree would replace a wrong
        // answer with an invented one, and the product already prefers
        // uncategorised over miscategorised.
        DB::statement(<<<'SQL'
            UPDATE transactions t
               SET category_id = NULL
              FROM categories c
             WHERE c.id = t.category_id
               AND c.user_id <> t.user_id
        SQL);

        // A merchant, unlike a category, carries information worth keeping: it
        // is what groups a user's movements and what their learned rules hang
        // off. So the owner gets their own `details` row with the same name
        // instead of losing the association.
        //
        // `last_used_category_id` is deliberately NOT copied. It is the other
        // user's learned categorisation, and carrying it over would leak the
        // very thing this migration exists to stop — one user's spending
        // habits reaching another user's account.
        DB::statement(<<<'SQL'
            INSERT INTO details (description, user_id, entity_clean, operation_type, created_at, updated_at)
            SELECT DISTINCT ON (t.user_id, d.description)
                   d.description, t.user_id, d.entity_clean, d.operation_type, now(), now()
              FROM transactions t
              JOIN details d ON d.id = t.detail_id
             WHERE d.user_id <> t.user_id
               AND NOT EXISTS (
                   SELECT 1
                     FROM details own
                    WHERE own.user_id = t.user_id
                      AND own.description = d.description
               )
             ORDER BY t.user_id, d.description, d.id
        SQL);

        // Repointed to the lowest-id match rather than an arbitrary one. The
        // audit found no user holding two `details` with the same description,
        // so today the choice is theoretical — but an UPDATE whose result
        // depends on the query plan is not something to leave in a migration.
        DB::statement(<<<'SQL'
            UPDATE transactions t
               SET detail_id = (
                   SELECT MIN(own.id)
                     FROM details own
                    WHERE own.user_id = t.user_id
                      AND own.description = d_other.description
               )
              FROM details d_other
             WHERE d_other.id = t.detail_id
               AND d_other.user_id <> t.user_id
        SQL);

        // ---------------------------------------------------------------
        // 2. Enforce.
        // ---------------------------------------------------------------

        // The referenced side of a composite foreign key must be unique. `id`
        // is already the primary key, so these add no restriction that was not
        // true already — they only make the pair addressable.
        DB::statement('ALTER TABLE details ADD CONSTRAINT unq_details_id_user_id UNIQUE (id, user_id)');
        DB::statement('ALTER TABLE categories ADD CONSTRAINT unq_categories_id_user_id UNIQUE (id, user_id)');

        // Without this index, deleting a detail or a category has to scan
        // `transactions` in full to prove no row still references it.
        DB::statement('CREATE INDEX idx_transactions_detail_user ON transactions (detail_id, user_id)');
        DB::statement('CREATE INDEX idx_transactions_category_user ON transactions (category_id, user_id)');

        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_detail_id_foreign');
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_category_id_foreign');

        DB::statement(<<<'SQL'
            ALTER TABLE transactions
              ADD CONSTRAINT fk_transactions_detail_id
              FOREIGN KEY (detail_id, user_id) REFERENCES details (id, user_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE transactions
              ADD CONSTRAINT fk_transactions_category_id
              FOREIGN KEY (category_id, user_id) REFERENCES categories (id, user_id)
        SQL);

        // `categorization_rules` is the other table that names a user, a
        // merchant and a category at once. The audit found it clean in both
        // directions, so there is nothing to repair — but leaving the door open
        // on the one table that WRITES the categorisation the others only read
        // would defeat the point.
        DB::statement(<<<'SQL'
            ALTER TABLE categorization_rules
              ADD CONSTRAINT fk_categorization_rules_detail_id
              FOREIGN KEY (detail_id, user_id) REFERENCES details (id, user_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE categorization_rules
              ADD CONSTRAINT fk_categorization_rules_category_id
              FOREIGN KEY (category_id, user_id) REFERENCES categories (id, user_id)
        SQL);
    }

    /**
     * Restores the schema, NOT the data.
     *
     * The repair above is one-way by nature: the rows that pointed at another
     * user's records no longer exist to point back, and re-creating them would
     * mean re-introducing the defect on purpose. Rolling back therefore returns
     * the weaker constraints and leaves the corrected rows corrected.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE categorization_rules DROP CONSTRAINT fk_categorization_rules_category_id');
        DB::statement('ALTER TABLE categorization_rules DROP CONSTRAINT fk_categorization_rules_detail_id');

        DB::statement('ALTER TABLE transactions DROP CONSTRAINT fk_transactions_category_id');
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT fk_transactions_detail_id');

        DB::statement(<<<'SQL'
            ALTER TABLE transactions
              ADD CONSTRAINT transactions_detail_id_foreign
              FOREIGN KEY (detail_id) REFERENCES details (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE transactions
              ADD CONSTRAINT transactions_category_id_foreign
              FOREIGN KEY (category_id) REFERENCES categories (id)
        SQL);

        DB::statement('DROP INDEX idx_transactions_category_user');
        DB::statement('DROP INDEX idx_transactions_detail_user');

        DB::statement('ALTER TABLE categories DROP CONSTRAINT unq_categories_id_user_id');
        DB::statement('ALTER TABLE details DROP CONSTRAINT unq_details_id_user_id');
    }
};
