<?php

namespace App\Filament\Resources\Scheduling\Schedules;

use App\Filament\Resources\Scheduling\Schedules\ScheduleResource\Pages;
use App\Models\Schedule;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;                                  // v4 forms API
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
use Filament\Tables;
use Filament\Tables\Table;

class ScheduleResource extends Resource
{
    protected static ?string $model = Schedule::class;

    protected static ?string $navigationLabel = 'Schedules';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clock';
    protected static \UnitEnum|string|null $navigationGroup = 'Scheduling';
    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        $dayOptions = [
            'mon' => 'Monday',
            'tue' => 'Tuesday',
            'wed' => 'Wednesday',
            'thu' => 'Thursday',
            'fri' => 'Friday',
            'sat' => 'Saturday',
            'sun' => 'Sunday',
        ];

        return $schema->schema([
            TextInput::make('name')->label('Schedule name')->required()->maxLength(120),
            TextInput::make('service_slug')->label('Service key (optional)')->placeholder('e.g. travel-clinic'),
            TextInput::make('timezone')->default('Europe/London')->required(),
            TextInput::make('slot_minutes')->label('Slot length (minutes)')->numeric()->minValue(5)->default(15)->required(),
            TextInput::make('capacity')->label('Capacity per slot')->numeric()->minValue(1)->default(1)->required(),

            Repeater::make('week')
                ->label('Weekly hours (Mon–Sun)')
                ->collapsed(false)
                ->default(function () use ($dayOptions) {
                    return [
                        ['day' => 'mon', 'open' => true,  'start' => '09:00', 'end' => '17:00', 'break_start' => '13:00', 'break_end' => '14:00'],
                        ['day' => 'tue', 'open' => true,  'start' => '09:00', 'end' => '17:00', 'break_start' => '13:00', 'break_end' => '14:00'],
                        ['day' => 'wed', 'open' => true,  'start' => '09:00', 'end' => '17:00', 'break_start' => '13:00', 'break_end' => '14:00'],
                        ['day' => 'thu', 'open' => true,  'start' => '09:00', 'end' => '17:00', 'break_start' => '13:00', 'break_end' => '14:00'],
                        ['day' => 'fri', 'open' => true,  'start' => '09:00', 'end' => '17:00', 'break_start' => '13:00', 'break_end' => '14:00'],
                        ['day' => 'sat', 'open' => false, 'start' => '09:00', 'end' => '13:00', 'break_start' => null,    'break_end' => null],
                        ['day' => 'sun', 'open' => false, 'start' => '09:00', 'end' => '13:00', 'break_start' => null,    'break_end' => null],
                    ];
                })
                ->schema([
                    Select::make('day')->options($dayOptions)->required(),
                    Toggle::make('open')->label('Open?')->default(true)->inline(false),
                    TextInput::make('start')->label('Start (HH:MM)')->placeholder('09:00')->rule('regex:/^[0-2][0-9]:[0-5][0-9]$/')->required(),
                    TextInput::make('end')->label('End (HH:MM)')->placeholder('17:00')->rule('regex:/^[0-2][0-9]:[0-5][0-9]$/')->required(),
                    TextInput::make('break_start')
                        ->label('Break start (HH:MM)')
                        ->placeholder('13:00')
                        ->helperText('Leave empty if no break')
                        ->rule('regex:/^[0-2][0-9]:[0-5][0-9]$/')
                        ->nullable(),
                    TextInput::make('break_end')
                        ->label('Break end (HH:MM)')
                        ->placeholder('14:00')
                        ->rule('regex:/^[0-2][0-9]:[0-5][0-9]$/')
                        ->nullable(),
                ])
                ->addActionLabel('Add day')
                ->columns(6),

            Repeater::make('overrides')
                ->label('Date overrides (holidays, short days, blackouts)')
                ->schema([
                    TextInput::make('date')->placeholder('YYYY-MM-DD')->rule('date')->required(),
                    Toggle::make('open')->label('Open?')->default(true),
                    TextInput::make('start')->label('Start (HH:MM)')->placeholder('10:00'),
                    TextInput::make('end')->label('End (HH:MM)')->placeholder('16:00'),
                    TagsInput::make('blackouts')->label('Remove times')->placeholder('Add HH:MM and press Enter'),
                    TextInput::make('reason')->placeholder('Reason (optional)'),
                ])
                ->addActionLabel('Add override')
                ->columns(3)
                ->collapsed(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('service_slug')->label('Service')->toggleable()->searchable(),
                Tables\Columns\TextColumn::make('slot_minutes')->label('Slot (min)')->sortable(),
                Tables\Columns\TextColumn::make('capacity')->label('Cap.')->sortable(),
                Tables\Columns\TextColumn::make('timezone')->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime('d M Y, H:i')->label('Updated'),
            ])
            // Make row clickable to Edit (avoids missing action classes)
            ->recordUrl(fn ($record) => static::getUrl('edit', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSchedules::route('/'),
            'create' => Pages\CreateSchedule::route('/create'),
            'edit'   => Pages\EditSchedule::route('/{record}/edit'),
        ];
    }
}