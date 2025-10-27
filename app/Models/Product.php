<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'type',
        'image_path', 'price_from', 'status', 'max_bookable_quantity',
    ];

    protected $casts = [
        'price_from' => 'decimal:2',
    ];

    protected $attributes = [
        'status' => 'draft',
        'max_bookable_quantity' => 1,
    ];

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (blank($product->slug)) {
                $base = Str::slug((string) $product->name);
                $slug = $base ?: Str::random(8);

                $i = 2;
                while (static::where('slug', $slug)->exists()) {
                    $slug = $base . '-' . $i;
                    $i++;
                }

                $product->slug = $slug;
            }
        });
    }

    public function services()
    {
        return $this->belongsToMany(\App\Models\Service::class, 'product_service')
            ->withPivot(['active', 'sort_order', 'min_qty', 'max_qty', 'price'])
            ->withTimestamps();
    }

    public function variations()
    {
        return $this->hasMany(\App\Models\ProductVariation::class);
    }
}