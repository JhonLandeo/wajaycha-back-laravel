<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una parte de un movimiento asignada a una categoría.
 *
 * La suma de los splits de una transacción es exactamente su `amount`, y una
 * transacción con splits no tiene `category_id` propio. Ambos invariantes los
 * sostienen triggers diferidos en la base; este modelo es solo la puerta de
 * lectura y escritura. Ver ADR-0050.
 */
class TransactionSplit extends Model
{
    protected $table = 'transaction_splits';

    protected $fillable = [
        'transaction_id',
        'category_id',
        'user_id',
        'amount',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
