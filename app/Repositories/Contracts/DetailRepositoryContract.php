<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

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
     * The caller's details that are already a categorisation rule for $categoryId.
     *
     * Scoped by both: a rule belongs to a user and to a category, and matching on the
     * category alone would list another user's rules for a category name they share.
     */
    public function paginateRulesForCategory(
        int $userId,
        int $categoryId,
        int $perPage,
        int $page
    ): LengthAwarePaginator;

    /**
     * Uncategorised details closest to what $categoryId already looks like.
     *
     * The category's shape is the average embedding of the details already assigned to
     * it, and candidates are ordered by cosine distance from it. A category with no
     * embedded detail has no shape to compare against, so it yields nothing rather than
     * an arbitrary ordering — an empty page is the honest answer, not a failure.
     *
     * Details that are already a rule are excluded: suggesting what the user has
     * already decided is noise.
     */
    public function paginateSuggestionsForCategory(
        int $userId,
        int $categoryId,
        int $perPage,
        int $page
    ): LengthAwarePaginator;
}
