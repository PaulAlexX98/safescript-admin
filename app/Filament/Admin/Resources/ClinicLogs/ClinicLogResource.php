<?php

namespace App\Filament\Admin\Resources\ClinicLogs;

use App\Filament\Admin\Resources\ClinicLogs\ClinicLogResource\Pages\CreateClinicLog;
use App\Filament\Admin\Resources\ClinicLogs\ClinicLogResource\Pages\EditClinicLog;
use App\Filament\Admin\Resources\ClinicLogs\ClinicLogResource\Pages\ListClinicLogs;
use App\Filament\Admin\Resources\ClinicLogs\ClinicLogResource\Pages\ViewClinicLog;
use App\Models\ClinicLog;
use BackedEnum;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

class ClinicLogResource extends Resource
{
    protected static ?string $model = ClinicLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('type')
                    ->options([
                        'Fridge' => 'Fridge',
                        'RP' => 'RP',
                        'Handover' => 'Handover',
                    ])
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('branch')->maxLength(120),
                Forms\Components\TextInput::make('pharmacist')->maxLength(120),
                Forms\Components\DatePicker::make('date'),
                Forms\Components\TimePicker::make('start_time')->label('Start'),
                Forms\Components\TimePicker::make('end_time')->label('End'),
                Forms\Components\Textarea::make('notes')->rows(4),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\BadgeColumn::make('type')
                    ->colors([
                        'info' => 'Fridge',
                        'warning' => 'RP',
                        'success' => 'Handover',
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('branch')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('pharmacist')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('date')->date('d-m-Y')->sortable(),
                Tables\Columns\TextColumn::make('start_time')->label('Start'),
                Tables\Columns\TextColumn::make('end_time')->label('End'),
                Tables\Columns\TextColumn::make('notes')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->dateTime('d-m-Y H:i')->label('Updated')->sortable(),
            ])
            ->filters([
                // add filters here if needed
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
