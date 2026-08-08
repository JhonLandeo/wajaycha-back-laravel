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
}
