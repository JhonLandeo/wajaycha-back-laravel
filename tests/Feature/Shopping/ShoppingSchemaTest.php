<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Verifies the `products` table's shape directly against PostgreSQL's catalog
 * (design.md M1). A column type or a named constraint is exactly the kind of
 * detail a later migration can drift from what design.md promised without any
 * application-level test noticing — sqlite would silently fake several of
 * these (nullable-distinct unique semantics, timestamptz), which is why
 * CLAUDE.md disqualifies it for this suite.
 */
function pgColumn(string $table, string $column): ?object
{
    /** @var object|null $row */
    $row = DB::table('information_schema.columns')
        ->where('table_name', $table)
        ->where('column_name', $column)
        ->select('data_type', 'is_nullable', 'column_default')
        ->first();

    return $row;
}

function pgConstraintExists(string $constraintName): bool
{
    return DB::table('pg_constraint')->where('conname', $constraintName)->exists();
}

/**
 * `$table->index(...)` creates a plain btree index, not a constraint, so
 * `pg_constraint` never sees it — only `pg_indexes` does.
 */
function pgIndexExists(string $indexName): bool
{
    return DB::table('pg_indexes')->where('indexname', $indexName)->exists();
}

it('creates the products table with the columns design.md M1 specifies', function () {
    expect(Schema::hasTable('products'))->toBeTrue();

    $userId = pgColumn('products', 'user_id');
    expect($userId)->not->toBeNull()
        ->and($userId->data_type)->toBe('bigint')
        ->and($userId->is_nullable)->toBe('YES');

    $slug = pgColumn('products', 'slug');
    expect($slug)->not->toBeNull()
        ->and($slug->data_type)->toBe('text')
        ->and($slug->is_nullable)->toBe('YES');

    $name = pgColumn('products', 'name');
    expect($name)->not->toBeNull()
        ->and($name->data_type)->toBe('text')
        ->and($name->is_nullable)->toBe('NO');

    $unit = pgColumn('products', 'unit');
    expect($unit)->not->toBeNull()
        ->and($unit->data_type)->toBe('text')
        ->and($unit->is_nullable)->toBe('NO');

    $isActive = pgColumn('products', 'is_active');
    expect($isActive)->not->toBeNull()
        ->and($isActive->data_type)->toBe('boolean')
        ->and($isActive->is_nullable)->toBe('NO')
        ->and($isActive->column_default)->toContain('true');

    $createdAt = pgColumn('products', 'created_at');
    expect($createdAt)->not->toBeNull()
        ->and($createdAt->data_type)->toBe('timestamp with time zone');

    $updatedAt = pgColumn('products', 'updated_at');
    expect($updatedAt)->not->toBeNull()
        ->and($updatedAt->data_type)->toBe('timestamp with time zone');
});

it('names the two uniqueness guards design.md D3 requires', function () {
    expect(pgConstraintExists('unq_products_slug'))->toBeTrue()
        ->and(pgConstraintExists('unq_products_user_id_name'))->toBeTrue();
});

it('cascades a user deletion onto their own private products', function () {
    // Deleting through the model rather than through a real User::factory()
    // row on purpose: a fresh user carries onboarding side effects (seeded
    // categories, a Pareto classification) with no cascade of their own, so
    // deleting it would fail on an unrelated foreign key before this
    // assertion ever ran. Reading the constraint's own delete action proves
    // the same fact — `cascadeOnDelete()` — without depending on that
    // unrelated seeding.
    $confdeltype = DB::table('pg_constraint')
        ->where('conname', 'products_user_id_foreign')
        ->value('confdeltype');

    expect($confdeltype)->toBe('c');
});

// -------------------------------------------------------------- pantry_items

it('creates the pantry_items table with the columns tasks.md 2.2 specifies', function () {
    expect(Schema::hasTable('pantry_items'))->toBeTrue();

    $quantity = pgColumn('pantry_items', 'quantity');
    expect($quantity)->not->toBeNull()
        ->and($quantity->data_type)->toBe('numeric')
        ->and($quantity->is_nullable)->toBe('NO');

    $unit = pgColumn('pantry_items', 'unit');
    expect($unit)->not->toBeNull()
        ->and($unit->data_type)->toBe('text')
        ->and($unit->is_nullable)->toBe('NO');

    $acquisitionSource = pgColumn('pantry_items', 'acquisition_source');
    expect($acquisitionSource)->not->toBeNull()
        ->and($acquisitionSource->data_type)->toBe('text')
        ->and($acquisitionSource->is_nullable)->toBe('NO');

    $acquiredOn = pgColumn('pantry_items', 'acquired_on');
    expect($acquiredOn)->not->toBeNull()
        ->and($acquiredOn->data_type)->toBe('date')
        ->and($acquiredOn->is_nullable)->toBe('NO')
        ->and($acquiredOn->column_default)->toContain('CURRENT_DATE');

    $expiresOn = pgColumn('pantry_items', 'expires_on');
    expect($expiresOn)->not->toBeNull()
        ->and($expiresOn->data_type)->toBe('date')
        ->and($expiresOn->is_nullable)->toBe('YES');

    $note = pgColumn('pantry_items', 'note');
    expect($note)->not->toBeNull()
        ->and($note->data_type)->toBe('text')
        ->and($note->is_nullable)->toBe('YES');

    $createdAt = pgColumn('pantry_items', 'created_at');
    expect($createdAt)->not->toBeNull()
        ->and($createdAt->data_type)->toBe('timestamp with time zone');
});

it('restricts deleting a product referenced by a pantry item', function () {
    $confdeltype = DB::table('pg_constraint')
        ->where('conname', 'pantry_items_product_id_foreign')
        ->value('confdeltype');

    expect($confdeltype)->toBe('r');
});

it('names the two indexes design.md 2.2 requires on pantry_items', function () {
    expect(pgIndexExists('idx_pantry_items_user_product'))->toBeTrue()
        ->and(pgIndexExists('idx_pantry_items_user_expires'))->toBeTrue();
});

// --------------------------------------------------------- consumption_habits

it('creates the consumption_habits table with the columns tasks.md 3.1 specifies', function () {
    expect(Schema::hasTable('consumption_habits'))->toBeTrue();

    $weeklyQuantity = pgColumn('consumption_habits', 'weekly_quantity');
    expect($weeklyQuantity)->not->toBeNull()
        ->and($weeklyQuantity->data_type)->toBe('numeric')
        ->and($weeklyQuantity->is_nullable)->toBe('NO');

    $unit = pgColumn('consumption_habits', 'unit');
    expect($unit)->not->toBeNull()
        ->and($unit->data_type)->toBe('text')
        ->and($unit->is_nullable)->toBe('NO');

    $isActive = pgColumn('consumption_habits', 'is_active');
    expect($isActive)->not->toBeNull()
        ->and($isActive->data_type)->toBe('boolean')
        ->and($isActive->is_nullable)->toBe('NO')
        ->and($isActive->column_default)->toContain('true');
});

it('restricts deleting a product referenced by a consumption habit', function () {
    $confdeltype = DB::table('pg_constraint')
        ->where('conname', 'consumption_habits_product_id_foreign')
        ->value('confdeltype');

    expect($confdeltype)->toBe('r');
});

it('names the unique guard and index tasks.md 3.1 requires on consumption_habits', function () {
    expect(pgConstraintExists('unq_consumption_habits_user_id_product_id'))->toBeTrue()
        ->and(pgIndexExists('idx_consumption_habits_user_active'))->toBeTrue();
});

// ------------------------------------------------------- grocery_budget_links

it('creates the grocery_budget_links table with the columns tasks.md 4.1 specifies', function () {
    expect(Schema::hasTable('grocery_budget_links'))->toBeTrue();

    $categoryId = pgColumn('grocery_budget_links', 'category_id');
    expect($categoryId)->not->toBeNull()
        ->and($categoryId->data_type)->toBe('bigint')
        ->and($categoryId->is_nullable)->toBe('NO');

    $resolvedBy = pgColumn('grocery_budget_links', 'resolved_by');
    expect($resolvedBy)->not->toBeNull()
        ->and($resolvedBy->data_type)->toBe('text')
        ->and($resolvedBy->is_nullable)->toBe('NO');

    $linkedAt = pgColumn('grocery_budget_links', 'linked_at');
    expect($linkedAt)->not->toBeNull()
        ->and($linkedAt->data_type)->toBe('timestamp with time zone')
        ->and($linkedAt->is_nullable)->toBe('NO');
});

it('names the unique guard and index tasks.md 4.1 requires on grocery_budget_links', function () {
    expect(pgConstraintExists('unq_grocery_budget_links_user_id'))->toBeTrue()
        ->and(pgIndexExists('idx_grocery_budget_links_category_user'))->toBeTrue();
});

it('cascades a category deletion onto its grocery_budget_links pin (design.md M4)', function () {
    // fk_grocery_budget_links_category_id is raw SQL because the Schema builder
    // cannot express a composite FK — verified here against pg_constraint
    // rather than the migration's own text, so a future edit that silently
    // drops the ON DELETE clause (the exact bug the precedent migration
    // shipped) fails this test instead of surfacing as a blocked delete in
    // the SPA.
    $confdeltype = DB::table('pg_constraint')
        ->where('conname', 'fk_grocery_budget_links_category_id')
        ->value('confdeltype');

    expect($confdeltype)->toBe('c');
});
