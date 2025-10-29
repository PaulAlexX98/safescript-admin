<?php

namespace App\Filament\Resources\Appointments;

use App\Filament\Resources\Appointments\AppointmentResource\Pages;
use App\Models\Appointment;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\DateTimePicker;
use Filament\Schemas\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;

class AppointmentResource extends Resource
{
    protected static ?string $model = Appointment::class;

    // Sidebar placement
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationLabel = 'Appointments';
    protected static \UnitEnum|string|null $navigationGroup = 'Notifications';
    protected static ?int    $navigationSort  = 1;

    // Title used on View/Edit pages
    protected static ?string $recordTitleAttribute = 'display_title';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            DateTimePicker::make('start_at')->label('Start')->seconds(false)->required(),
            DateTimePicker::make('end_at')->label('End')->seconds(false),
            TextInput::make('patient_name')->label('Patient name'),
            TextInput::make('first_name')->maxLength(120),
            TextInput::make('last_name')->maxLength(120),
            TextInput::make('service_name')->label('Service'),
            TextInput::make('service_slug')->label('Service key'),
            TextInput::make('status')->default('booked')->maxLength(50),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('start_at')->label('When')->dateTime('d M Y, H:i')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('patient')
                    ->label('Patient')
                    ->getStateUsing(fn ($r) => $r->patient_name ?: trim(($r->first_name ?? '').' '.($r->last_name ?? '')))
                    ->searchable(),
                Tables\Columns\TextColumn::make('service_name')->label('Service')->toggleable()->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $s) => match ($s) {
                        'booked'    => 'success',
                        'pending'   => 'warning',
                        'cancelled' => 'gray',
                        default     => 'primary',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('deleted_at')->label('Deleted')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Day selector (defaults to today) — shows only that date
                SelectFilter::make('day')
                    ->label('Day')
                    ->options(function () {
                        // build a 361-day window: 180 days back, today, 180 days ahead
                        $options = [];
                        for ($i = -180; $i <= 180; $i++) {
                            $date = now()->clone()->addDays($i);
                            $options[$date->toDateString()] = $date->format('D d M');
                        }
                        return $options;
                    })
                    ->default(now()->toDateString())
                    ->query(function (Builder $q, $state) {
                        $date = is_array($state) ? ($state['value'] ?? null) : $state;
                        return $date ? $q->whereDate('start_at', $date) : $q;
                    })
                    ->indicateUsing(function ($state) {
                        $date = is_array($state) ? ($state['value'] ?? null) : $state;
                        return $date ? 'Day: ' . \Illuminate\Support\Carbon::parse($date)->format('D d M') : null;
                    }),

                // Optional: Upcoming toggle (no default). Use if you only want future times for the chosen day.
                Tables\Filters\Filter::make('upcoming')
                    ->label('Upcoming')
                    ->query(fn (Builder $q) => $q->where('start_at', '>=', now())),
            ])
            ->recordUrl(fn ($record) => static::getUrl('edit', ['record' => $record]));
    }

    // Allow the TrashedFilter to work (include trashed in base query)
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }

    public static function getNavigationBadge(): ?string
    {
        // Count appointments for *today* (entire day). Adjust the column if your schema differs.
        $count = Appointment::query()
            ->whereDate('start_at', now()->toDateString())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        // Green if there are appointments today, gray if none.
        $count = Appointment::query()
            ->whereDate('start_at', now()->toDateString())
            ->count();

        return $count > 0 ? 'success' : 'gray';
    }

    public static function getPages(): array
    {
        $pages = [
            'index' => Pages\ListAppointments::route('/'),
            'edit'  => Pages\EditAppointment::route('/{record}/edit'),
        ];

        // Add the View page only if it exists (prevents class-not-found issues)
        if (class_exists(\App\Filament\Resources\Appointments\AppointmentResource\Pages\ViewAppointment::class)) {
            $pages['view'] = \App\Filament\Resources\Appointments\AppointmentResource\Pages\ViewAppointment::route('/{record}');
        }

        return $pages;
    }
}