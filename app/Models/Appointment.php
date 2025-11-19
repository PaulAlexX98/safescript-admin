<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'start_at', 'end_at',
        'patient_name', 'first_name', 'last_name',
        'service_slug', 'service_name',
        'status', 'order_id',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at'   => 'datetime',
    ];

    // Nice title used by Filament (Resource record title)
    public function getDisplayTitleAttribute(): string
    {
        $name = $this->patient_name ?: trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
        $when = optional($this->start_at)->format('d M Y, H:i');
        return trim(($name ?: 'Appointment').' · '.($when ?: ''));
    }

    /**
     * Link to the owning Order (if stored).
     */
    public function order()
    {
        return $this->belongsTo(\App\Models\Order::class, 'order_id');
    }

    /**
     * Resolve the completed Order for this appointment.
     * Prefers the FK; falls back to matching by order ref when present.
     */
    public function completedOrder(): ?\App\Models\Order
    {
        // If the relation is already loaded and completed, just return it
        if ($this->relationLoaded('order') && $this->order && ($this->order->status === 'completed' || ($this->order->state ?? null) === 'completed')) {
            return $this->order;
        }

        // Try by foreign key
        if ($this->order_id) {
            $o = \App\Models\Order::find($this->order_id);
            if ($o && ($o->status === 'completed' || ($o->state ?? null) === 'completed')) {
                return $o;
            }
        }

        // Optional fallback: try by order reference if the appointment carries one
        $ref = $this->attributes['ref'] ?? ($this->ref ?? null);
        if ($ref) {
            $o = \App\Models\Order::query()
                ->where('ref', $ref)
                ->where(function ($q) {
                    $q->where('status', 'completed')->orWhere('state', 'completed');
                })
                ->latest('id')
                ->first();

            if ($o) return $o;
        }

        return null;
    }

    /**
     * Convenience URL to the Completed Order "Details" page (used by Filament table row links).
     */
    public function completedOrderUrl(): ?string
    {
        $order = $this->completedOrder();

        return $order
            ? url('/admin/orders/completed-orders/' . $order->id . '/details')
            : null;
    }
}