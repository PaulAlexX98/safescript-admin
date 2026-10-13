<?php

namespace App\Filament\Resources\PendingOrders\Widgets;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ManualAppointmentsPending extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        try {
            return static::query()->exists();
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Manual Appointments Pending')
            ->description('Manual appointments are shown separately from paid orders and use the existing appointment workflow.')
            ->query(static::query())
            ->defaultPaginationPageOption(5)
            ->paginationPageOptions([5, 10, 25])
            ->columns([
                TextColumn::make('order_reference')
                    ->label('Ref')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Reference copied'),

                TextColumn::make('patient')
                    ->label('Patient')
                    ->getStateUsing(function (Appointment $record): string {
                        $patientName = trim((string) ($record->patient_name ?? ''));

                        if ($patientName !== '') {
                            return $patientName;
                        }

                        $fullName = trim(
                            trim((string) ($record->first_name ?? ''))
                            .' '
                            .trim((string) ($record->last_name ?? ''))
                        );

                        return $fullName !== '' ? $fullName : '—';
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $like = '%'.$search.'%';

                        return $query->where(function (Builder $patientQuery) use ($like): void {
                            $patientQuery
                                ->where('patient_name', 'like', $like)
                                ->orWhere('first_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        });
                    }),

                TextColumn::make('service_name')
                    ->label('Service')
                    ->getStateUsing(function (Appointment $record): string {
                        foreach ([$record->service_name, $record->service, $record->service_slug] as $service) {
                            $service = trim((string) ($service ?? ''));

                            if ($service !== '') {
                                return $service;
                            }
                        }

                        return '—';
                    })
                    ->searchable(),

                TextColumn::make('start_at')
                    ->label('Appointment')
                    ->getStateUsing(function (Appointment $record): string {
                        try {
                            $startAt = $record->getRawOriginal('start_at');

                            return $startAt
                                ? Carbon::parse($startAt, 'UTC')->tz('Europe/London')->format('d M Y, H:i')
                                : '—';
                        } catch (\Throwable) {
                            return '—';
                        }
                    })
                    ->sortable(),

            ])
            ->recordActions([
                Action::make('editAppointment')
                    ->label('View / Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (Appointment $record): string => AppointmentResource::getUrl('edit', [
                        'record' => $record,
                    ])),
            ])
            ->recordUrl(null)
            ->emptyStateHeading('No manual appointments pending')
            ->emptyStateDescription('Active manual appointments will appear here automatically.');
    }

    public static function query(): Builder
    {
        return Appointment::query()
            ->select([
                'id',
                'order_reference',
                'patient_name',
                'first_name',
                'last_name',
                'email',
                'service_name',
                'service',
                'service_slug',
                'start_at',
            ])
            ->whereNull('order_id')
            ->where('order_reference', 'like', 'PCAO%')
            ->whereNotNull('start_at')
            ->where('start_at', '>=', Carbon::now('Europe/London')->startOfDay()->utc())
            ->where(function (Builder $statusQuery): void {
                $statusQuery
                    ->whereNull('status')
                    ->orWhere('status', '')
                    ->orWhereNotIn('status', [
                        'completed',
                        'complete',
                        'done',
                        'cancelled',
                        'canceled',
                        'rejected',
                    ]);
            })
            ->orderBy('start_at');
    }
}
