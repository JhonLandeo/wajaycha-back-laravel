<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionYape extends Model
{
    protected $guarded = [];  

    protected $fillable = [
        'date_operation',
        'message',
        'origin',
        'destination',
        'amount',
        'type_transaction',
        'user_id',
        'detail_id',
    ];
}
