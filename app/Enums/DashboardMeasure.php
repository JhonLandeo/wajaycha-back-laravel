<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a dashboard series counts.
 *
 * The SQL functions take this as `p_is_checked BOOLEAN`, and the HTTP payload still
 * calls it `isChecked` — the name of a checkbox, which describes the widget the user
 * clicked and not the thing being asked for. True means "how many transactions",
 * false means "how much money". Nobody can read that at the call site.
 *
 * The boolean also fails the project's own rule against flag arguments: a method that
 * branches on a boolean parameter is two methods wearing one name.
 *
 * The wire format is unchanged — `isChecked` still arrives from the SPA and still
 * reaches PostgreSQL as a boolean. The translation happens once, at the controller,
 * which is where an adapter belongs.
 */
enum DashboardMeasure: string
{
    /** Number of transactions in the period. */
    case Count = 'count';

    /** Sum of amounts in the period. */
    case Amount = 'amount';

    /** The boolean the SQL functions still expect. */
    public function asLegacyFlag(): bool
    {
        return $this === self::Count;
    }
}
