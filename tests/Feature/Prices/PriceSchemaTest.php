<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shape of `price_observations` and `price_ingestion_runs`, read straight
 * from PostgreSQL's catalog (spec "Price observation storage" and "Ingestion
 * run log"). A column type is exactly the detail a later migration can drift
 * from without any application test noticing — float money in particular.
 */
function priceColumn(string $table, string $column): ?object
{
    /** @var object|null $row */
    $row = DB::table('information_schema.columns')
        ->where('table_name', $table)
        ->where('column_name', $column)
        ->select('data_type', 'is_nullable', 'column_default', 'numeric_precision', 'numeric_scale')
        ->first();

    return $row;
}

/**
 * @return string[]
 */
function priceColumnNames(string $table): array
{
    return DB::table('information_schema.columns')
        ->where('table_name', $table)
        ->pluck('column_name')
        ->all();
}

function priceColumnComment(string $table, string $column): ?string
{
    /** @var object|null $row */
    $row = DB::selectOne(
        'SELECT col_description(c.oid, a.attnum) AS comment
           FROM pg_class c
           JOIN pg_attribute a ON a.attrelid = c.oid
          WHERE c.relname = ? AND a.attname = ?',
        [$table, $column],
    );

    return $row?->comment;
}

function priceTableComment(string $table): ?string
{
    /** @var object|null $row */
    $row = DB::selectOne(
        'SELECT obj_description(c.oid, ?) AS comment FROM pg_class c WHERE c.relname = ?',
        ['pg_class', $table],
    );

    return $row?->comment;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function priceRow(int $productId, array $overrides = []): array
{
    return array_merge([
        'product_id' => $productId,
        'source' => 'plazavea',
        'price_kind' => 'retail',
        'unit' => 'kg',
        'unit_price' => '4.3333',
        'sample_size' => 3,
        'basis' => 'measured',
        'period_start' => '2026-10-05',
        'period_end' => '2026-10-05',
        'observed_at' => '2026-10-05 05:00:00-05',
        'source_ref' => 'sku:1',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

// --------------------------------------------------------- price_observations

it('stores money as numeric with four decimals, never as float', function () {
    foreach (['unit_price', 'reference_price', 'price_min', 'price_max'] as $column) {
        $info = priceColumn('price_observations', $column);

        expect($info)->not->toBeNull()
            ->and($info->data_type)->toBe('numeric')
            ->and($info->numeric_precision)->toBe(12)
            ->and($info->numeric_scale)->toBe(4);
    }

    // unit_price is mandatory; the optional money columns are nullable.
    expect(priceColumn('price_observations', 'unit_price')->is_nullable)->toBe('NO')
        ->and(priceColumn('price_observations', 'reference_price')->is_nullable)->toBe('YES')
        ->and(priceColumn('price_observations', 'price_min')->is_nullable)->toBe('YES')
        ->and(priceColumn('price_observations', 'price_max')->is_nullable)->toBe('YES');
});

it('has no store column — the source key is the store identity', function () {
    $columns = priceColumnNames('price_observations');

    expect($columns)->not->toContain('store')
        ->and($columns)->toContain('source')
        ->and($columns)->toContain('unit')
        ->and($columns)->toContain('price_kind')
        ->and($columns)->toContain('basis');
});

it('stores only price facts — no image, description or brand column', function () {
    $columns = priceColumnNames('price_observations');

    expect($columns)->toHaveCount(18);

    foreach ($columns as $name) {
        expect($name)->not->toMatch('/image|photo|logo|description|brand|name/');
    }
});

it('declares the remaining observation columns with the promised types', function () {
    $expectations = [
        'product_id' => ['bigint', 'NO'],
        'source' => ['text', 'NO'],
        'price_kind' => ['text', 'NO'],
        'unit' => ['text', 'NO'],
        'sample_size' => ['smallint', 'NO'],
        'basis' => ['text', 'NO'],
        'period_start' => ['date', 'NO'],
        'period_end' => ['date', 'NO'],
        'observed_at' => ['timestamp with time zone', 'NO'],
        'source_ref' => ['text', 'YES'],
        'is_quarantined' => ['boolean', 'NO'],
        'created_at' => ['timestamp with time zone', 'YES'],
        'updated_at' => ['timestamp with time zone', 'YES'],
    ];

    foreach ($expectations as $column => [$type, $nullable]) {
        $info = priceColumn('price_observations', $column);

        expect($info)->not->toBeNull()
            ->and($info->data_type)->toBe($type)
            ->and($info->is_nullable)->toBe($nullable);
    }

    expect(priceColumn('price_observations', 'is_quarantined')->column_default)->toContain('false');
});

it('rejects a second row for the same product, source and period start', function () {
    $product = Product::factory()->create();

    DB::table('price_observations')->insert(priceRow($product->id));

    // Inside a savepoint: a failed statement would otherwise abort the whole
    // RefreshDatabase transaction and every later insert with it.
    expect(fn () => DB::transaction(
        fn () => DB::table('price_observations')->insert(priceRow($product->id, ['unit_price' => '9.0000']))
    ))->toThrow(QueryException::class);

    // Same product and source, other period: allowed — that is history.
    DB::table('price_observations')->insert(priceRow($product->id, ['period_start' => '2026-10-12', 'period_end' => '2026-10-12']));
    // Same product and period, other source: allowed — that is a second opinion.
    DB::table('price_observations')->insert(priceRow($product->id, ['source' => 'inei']));

    expect(DB::table('price_observations')->count())->toBe(3);
});

it('names the uniqueness guard and the read index', function () {
    expect(DB::table('pg_constraint')->where('conname', 'unq_price_observations_product_id_source_period_start')->exists())->toBeTrue()
        ->and(DB::table('pg_indexes')->where('indexname', 'idx_price_observations_source_period_end')->exists())->toBeTrue();
});

it('removes a product\'s observations when the product goes away', function () {
    $product = Product::factory()->create();
    DB::table('price_observations')->insert(priceRow($product->id));

    expect(DB::table('price_observations')->where('product_id', $product->id)->count())->toBe(1);

    $product->delete();

    expect(DB::table('price_observations')->where('product_id', $product->id)->count())->toBe(0);
});

it('comments the table and its principal columns', function () {
    expect(priceTableComment('price_observations'))->not->toBeNull()->not->toBe('');

    foreach (['source', 'price_kind', 'unit', 'unit_price', 'reference_price', 'basis', 'period_start', 'period_end', 'observed_at', 'source_ref', 'is_quarantined'] as $column) {
        expect(priceColumnComment('price_observations', $column))->not->toBeNull()->not->toBe('');
    }
});

// ------------------------------------------------------ price_ingestion_runs

it('creates the run log with one row per source and unit of work', function () {
    expect(Schema::hasTable('price_ingestion_runs'))->toBeTrue();

    $expectations = [
        'source' => ['text', 'NO'],
        'unit_key' => ['text', 'NO'],
        'status' => ['text', 'NO'],
        'started_at' => ['timestamp with time zone', 'NO'],
        'finished_at' => ['timestamp with time zone', 'YES'],
        'rows_written' => ['integer', 'NO'],
        'rows_rejected' => ['integer', 'NO'],
        'error' => ['text', 'YES'],
        'details' => ['jsonb', 'YES'],
    ];

    foreach ($expectations as $column => [$type, $nullable]) {
        $info = priceColumn('price_ingestion_runs', $column);

        expect($info)->not->toBeNull()
            ->and($info->data_type)->toBe($type)
            ->and($info->is_nullable)->toBe($nullable);
    }

    expect(DB::table('pg_indexes')->where('indexname', 'idx_price_ingestion_runs_source_started_at')->exists())->toBeTrue();
});

it('keeps third-party prices out of the run log', function () {
    foreach (priceColumnNames('price_ingestion_runs') as $name) {
        expect($name)->not->toMatch('/price|amount|cost/');
    }

    expect(priceTableComment('price_ingestion_runs'))->not->toBeNull()->not->toBe('');
});
