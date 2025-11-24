<?php
// app/Models/Order.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $casts = [
        'meta' => 'array',
        'paid_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    protected $table = 'orders';

    public function scopePendingNhs($q)
    {
        return $q
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.\"type\"')) = ?", ['nhs'])
            ->where('status', 'pending');
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function scopePendingApproval($q)
    {
        return $q->where('status', 'pending');
    }

    public function scopeApproved($q)
    {
        return $q->where('status', 'approved');
    }

    public function scopeCompleted($q)
    {
        return $q->where('status', 'completed');
    }

    public function scopeRejected($q)
    {
        return $q->where('status', 'rejected');
    }

    public function scopeUnpaid($q)
    {
        return $q->where('status', 'unpaid');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function appointment()
    {
        // Temporary: still points to Booking model/table until Appointment model is fully adopted.
        return $this->hasOne(Booking::class, 'order_id');
    }

    /**
     * @deprecated Use appointment() instead.
     */
    public function booking()
    {
        return $this->appointment();
    }

    /**
     * Accessor for products_total_minor
     * Always returns an integer number of pence (minor units) so Filament never sees null.
     */
    public function getProductsTotalMinorAttribute(): int
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        // Preferred item arrays in various payload shapes
        $paths = [
            'meta.items',
            'meta.lines',
            'meta.products',
            'meta.line_items',
            'meta.cart.items',
        ];

        $sum = 0;

        $toMinor = function ($v): ?int {
            if ($v === null || $v === '') return null;
            if (is_int($v)) return $v;
            if (is_float($v)) return (int) round($v * 100);
            if (is_string($v)) {
                $clean = preg_replace('/[^\d\.\-]/', '', $v);
                if ($clean === '' || $clean === '-' || $clean === null) return null;
                if (str_contains($clean, '.')) return (int) round(((float) $clean) * 100);
                return (int) $clean;
            }
            return null;
        };

        // Build items collection from the first non-empty known path
        $items = collect(data_get(['meta' => $meta], 'meta.items'));
        if ($items->isEmpty()) {
            foreach ($paths as $p) {
                $arr = data_get(['meta' => $meta], $p);
                if (is_array($arr) && count($arr)) {
                    $items = collect($arr);
                    break;
                }
            }
        }

        foreach ($items as $it) {
            $line = $toMinor(data_get($it, 'totalMinor'));
            if ($line === null) {
                $unit = $toMinor(data_get($it, 'unitMinor'));
                $qty  = (int) (data_get($it, 'qty') ?? 1);
                if ($unit !== null) $line = $unit * max($qty, 1);
            }
            if ($line !== null) $sum += $line;
        }

        // Fallback totals if no line items matched
        $fallbacks = [
            'meta.totals.products_total_minor',
            'meta.totalMinor',
            'meta.subtotalMinor',
            'meta.totals.subtotalMinor',
        ];
        if ($sum === 0) {
            foreach ($fallbacks as $key) {
                $v = $toMinor(data_get(['meta' => $meta], $key));
                if ($v !== null) {
                    $sum = $v;
                    break;
                }
            }
        }

        return max(0, (int) $sum);
    }

    public function getScrVerifiedAttribute(): ?string
    {
        $meta = is_array($this->meta) ? $this->meta : (json_decode($this->meta ?? '[]', true) ?: []);
        $val = data_get($meta, 'scr_verified') ?? data_get($meta, 'scr_status') ?? data_get($meta, 'scrVerified');

        if ($val !== null && $val !== '') {
            $s = strtolower(trim((string)$val));
            return in_array($s, ['y','yes','true','1'], true) ? 'Yes' : (in_array($s, ['n','no','false','0'], true) ? 'No' : '—');
        }

        $u = $this->user;
        if ($u && $u->scr_verified !== null) {
            return $u->scr_verified ? 'Yes' : 'No';
        }

        return '—';
    }
}