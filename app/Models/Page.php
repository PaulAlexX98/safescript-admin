<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    protected $table = 'pages';

    protected $fillable = [
        'title',
        'name',
        'slug',
        'template',
        'status',
        'content',
        'description',
        'gallery',
        'visibility',
        'active',
        'meta_title',
        'meta_description',
        'published_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'gallery' => 'array',
        'published_at' => 'datetime',
    ];
}