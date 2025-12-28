<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnifyTransactions extends Model
{
    protected $table = 'mv_unified_transactions';

    public $timestamps = false;

    protected $guarded = [];
}
