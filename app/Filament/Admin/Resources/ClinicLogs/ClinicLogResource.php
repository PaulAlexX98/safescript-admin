<?php

namespace App\Filament\Admin\Resources\ClinicLogs;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Admin\Resources\ClinicLogs\ClinicLogResource\Pages\CreateClinicLog;
use App\Filament\Admin\Resources\ClinicLogs\ClinicLogResource\Pages\EditClinicLog;
use App\Filament\Admin\Resources\ClinicLogs\ClinicLogResource\Pages\ListClinicLogs;
use App\Filament\Admin\Resources\ClinicLogs\ClinicLogResource\Pages\ViewClinicLog;
use App\Models\ClinicLog;
use BackedEnum;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

class ClinicLogResource extends Resource
{
    protected static ?string $model = ClinicLog::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->options([
                        'Fridge' => 'Fridge',
                        'RP' => 'RP',
                        'Handover' => 'Handover',
                    ])
                    ->required()
                    ->native(false),
                TextInput::make('branch')->maxLength(120),
                TextInput::make('pharmacist')->maxLength(120),
                DatePicker::make('date'),
                TimePicker::make('start_time')->label('Start'),
                TimePicker::make('end_time')->label('End'),
                Textarea::make('notes')->rows(4),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                BadgeColumn::make('type')
                    ->colors([
                        'info' => 'Fridge',
                        'warning' => 'RP',
                        'success' => 'Handover',
                    ])
                    ->sortable(),
                TextColumn::make('branch')->sortable()->searchable(),
                TextColumn::make('pharmacist')->sortable()->toggleable(),
                TextColumn::make('date')->date('d-m-Y')->sortable(),
                TextColumn::make('start_time')->label('Start'),
                TextColumn::make('end_time')->label('End'),
                TextColumn::make('notes')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime('d-m-Y H:i')->label('Updated')->sortable(),
            ])
            ->filters([
                // add filters here if needed
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClinicLogs::route('/'),
            'create' => CreateClinicLog::route('/create'),
            'view' => ViewClinicLog::route('/{record}'),
            'edit' => EditClinicLog::route('/{record}/edit'),
        ];
    }
}
