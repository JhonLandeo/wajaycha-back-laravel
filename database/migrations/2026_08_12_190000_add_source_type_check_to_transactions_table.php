<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Constrains `transactions.source_type` to the values the domain actually has.
 *
 * `App\Enums\SourceType` already names them, but an enum guards the code paths
 * that go through it and nothing else: the `DB::statement` blocks in these
 * migrations, an UPDATE run by hand, an importer written later that writes the
 * string directly. The column stayed a bare `varchar(255)`, which is how it
 * came to carry a value — `manual` — that meant two different provenances at
 * once. This is the same distinction as the read-only database access:
 * `--access-mode=restricted` is a client-side check, the role's privileges are
 * the guarantee.
 *
 * The list mirrors the enum rather than the data, and the difference is
 * deliberate. `wajaycha_audit` — 7460 rows across four years — holds only
 * `manual`, `import_app` and `import_statement`; `yape_matched` and
 * `transaction` appear solely as computed literals inside the PostgreSQL
 * functions and were never stored. A four-value list would be a truer
 * description of reality, and would also fail the first UPDATE against an
 * environment this repository cannot see. Keeping the constraint equal to the
 * enum makes the invariant one sentence — the column holds what the enum can
 * express — and it still rejects everything a typo or an unvalidated request
 * would produce.
 *
 * NOT VALID for the same reason: it binds every write from now on without
 * reading the existing rows, so no environment can be broken by a migration
 * that could not inspect it first. Once each one is confirmed, a
 * `VALIDATE CONSTRAINT` closes it — and narrowing the list is then one more
 * migration.
 */
return new class extends Migration
{
    private const ALLOWED = [
        'manual',
        'capture',
        'import_app',
        'import_statement',
        'yape_matched',
        'transaction',
    ];

    public function up(): void
    {
        $values = implode(', ', array_map(
            static fn (string $value): string => "'{$value}'",
            self::ALLOWED
        ));

        DB::statement("
            ALTER TABLE transactions
            ADD CONSTRAINT chk_transactions_source_type
            CHECK (source_type IN ({$values}))
            NOT VALID
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS chk_transactions_source_type');
    }
};
