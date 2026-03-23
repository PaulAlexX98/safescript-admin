<?php

namespace App\Filament\Widgets;

use App\Models\StaffShift;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class ClockWidget extends Widget
{
    protected string $view = 'filament.widgets.clock-widget';
    protected int|string|array $columnSpan = 'full';

    public ?string $start_time = null; // HH:MM
    public ?string $end_time = null;   // HH:MM

    public ?string $pharmacist_name = null;
    public ?string $pharmacist_reg = null;

    public static function canView(): bool
    {
        $u = auth()->user();

        return (bool) $u && ((bool) $u->is_pharmacist || (bool) $u->is_staff);
    }

    public function mount(): void
    {
        $now = now()->second(0);
        $u = Auth::user();
        $this->pharmacist_name = trim((string) ($u?->name ?: collect([$u?->first_name, $u?->last_name])->filter()->implode(' ')));
        $this->pharmacist_reg = $u?->gphc_number;

        // Round to nearest 5 minutes.
        $minute = (int) $now->format('i');
        $roundedMinute = (int) (round($minute / 5) * 5);
        if ($roundedMinute === 60) {
            $now->addHour();
            $roundedMinute = 0;
        }
        $now->minute($roundedMinute);

        $shift = $this->getOpenShift();
        $this->start_time = $shift?->clocked_in_at?->format('H:i') ?? $now->format('H:i');
        $this->end_time = $now->copy()->addMinutes(60)->format('H:i');
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

    private function parsePickedTime(?string $time): ?Carbon
    {
        if (!$time) return null;

        // Expect HH:MM.
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) return null;

        $h = (int) $m[1];
        $min = (int) $m[2];

        // Enforce 5-minute increments.
        if (($min % 5) !== 0) return null;

        // Enforce 09:00–18:00 inclusive.
        $total = $h * 60 + $min;
        if ($total < (9 * 60) || $total > (18 * 60)) return null;

        return now()->startOfDay()->addMinutes($total);
    }

    public function clockIn(): void
    {
        $u = Auth::user();
        if (!$u) return;

        $enteredName = trim((string) $this->pharmacist_name);
        $enteredReg = trim((string) $this->pharmacist_reg);

        if ($enteredName === '') {
            Notification::make()
                ->title('Name missing')
                ->body('Enter your name before clocking in.')
                ->warning()
                ->send();
            return;
        }

        if ($enteredReg === '') {
            Notification::make()
                ->title('GPhC number missing')
                ->body('Enter your GPhC number before clocking in.')
                ->warning()
                ->send();
            return;
        }

        if ($this->getOpenShift()) {
            Notification::make()->title('Already clocked in')->warning()->send();
            return;
        }

        $picked = $this->parsePickedTime($this->start_time);
        if (!$picked) {
            Notification::make()
                ->title('Invalid start time')
                ->body('Choose a start time between 09:00 and 18:00 in 5-minute steps.')
                ->warning()
                ->send();
            return;
        }

        $now = $picked;

        $updates = [];
        if (($u->name ?? null) !== $enteredName) {
            $updates['name'] = $enteredName;
        }
        if (($u->gphc_number ?? null) !== $enteredReg) {
            $updates['gphc_number'] = $enteredReg;
        }
        if (!empty($updates)) {
            $u->fill($updates)->save();
        }

        StaffShift::create([
            'user_id' => $u->id,
            'created_by' => $u->id,
            'shift_date' => $now->toDateString(),
            'pharmacist_name' => $enteredName,
            'gphc_number' => $enteredReg,
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

        $picked = $this->parsePickedTime($this->end_time);
        if (!$picked) {
            Notification::make()
                ->title('Invalid end time')
                ->body('Choose an end time between 09:00 and 18:00 in 5-minute steps.')
                ->warning()
                ->send();
            return;
        }

        $now = $picked;

        if ($shift->clocked_in_at && $now->lessThanOrEqualTo($shift->clocked_in_at)) {
            Notification::make()
                ->title('End time must be after start time')
                ->warning()
                ->send();
            return;
        }

        $shift->update([
            'created_by' => $u->id,
            'clocked_out_at' => $now,
            'clock_out_ip' => request()->ip(),
            'clock_out_ua' => request()->userAgent(),
        ]);

        Notification::make()->title('Clocked out')->success()->send();
    }
}