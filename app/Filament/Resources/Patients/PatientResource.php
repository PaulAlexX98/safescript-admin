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
    protected static ?int $navigationSort = 1;

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
                TextEntry::make('dob')
                    ->label('DOB')
                    ->getStateUsing(function ($record) {
                        $u = $record->user ?? null;
                        $raw = $u?->dob ?? $record->dob ?? null;
                        if (! $raw) return null;
                        try {
                            return ($raw instanceof \Carbon\Carbon)
                                ? $raw->format('d-m-Y')
                                : \Carbon\Carbon::parse($raw)->format('d-m-Y');
                        } catch (\Throwable $e) {
                            return (string) $raw;
                        }
                    }),
                TextEntry::make('gender')->label('Gender'),
                TextEntry::make('email')->label('Email'),
                TextEntry::make('phone')->label('Phone'),
                TextEntry::make('updated_at')->label('Updated At')->dateTime('d-m-Y H:i'),
            ])->columns(2),

            Section::make('Addresses')->schema([
                TextEntry::make('home_address_block')
                    ->label('Home address')
                    ->getStateUsing(function ($record) {
                        if (! $record) { return '—'; }
                        $u = $record->user ?? null;
                        // Prefer user columns over patient columns so edits on the user reflect immediately
                        $line1 = $u->address1 ?? $u->address_1 ?? $u->address_line1 ?? $record->address1 ?? $record->address_1 ?? $record->address_line1 ?? null;
                        $line2 = $u->address2 ?? $u->address_2 ?? $u->address_line2 ?? $record->address2 ?? $record->address_2 ?? $record->address_line2 ?? null;
                        $city  = $u->city ?? $u->town ?? $u->locality ?? $record->city ?? $record->town ?? null;
                        $pc    = $u->postcode ?? $u->post_code ?? $u->postal_code ?? $u->zip ?? $u->zip_code ?? $record->postcode ?? $record->post_code ?? $record->postal_code ?? null;
                        $ctry  = $u->country ?? $u->country_name ?? $record->country ?? null;
                        $parts = array_values(array_filter([
                            is_string($line1) ? trim($line1) : null,
                            is_string($line2) ? trim($line2) : null,
                            trim(trim((string) $city) . ' ' . trim((string) $pc)) ?: null,
                            is_string($ctry) ? trim($ctry) : null,
                        ]));
                        return empty($parts) ? '—' : implode("\n", $parts);
                    })
                    ->formatStateUsing(fn ($state) => $state ? nl2br(e($state)) : '—')
                    ->html()
                    ->columnSpan(1),

                TextEntry::make('shipping_address_block')
                    ->label('Shipping address')
                    ->getStateUsing(function ($record) {
                        if (! $record) { return '—'; }
                        $u = $record->user ?? null;
                        // Try dedicated user shipping fields first
                        $line1 = $u->shipping_address1 ?? $u->shipping_address_1 ?? $u->shipping_line1 ?? null;
                        $line2 = $u->shipping_address2 ?? $u->shipping_address_2 ?? $u->shipping_line2 ?? null;
                        $city  = $u->shipping_city ?? $u->shipping_town ?? null;
                        $pc    = $u->shipping_postcode ?? $u->shipping_post_code ?? $u->shipping_postal_code ?? $u->shipping_zip ?? $u->shipping_zip_code ?? null;
                        $ctry  = $u->shipping_country ?? null;
                        // Fallback to home if shipping is empty
                        if (! $line1) {
                            $line1 = $u->address1 ?? $u->address_1 ?? $u->address_line1 ?? null;
                            $line2 = $line2 ?: ($u->address2 ?? $u->address_2 ?? $u->address_line2 ?? null);
                            $city  = $city ?: ($u->city ?? $u->town ?? $u->locality ?? null);
                            $pc    = $pc ?: ($u->postcode ?? $u->post_code ?? $u->postal_code ?? $u->zip ?? $u->zip_code ?? null);
                            $ctry  = $ctry ?: ($u->country ?? $u->country_name ?? null);
                        }
                        $parts = array_values(array_filter([
                            is_string($line1) ? trim($line1) : null,
                            is_string($line2) ? trim($line2) : null,
                            trim(trim((string) $city) . ' ' . trim((string) $pc)) ?: null,
                            is_string($ctry) ? trim($ctry) : null,
                        ]));
                        return empty($parts) ? '—' : implode("\n", $parts);
                    })
                    ->formatStateUsing(fn ($state) => $state ? nl2br(e($state)) : '—')
                    ->html()
                    ->columnSpan(1),
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