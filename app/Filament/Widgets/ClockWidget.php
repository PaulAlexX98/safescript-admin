<?php

namespace App\Filament\Widgets;

use App\Models\StaffShift;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class ClockWidget extends Widget
{
    protected string $view = 'filament.widgets.clock-widget';
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $u = auth()->user();

        return (bool) $u && ((bool) $u->is_pharmacist || (bool) $u->is_staff);
    }

    public function getOpenShift(): ?StaffShift
    {
        return StaffShift::query()
            ->where('user_id', Auth::id())
            ->whereNotNull('clocked_in_at')
            ->whereNull('clocked_out_at')
            ->latest('clocked_in_at')
            ->first();
    }

    public function clockIn(): void
    {
        $u = Auth::user();
        if (!$u) return;

        if (empty($u->gphc_number)) {
            Notification::make()
                ->title('GPhC number missing')
                ->body('Set your GPhC number before clocking in.')
                ->warning()
                ->send();
            return;
        }

        if ($this->getOpenShift()) {
            Notification::make()->title('Already clocked in')->warning()->send();
            return;
        }

        $now = now();

        StaffShift::create([
            'user_id' => $u->id,
            'created_by' => $u->id,
            'shift_date' => $now->toDateString(),
            'clocked_in_at' => $now,
            'clock_in_ip' => request()->ip(),
            'clock_in_ua' => request()->userAgent(),
        ]);

        Notification::make()->title('Clocked in')->success()->send();
    }

    public function clockOut(): void
    {
        $u = Auth::user();
        if (!$u) return;

        $shift = $this->getOpenShift();
        if (!$shift) {
            Notification::make()->title('No open shift')->warning()->send();
            return;
        }

        $now = now();

        $shift->update([
            'created_by' => $u->id,
            'clocked_out_at' => $now,
            'clock_out_ip' => request()->ip(),
            'clock_out_ua' => request()->userAgent(),
        ]);

        Notification::make()->title('Clocked out')->success()->send();
    }
}