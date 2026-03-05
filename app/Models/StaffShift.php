<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffShift extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'created_by',
        'shift_date',
        'clocked_in_at',
        'clocked_out_at',
        'clock_in_ip',
        'clock_in_ua',
        'clock_out_ip',
        'clock_out_ua',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'clocked_in_at' => 'datetime',
        'clocked_out_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}