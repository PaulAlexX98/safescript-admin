<?php

namespace App\Filament\Resources\ClinicLogs;

use App\Filament\Resources\ClinicLogs\Pages\CreateClinicLog;
use App\Filament\Resources\ClinicLogs\Pages\EditClinicLog;
use App\Filament\Resources\ClinicLogs\Pages\ListClinicLogs;
use App\Filament\Resources\ClinicLogs\Pages\ViewClinicLog;
use App\Filament\Resources\ClinicLogs\Schemas\ClinicLogForm;
use App\Filament\Resources\ClinicLogs\Schemas\ClinicLogInfolist;
use App\Filament\Resources\ClinicLogs\Tables\ClinicLogsTable;
use App\Models\ClinicLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ClinicLogResource extends Resource
{
    protected static ?string $model = ClinicLog::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;
    protected static ?string $recordTitleAttribute = 'id';
    protected static string | \UnitEnum | null $navigationGroup = 'Logs';
    protected static ?int $navigationSort = 11;

    public static function form(Schema $schema): Schema
    {
        return ClinicLogForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ClinicLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClinicLogsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListClinicLogs::route('/'),
            'create' => CreateClinicLog::route('/create'),
            'view'   => ViewClinicLog::route('/{record}'),
            'edit'   => EditClinicLog::route('/{record}/edit'),
        ];
    }
}