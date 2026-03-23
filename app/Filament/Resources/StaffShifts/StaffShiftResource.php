<?php

namespace App\Filament\Resources\StaffShifts;

use App\Filament\Resources\StaffShifts\Pages;
use App\Models\StaffShift;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\FontWeight;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Schemas\Schema;

class StaffShiftResource extends Resource
{
    protected static ?string $model = StaffShift::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;
    protected static ?string $navigationLabel = 'Responsible Pharmacist Log';
    
    protected static ?string $modelLabel = 'Logs';
    protected static ?string $pluralModelLabel = 'Responsible Pharmacist Log';

    // Put it under Operations (as you asked)
    protected static string|\UnitEnum|null $navigationGroup = 'Logs';

    // Optional: control ordering in the sidebar
    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        // Read-only resource: no create/edit forms.
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('clocked_in_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('shift_date')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user_display')
                    ->label('Staff')
                    ->getStateUsing(function (StaffShift $record) {
                        $u = $record->user;
                        if (!$u) return '—';
                        return $u->pharmacist_display_name
                            ?: $u->name
                            ?: trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''))
                            ?: '—';
                    })
                    ->searchable(query: function (Builder $query, string $search) {
                        // Search user fields
                        $query->whereHas('user', function (Builder $q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('pharmacist_display_name', 'like', "%{$search}%")
                                ->orWhere('gphc_number', 'like', "%{$search}%");
                        });
                    }),

                Tables\Columns\TextColumn::make('user.gphc_number')
                    ->label('Reg no')
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('clocked_in_at')
                    ->label('Clock in')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payroll_in')
                    ->label('Payroll in')
                    ->getStateUsing(function (StaffShift $record) {
                        if (!$record->clocked_in_at) return '—';
                        $t = $record->clocked_in_at->copy();
                        $scheduled = $t->copy()->setTime(9, 0, 0);
                        $snapped = $t->between($scheduled->copy()->subMinute(), $scheduled->copy()->addMinute()->addSeconds(59))
                            ? $scheduled
                            : $t;
                        return $snapped->format('d M Y H:i:s');
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('clocked_out_at')
                    ->label('Clock out')
                    ->dateTime('d M Y H:i:s')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('payroll_out')
                    ->label('Payroll out')
                    ->getStateUsing(function (StaffShift $record) {
                        if (!$record->clocked_out_at) return '—';
                        $t = $record->clocked_out_at->copy();
                        $scheduled = $t->copy()->setTime(18, 0, 0);
                        $snapped = $t->between($scheduled->copy()->subMinute(), $scheduled->copy()->addMinute()->addSeconds(59))
                            ? $scheduled
                            : $t;
                        return $snapped->format('d M Y H:i:s');
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('duration')
                    ->label('Duration')
                    ->getStateUsing(function (StaffShift $record) {
                        if (!$record->clocked_in_at) return '—';
                        $end = $record->clocked_out_at ?: now();
                        $mins = $record->clocked_in_at->diffInMinutes($end);
                        $h = intdiv($mins, 60);
                        $m = $mins % 60;
                        return sprintf('%02dh %02dm', $h, $m);
                    })
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->getStateUsing(fn (StaffShift $record) => $record->clocked_out_at ? 'Closed' : 'Open')
                    ->colors([
                        'success' => 'Closed',
                        'warning' => 'Open',
                    ])
                    ->sortable(query: function (Builder $query, string $direction) {
                        // Sort open shifts first/last based on direction
                        return $query->orderByRaw(
                            "(clocked_out_at IS NULL) " . ($direction === 'asc' ? 'DESC' : 'ASC')
                        );
                    }),

                Tables\Columns\TextColumn::make('clock_in_ip')
                    ->label('IP (in)')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('clock_out_ip')
                    ->label('IP (out)')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
            ])
            ->recordUrl(fn (StaffShift $record) => Pages\ViewStaffShift::getUrl(['record' => $record]))
            ->bulkActions([
                // No bulk actions for now
            ])
            ->emptyStateHeading('No shifts yet')
            ->emptyStateDescription('Clock in/out entries will appear here.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Shift')
                ->schema([
                    TextEntry::make('shift_date')->label('Date')->date('d M Y')->weight(FontWeight::SemiBold),
                    TextEntry::make('clocked_in_at')->label('Clock in')->dateTime('d M Y H:i:s')->placeholder('—'),
                    TextEntry::make('payroll_in')
                        ->label('Payroll in')
                        ->getStateUsing(function (StaffShift $record) {
                            if (!$record->clocked_in_at) return '—';
                            $t = $record->clocked_in_at->copy();
                            $scheduled = $t->copy()->setTime(9, 0, 0);
                            $snapped = $t->between($scheduled->copy()->subMinute(), $scheduled->copy()->addMinute()->addSeconds(59))
                                ? $scheduled
                                : $t;
                            return $snapped->format('d M Y H:i:s');
                        }),
                    TextEntry::make('clocked_out_at')->label('Clock out')->dateTime('d M Y H:i:s')->placeholder('—'),
                    TextEntry::make('payroll_out')
                        ->label('Payroll out')
                        ->getStateUsing(function (StaffShift $record) {
                            if (!$record->clocked_out_at) return '—';
                            $t = $record->clocked_out_at->copy();
                            $scheduled = $t->copy()->setTime(18, 0, 0);
                            $snapped = $t->between($scheduled->copy()->subMinute(), $scheduled->copy()->addMinute()->addSeconds(59))
                                ? $scheduled
                                : $t;
                            return $snapped->format('d M Y H:i:s');
                        }),
                    TextEntry::make('status')
                        ->label('Status')
                        ->getStateUsing(fn (StaffShift $record) => $record->clocked_out_at ? 'Closed' : 'Open'),
                    TextEntry::make('duration')
                        ->label('Duration')
                        ->getStateUsing(function (StaffShift $record) {
                            if (!$record->clocked_in_at) return '—';
                            $end = $record->clocked_out_at ?: now();
                            $mins = $record->clocked_in_at->diffInMinutes($end);
                            $h = intdiv($mins, 60);
                            $m = $mins % 60;
                            return sprintf('%02dh %02dm', $h, $m);
                        }),
                ])
                ->columns(2),

            Section::make('Staff')
                ->schema([
                    TextEntry::make('user.name')->label('Name')->placeholder('—'),
                    TextEntry::make('user.email')->label('Email')->placeholder('—'),
                    TextEntry::make('user.gphc_number')->label('Reg no')->placeholder('—'),
                ])
                ->columns(2),

            Section::make('Audit')
                ->schema([
                    TextEntry::make('created_by')->label('Action by (user id)')->placeholder('—'),
                    TextEntry::make('clock_in_ip')->label('Clock in IP')->placeholder('—'),
                    TextEntry::make('clock_out_ip')->label('Clock out IP')->placeholder('—'),
                    TextEntry::make('clock_in_ua')->label('Clock in UA')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('clock_out_ua')->label('Clock out UA')->placeholder('—')->columnSpanFull(),
                ])
                ->collapsed()
                ->columns(2),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaffShifts::route('/'),
            'view'  => Pages\ViewStaffShift::route('/{record}'),
        ];
    }
}