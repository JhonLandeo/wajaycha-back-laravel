<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Detail|null $detail
 * @property-read Category|null $category
 * @property float $value
 * @property string $name
 * @property float $avg_daily_income
 * @property float $avg_daily_expense
 * @property float $total_income
 * @property float $total_expense
 * @property float $balance
 * @property string $name_month
 * @property int $month
 * @property string $name_day
 * @property int $day
 * @property string $detail_name
 * @property float $monto_promedio
 * @property string $cat_name // Asumo que esta existe por tu groupBy('cat_name')
 * @property float $amount
 * @property string $date_operation
 * @property string $type_transaction
 */
class Transaction extends Model
{
    /** @use HasFactory<\Database\Factories\TransactionFactory> */
    use HasFactory;

    protected $table = 'transactions';

    protected $fillable = [
        'category_id',
        'amount',
        'date_operation',
        'type_transaction',
        'user_id',
        'detail_id',
        'is_manual',
        'is_date_estimated',
        'message',
        'source_type',
        'financial_entity_id',
        'payment_service_id',
        'matched_transaction_id',
    ];

    public function detail(): BelongsTo
    {
        return $this->belongsTo(Detail::class, 'detail_id');
    }

    /**
     * `transactions.category_id` is a plain nullable foreign key to
     * `categories.id`, so this is a `belongsTo` and nothing more.
     *
     * It was declared `hasOneThrough(Category::class, Category::class)`, which
     * names Category as the final model AND as the intermediate one. Eloquent
     * built it literally: it joined `categories` to `categories` under the same
     * name and looked for a `categories.transaction_id` column that has never
     * existed. Reading `$transaction->category` did not return null — it raised
     * `SQLSTATE[42712] Duplicate alias: table name "categories" specified more
     * than once`.
     *
     * That is why nothing broke. A relation that throws on every access cannot
     * be in use anywhere, and the reports reach category data through the
     * PostgreSQL functions instead. The bug was survivable precisely because it
     * was total.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
