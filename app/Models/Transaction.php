<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Detail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * @property-read Detail $detail
 *
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
    protected $table = 'transactions';
    protected $fillable = [
        'category_id',
        'amount',
        'date_operation',
        'type_transaction',
        'user_id',
        'detail_id',
        'yape_id',
    ];

    public function detail(): BelongsTo
    {
        return $this->belongsTo(Detail::class, 'detail_id');
    }

    public function category(): HasOneThrough
    {
        return $this->hasOneThrough(Category::class, Category::class);
    }

    public function splits(): HasMany
    {
        return $this->hasMany(TransactionSplit::class);
    }
}
