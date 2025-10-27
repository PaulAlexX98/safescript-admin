<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariation extends Model
{
    protected $fillable = [
        'product_id',
        'title',
        'price',
        'sort_order',
        'stock',
        'max_qty',   // <-- make sure this is fillable
        'status',
    ];

    protected $casts = [
        'price'   => 'decimal:2',
        'sort_order' => 'integer',
        'stock'   => 'integer',
        'max_qty' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}