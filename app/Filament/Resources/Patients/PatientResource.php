<?php

namespace App\Filament\Resources\Patients;

use App\Filament\Resources\Patients\Pages\CreatePatient;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\Schemas\PatientForm;
use App\Filament\Resources\Patients\Schemas\PatientInfolist;
use App\Filament\Resources\Patients\Tables\PatientsTable;
use App\Models\Patient;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Schemas\Schema as FilamentSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\TextEntry;

class PatientResource extends Resource
{
    protected static ?string $model = Patient::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedUsers;
    protected static ?string $navigationLabel = 'Patients';
    protected static ?string $pluralLabel = 'Patients';
    protected static ?string $modelLabel = 'Patient';
    protected static ?string $recordTitleAttribute = 'name';
    protected static string | \UnitEnum | null $navigationGroup = 'People';
    protected static ?int $navigationSort = 9;

    public static function form(FilamentSchema $filamentSchema): FilamentSchema
    {
        return PatientForm::configure($filamentSchema);
    }

    public static function infolist(FilamentSchema $filamentSchema): FilamentSchema
    {
        return $filamentSchema->components([
            Section::make('Patient Details')->schema([
                TextEntry::make('internal_id')->label('Internal ID'),
                TextEntry::make('first_name')->label('First Name'),
                TextEntry::make('last_name')->label('Last Name'),
                TextEntry::make('dob')->label('DOB')->date('d-m-Y'),
                TextEntry::make('gender')->label('Gender'),
                TextEntry::make('email')->label('Email'),
                TextEntry::make('phone')->label('Phone'),
                TextEntry::make('address1')->label('Address Line 1'),
                TextEntry::make('address2')->label('Address Line 2'),
                TextEntry::make('city')->label('City'),
                TextEntry::make('postcode')->label('Postcode'),
                TextEntry::make('country')->label('Country'),
                TextEntry::make('updated_at')->label('Updated At')->dateTime('d-m-Y H:i'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return PatientsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListPatients::route('/'),
            'create' => CreatePatient::route('/create'),
            'view'   => ViewPatient::route('/{record}'),
            'edit'   => EditPatient::route('/{record}/edit'),
        ];
    }
}