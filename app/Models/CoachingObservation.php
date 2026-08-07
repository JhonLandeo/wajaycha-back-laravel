<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the coach has already told a user about a category-month, or about
 * the categories it cannot watch at all.
 *
 * A row here exists so the unique index — not application code — can arbitrate
 * whether the scheduled sweep and the capture-time check may speak about the same
 * `(user_id, period_month, subject_key, band)` more than once (design.md D6).
 * `SpokenObservationLedger` is the only collaborator that writes to this model.
 *
 * @property \Illuminate\Support\Carbon $period_month
 * @property string $subject_key
 * @property string $band
 * @property bool $is_lumpy
 * @property \Illuminate\Support\Carbon $spoken_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
class CoachingObservation extends Model
{
    protected $fillable = [
        'user_id',
        'period_month',
        'subject_key',
        'category_id',
        'band',
        'is_lumpy',
        'budget_amount',
        'spent_amount',
        'projected_amount',
        'day_of_month',
        'entry_point',
        'spoken_at',
        'sent_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_month' => 'immutable_date',
            'is_lumpy' => 'boolean',
            'spoken_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
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
