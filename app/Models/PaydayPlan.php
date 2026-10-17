<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The savings commitment a user declared. One per user.
 *
 * @property int $id
 * @property int $user_id
 * @property int $payday_day
 * @property string $declared_salary
 * @property int $savings_percent
 * @property bool $is_active
 */
class PaydayPlan extends Model
{
    /** @use HasFactory<\Database\Factories\PaydayPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'payday_day',
        'declared_salary',
        'savings_percent',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payday_day' => 'integer',
            'declared_salary' => 'decimal:2',
            'savings_percent' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
