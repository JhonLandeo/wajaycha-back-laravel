<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

/**
 * Reads over the Detail catalogue.
 *
 * A query port, like `DashboardRepositoryContract`: no business rule, no entity, and
 * the PostgreSQL function behind it stays. `get_details` paginates, joins the most
 * used category per Detail and counts the total in one pass — a denormalised read
 * with no ORM, which is what a read side is supposed to look like.
 *
 * The same line applies here as on the dashboard: these rows may be rendered. They
 * may not be used to reach a conclusion. Anything that decides reads through a domain
 * service — see ADR-0009.
 */
interface DetailRepositoryContract
{
    /**
     * One page of the caller's Detail catalogue.
     *
     * `$userId` is an authorization boundary, not a filter of convenience: without it
     * the function returns another user's catalogue, and the endpoint that calls this
     * is a listing, so it would return it in full.
     *
     * Each row carries the paginator's `total_count`, which is why the total is read
     * off the first row rather than counted separately.
     *
     * @return array<int, object{id: int, name: string, created_at: string, category_name: ?string, total_count: int}>
     */
    public function listForUser(int $userId, int $perPage, int $page): array;

    /**
     * Overwrite the two columns Entity Resolution matches on.
     *
     * `operation_type` and `entity_clean` are what candidate search compares, so a
     * backfill that writes them wrong corrupts matching quietly and across every row it
     * touches. It is a bulk write with no user in sight, which is exactly why it belongs
     * behind a named method rather than a `DB::table()` inside a loop in a command.
     */
    public function updateClassification(int $detailId, ?string $operationType, ?string $entityClean): void;
}
