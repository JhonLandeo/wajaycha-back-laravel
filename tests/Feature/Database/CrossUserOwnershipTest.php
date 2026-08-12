<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * These assertions belong to the database, not to a service.
 *
 * Every previous guard against cross-user references lived in PHP, which is
 * exactly why 29 transactions ended up pointing at another user's merchant: a
 * rule that each query path has to remember is a rule some query path will
 * forget. What is tested here is that the write fails even when no application
 * code objects to it — the models are used raw, with no action, no repository
 * and no scope in the way.
 */
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->stranger = User::factory()->create();
});

it('rechaza una transacción que apunta al comercio de otro usuario', function () {
    $strangersDetail = Detail::factory()->create(['user_id' => $this->stranger->id]);

    expect(fn () => Transaction::factory()->create([
        'user_id' => $this->owner->id,
        'detail_id' => $strangersDetail->id,
    ]))->toThrow(QueryException::class);
});

it('rechaza una transacción que apunta a la categoría de otro usuario', function () {
    $ownDetail = Detail::factory()->create(['user_id' => $this->owner->id]);
    $strangersCategory = Category::factory()->create(['user_id' => $this->stranger->id]);

    expect(fn () => Transaction::factory()->create([
        'user_id' => $this->owner->id,
        'detail_id' => $ownDetail->id,
        'category_id' => $strangersCategory->id,
    ]))->toThrow(QueryException::class);
});

it('acepta una transacción cuando el comercio y la categoría son del mismo usuario', function () {
    $ownDetail = Detail::factory()->create(['user_id' => $this->owner->id]);
    $ownCategory = Category::factory()->create(['user_id' => $this->owner->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->owner->id,
        'detail_id' => $ownDetail->id,
        'category_id' => $ownCategory->id,
    ]);

    expect($transaction->exists)->toBeTrue();
});

/**
 * The composite key covers (category_id, user_id), and PostgreSQL's default
 * MATCH SIMPLE skips enforcement when any column of the key is NULL. That is
 * the behaviour the product depends on — the cascade returns null whenever it
 * cannot categorise, and "prefer uncategorised over wrong" would be impossible
 * if the constraint rejected it.
 */
it('sigue permitiendo una transacción sin categoría', function () {
    $ownDetail = Detail::factory()->create(['user_id' => $this->owner->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->owner->id,
        'detail_id' => $ownDetail->id,
        'category_id' => null,
    ]);

    expect($transaction->category_id)->toBeNull()
        ->and($transaction->exists)->toBeTrue();
});

it('rechaza una regla de categorización sobre el comercio de otro usuario', function () {
    $strangersDetail = Detail::factory()->create(['user_id' => $this->stranger->id]);
    $ownCategory = Category::factory()->create(['user_id' => $this->owner->id]);

    expect(fn () => \App\Models\CategorizationRule::create([
        'user_id' => $this->owner->id,
        'detail_id' => $strangersDetail->id,
        'category_id' => $ownCategory->id,
    ]))->toThrow(QueryException::class);
});
