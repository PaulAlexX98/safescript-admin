<?php

namespace App\Filament\Widgets;

use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Support\Carbon;
use Guava\Calendar\Enums\CalendarViewType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

class AppointmentsCalendarWidget extends CalendarWidget
{
    protected CalendarViewType $calendarView = CalendarViewType::DayGridMonth;
    protected bool $dateClickEnabled = true;
    protected bool $eventClickEnabled = true;
    protected ?string $locale = 'en-gb';
    protected function getEvents(FetchInfo $info): Collection | array | Builder
    {
        $rawStart = is_callable([$info, 'start']) ? $info->start() : (property_exists($info, 'start') ? $info->start : null);
        $rawEnd   = is_callable([$info, 'end'])   ? $info->end()   : (property_exists($info, 'end')   ? $info->end   : null);

        // Fallback guards
        if (! $rawStart) {
            $rawStart = now()->startOfMonth()->toDateString();
        }
        if (! $rawEnd) {
            // FullCalendar passes end-exclusive
            $rawEnd = now()->endOfMonth()->addDay()->toDateString();
        }

        $start = Carbon::parse($rawStart);
        $end   = Carbon::parse($rawEnd)->subSecond();

        // Aggregate map for day counts
        $counts = [];
        $addCount = function (string $date, int $n = 1) use (&$counts) {
            $counts[$date] = ($counts[$date] ?? 0) + $n;
        };

        // Helper to build the Appointments index URL with filters.
        $buildUrl = function (string $date) {
            $on = trim($date) . ' 00:00:00';

            $query = http_build_query([
                'filters' => [
                    'day' => ['on' => $on],
                ],
                'sort' => 'start_at:asc',
            ]);

            return url('/admin/appointments') . '?' . $query;
        };

        // 1) appointments table counts (match the Appointments list filters)
        if (Schema::hasTable('appointments') && Schema::hasColumn('appointments', 'start_at')) {
            $q = DB::table('appointments')
                ->selectRaw('DATE(start_at) as d, COUNT(*) as c')
                ->whereNotNull('start_at')
                ->whereBetween('start_at', [$start, $end]);

            // Soft deletes (match Resource default)
            if (Schema::hasColumn('appointments', 'deleted_at')) {
                $q->whereNull('deleted_at');
            }

            // Status filter (null/empty/waiting/pending)
            if (Schema::hasColumn('appointments', 'status')) {
                $q->where(function ($qq) {
                    $qq->whereNull('status')
                       ->orWhere('status', '')
                       ->orWhere('status', 'waiting')
                       ->orWhere('status', 'pending');
                });
            }

            $rows = $q->groupBy('d')->get();

            foreach ($rows as $r) {
                if (! empty($r->d)) {
                    $addCount($r->d, (int) $r->c);
                }
            }
        }


        $events = [];

        if (! empty($counts)) {
            ksort($counts);

            foreach ($counts as $date => $count) {
                $events[] = CalendarEvent::make()
                    ->title((string) $count)
                    ->start($date)
                    ->end($date)
                    ->allDay(true)
                    ->url($buildUrl($date));
            }
        }

        return $events;
    }
    public function getOptions(): array
    {
        return [
            'initialView'   => 'dayGridMonth',
            'firstDay'      => 1, // Monday start for UK
            'height'        => 'auto',
            'headerToolbar' => [
                'left'   => 'prevYear,prev,next,nextYear today',
                'center' => 'title',
                'right'  => 'dayGridMonth',
            ],
            'buttonText' => [
                'today'       => 'Today',
                'dayGridMonth'=> 'Month',
            ],
            'navLinks'     => true,
            'dayMaxEvents' => false,
            'eventContent' => $this->js(<<<'JS'
                (arg) => {
                    const n = String(arg?.event?.title ?? '').trim();
                    if (!n) return { html: '' };
                    return { html: `<span class="pe-cal-count" aria-label="${n} appointments">${n}</span>` };
                }
            JS),

            'dateClick' => $this->js(<<<'JS'
                (info) => {
                    // Guard: only redirect on an actual user interaction.
                    // Some integrations can trigger dateClick on initial mount without a click.
                    if (!info?.jsEvent) return;

                    let d = String(info?.dateStr ?? '').trim();

                    // FullCalendar can sometimes include time; the Filament filter expects YYYY-MM-DD only.
                    if (d.includes('T')) d = d.split('T')[0];
                    if (d.includes(' ')) d = d.split(' ')[0];

                    // Fallback: derive from Date object
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(d) && info?.date) {
                        d = new Date(info.date).toISOString().slice(0, 10);
                    }

                    const on = `${d} 00:00:00`;
                    const url = `/admin/appointments?filters[day][on]=${encodeURIComponent(on)}&sort=start_at:asc`;
                    window.location.href = url;
                }
            JS),

            'viewDidMount' => $this->js(<<<'JS'
                (arg) => {
                    const styleId = 'pe-appointments-calendar-style';
                    if (!document.getElementById(styleId)) {
                        const s = document.createElement('style');
                        s.id = styleId;
                        s.textContent = `
                            /* Pharmacy Express calendar polish */
                            .fc {
                                --fc-page-bg-color: transparent !important;
                                --fc-neutral-bg-color: transparent !important;
                                --fc-today-bg-color: transparent !important;
                                --fc-list-event-hover-bg-color: transparent !important;
                                --fc-now-indicator-color: currentColor !important;
                                --fc-border-color: rgba(255,255,255,0.10) !important;
                                color: inherit !important;
                            }

                            .fc .fc-scrollgrid,
                            .fc .fc-scrollgrid table {
                                border-radius: 16px !important;
                                overflow: hidden !important;
                                border: 1px solid rgba(255,255,255,0.12) !important;
                                background: rgba(255,255,255,0.03) !important;
                            }

                            .fc .fc-scrollgrid td,
                            .fc .fc-scrollgrid th {
                                border-color: rgba(255,255,255,0.12) !important;
                            }

                            .fc .fc-col-header-cell {
                                background: rgba(255,255,255,0.02) !important;
                            }

                            .fc .fc-col-header-cell-cushion {
                                font-weight: 800 !important;
                                letter-spacing: -0.01em !important;
                                opacity: 0.9 !important;
                                padding: 10px 8px !important;
                            }

                            .fc .fc-toolbar-title {
                                color: inherit !important;
                                font-weight: 900 !important;
                                letter-spacing: -0.02em !important;
                            }

                            .fc .fc-button {
                                background: transparent !important;
                                border: 1px solid rgba(255,255,255,0.14) !important;
                                box-shadow: none !important;
                                color: inherit !important;
                                border-radius: 999px !important;
                                padding: 8px 12px !important;
                                font-weight: 800 !important;
                            }

                            .fc .fc-button:hover {
                                background: rgba(255,255,255,0.06) !important;
                            }

                            .fc .fc-button:focus {
                                outline: none !important;
                                box-shadow: 0 0 0 3px rgba(16,185,129,0.22) !important;
                            }

                            .fc .fc-button.fc-button-active {
                                background: rgba(255,255,255,0.07) !important;
                                border-color: rgba(16,185,129,0.22) !important;
                            }

                            .fc .fc-daygrid-day {
                                position: relative !important;
                            }

                            .fc .fc-daygrid-day-frame {
                                padding: 10px !important;
                                min-height: 96px !important;
                            }

                            .fc .fc-daygrid-day-number {
                                font-weight: 900 !important;
                                font-size: 12px !important;
                                color: inherit !important;
                                opacity: 0.85 !important;
                                padding: 6px 10px !important;
                                border-radius: 999px !important;
                                background: rgba(255,255,255,0.04) !important;
                                border: 1px solid rgba(255,255,255,0.08) !important;
                            }

                            .fc .fc-day-today .fc-daygrid-day-number {
                                background: rgba(16,185,129,0.12) !important;
                                border-color: rgba(16,185,129,0.28) !important;
                            }

                            /* Remove default event bars and render a count badge */
                            .fc .fc-daygrid-event,
                            .fc .fc-timegrid-event {
                                background: transparent !important;
                                border: 0 !important;
                                box-shadow: none !important;
                                padding: 0 !important;
                            }

                            .fc .fc-daygrid-event-harness {
                                position: absolute !important;
                                top: 10px !important;
                                right: 10px !important;
                                left: auto !important;
                                margin: 0 !important;
                                z-index: 5 !important;
                            }

                            .fc .fc-daygrid-event-harness a {
                                text-decoration: none !important;
                            }

                            .fc .fc-daygrid-event .fc-event-main {
                                background: transparent !important;
                                border: 0 !important;
                                padding: 0 !important;
                            }

                            .pe-cal-count {
                                display: inline-flex;
                                align-items: center;
                                justify-content: center;
                                min-width: 30px;
                                height: 22px;
                                padding: 0 10px;
                                border-radius: 999px;
                                font-weight: 900;
                                font-size: 12px;
                                letter-spacing: -0.01em;
                                color: inherit;
                                background: rgba(16,185,129,0.12);
                                border: 1px solid rgba(16,185,129,0.30);
                                backdrop-filter: blur(6px);
                            }

                            .fc .fc-daygrid-event:hover .pe-cal-count {
                                background: rgba(16,185,129,0.18);
                                border-color: rgba(16,185,129,0.40);
                            }

                            /* Make empty days feel less harsh */
                            .fc .fc-daygrid-day:hover {
                                background: rgba(255,255,255,0.02) !important;
                            }
                        `;
                        document.head.appendChild(s);
                    }
                }
            JS),
        ];
    }

    public function options(): array
    {
        // Bridge for Guava v2 which sometimes calls options() instead of getOptions().
        return $this->getOptions();
    }
}