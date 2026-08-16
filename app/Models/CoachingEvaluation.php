<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The verdict a sweep reached for one category-month, whether or not it was
 * spoken.
 *
 * Sibling of {@see CoachingObservation}, and the distinction between the two is
 * the whole point: that model records what the coach SAID, this one records what
 * it LOOKED AT. Only the second can answer "was June clean?", because a silent
 * June leaves no row in the first.
 *
 * `EvaluatedCategoryLedger` is the only collaborator that writes to this model.
 * Nothing in the speaking path reads it — it exists to be read later, by streak
 * queries that do not exist yet.
 *
 * @property \Illuminate\Support\Carbon $period_month
 * @property string $outcome
 * @property string $budget_period
 * @property \Illuminate\Support\Carbon $evaluated_at
 */
class CoachingEvaluation extends Model
{
    /**
     * The category was looked at and crossed nothing. This is the value the
     * whole table was added for — every other outcome was already inferable from
     * `coaching_observations`.
     */
    public const OUTCOME_CLEAN = 'clean';

    /**
     * The category had spending but no budget, so no band could be computed.
     * Recorded rather than skipped: "I could not look" and "I looked and it was
     * fine" are different answers, and collapsing them is the bug this table
     * fixes.
     */
    public const OUTCOME_BLIND = 'blind';

    protected $fillable = [
        'user_id',
        'period_month',
        'category_id',
        'outcome',
        'budget_period',
        'budget_amount',
        'spent_amount',
        'evaluated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_month' => 'immutable_date',
            'budget_amount' => 'decimal:2',
            'spent_amount' => 'decimal:2',
            'evaluated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
