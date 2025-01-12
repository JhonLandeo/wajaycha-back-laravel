<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $guarded = [];
    protected $fillable = [
        'name',
        'category_id',
        'sub_category_id',
        'amount',
        'created_at',
        'updated_at',
        'details_id',
    ];

    protected $casts = [
        'value' => 'float',
    ];


    // Relación Has One con Detail (una transacción tiene un solo detalle)
    public function detail()
    {
        return $this->belongsTo(Detail::class);
    }

    // // Relación Has One Through para obtener la subcategoría
    // public function subCategory()
    // {
    //     return $this->hasOneThrough(
    //         SubCategory::class, 
    //         Detail::class,  
    //         'id',               // Clave foránea en `details` que apunta a `transactions`
    //         'id',               // Clave foránea en `sub_categories` que apunta a `details`
    //         'detail_id',        // Clave local en `transactions` que apunta a `details`
    //         'sub_category_id'   // Clave local en `details` que apunta a `sub_categories`
    //     );
    // }

    // Relación Has One Through para obtener la categoría
    public function category()
    {
        return $this->hasOneThrough(Category::class, SubCategory::class);
    }
}
