<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaydayCycleStatus;
use App\Enums\PaydaySkipReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One month of a user's payday plan: what was sent and what they answered.
 *
 * @property int $id
 * @property int $user_id
 * @property \Carbon\CarbonImmutable $period_month
 * @property \Carbon\CarbonImmutable $payday_on
 * @property array{payday_day: int, declared_salary: string, savings_percent: int, savings_amount: string} $plan_snapshot
 * @property PaydayCycleStatus $status
 * @property PaydaySkipReason|null $skip_reason
 * @property int $send_attempts
 * @property \Carbon\CarbonImmutable|null $sent_at
 * @property \Carbon\CarbonImmutable|null $answered_at
 * @property \Carbon\CarbonImmutable|null $reminded_at
 * @property \Carbon\CarbonImmutable|null $reminder_due_on
 */
class PaydayCycle extends Model
{
    /** @use HasFactory<\Database\Factories\PaydayCycleFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'period_month',
        'payday_on',
        'plan_snapshot',
        'status',
        'skip_reason',
        'send_attempts',
        'sent_at',
        'answered_at',
        'reminded_at',
        'reminder_due_on',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'period_month' => 'immutable_date',
            'payday_on' => 'immutable_date',
            'plan_snapshot' => 'array',
            'status' => PaydayCycleStatus::class,
            'skip_reason' => PaydaySkipReason::class,
            'send_attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
            'answered_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'reminder_due_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
