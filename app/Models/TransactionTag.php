<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionTag extends Model
{
    protected $table = 'transaction_tag';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    protected $fillable = [
        'transaction_id',
        'transaction_yape_id',
        'tag_id',
    ];
}
