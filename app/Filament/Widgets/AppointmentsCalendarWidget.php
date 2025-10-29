<?php

namespace App\Filament\Widgets;

use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AppointmentsCalendarWidget extends CalendarWidget
{

    /**
     * Return events for the visible range.
     * Adapt this to your actual schema. I support either:
     *  - appointments table: start_at, end_at, patient_name, status
     *  - orders table: appointment_at, appointment_ends_at, first_name/last_name, service_name, status
     */
    protected function getEvents(FetchInfo $info): Collection|array
    {
        // Prefer an appointments table if present
        if (\Schema::hasTable('appointments') && \Schema::hasColumns('appointments', ['start_at'])) {
            $rows = \DB::table('appointments')
                ->whereBetween('start_at', [$info->start, $info->end])
                ->orderBy('start_at')
                ->get();

            return $rows->map(function ($r) {
                $title = trim(($r->patient_name ?? 'Patient') . ' • ' . ($r->status ?? 'booked'));
                return CalendarEvent::make()      // value object
                    ->title($title)
                    ->start($r->start_at)
                    ->end($r->end_at ?? $r->start_at)
                    ->allDay(false);
            });
        }

        // Fallback: drive from orders table
        $rows = \DB::table('orders')
            ->whereNotNull('appointment_at')
            ->whereBetween('appointment_at', [$info->start, $info->end])
            ->orderBy('appointment_at')
            ->get();

        return $rows->map(function ($r) {
            $name = trim(($r->first_name ?? '').' '.($r->last_name ?? ''));
            $title = trim(($name !== '' ? $name : 'Patient') . ' • ' . ($r->service_name ?? 'Appointment') . ' • ' . ($r->status ?? ''));
            return CalendarEvent::make()
                ->title($title)
                ->start($r->appointment_at)
                ->end($r->appointment_ends_at ?? $r->appointment_at)
                ->allDay(false);
        });
    }
}