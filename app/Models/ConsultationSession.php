<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultationSession extends Model
{
    public function order()
    {
        return $this->belongsTo(ApprovedOrder::class, 'order_id');
    }

    protected $guarded = [];

    protected $attributes = [
        'meta' => '[]',
        'templates' => '[]',
        'steps' => '[]',
        'current' => 0,
    ];
    
    protected $casts = [
        'meta' => 'array',
        'templates' => 'array',
        'steps'     => 'array',
        'current'   => 'integer'
    ];

    /**
     * Answers are stored inside meta['answers'] to avoid requiring a DB column.
     */
    public function getAnswersAttribute()
    {
        $meta = $this->meta ?? [];
        return is_array($meta) && array_key_exists('answers', $meta)
            ? $meta['answers']
            : [];
    }

    public function setAnswersAttribute($value): void
    {
        $meta = $this->meta ?? [];
        if (! is_array($meta)) {
            $meta = [];
        }
        $meta['answers'] = $value;
        // Use the casted attribute so Eloquent serialises it as JSON
        $this->meta = $meta;

        // Ensure no raw 'answers' attribute is kept on the model,
        // so Eloquent won't try to insert/update a non-existent column.
        unset($this->attributes['answers']);
    }
}