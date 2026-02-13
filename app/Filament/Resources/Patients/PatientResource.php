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
use Illuminate\Support\Collection;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
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
    protected static ?int $navigationSort = 8;

    public static function form(FilamentSchema $filamentSchema): FilamentSchema
    {
        return PatientForm::configure($filamentSchema);
    }

    protected static function normaliseEmail(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    protected static function normalisePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) ($phone ?? '')) ?: '';
    }

    /**
     * Returns up to 10 possible duplicate Patient records based on matching email, phone,
     * or first+last+DOB.
     */
    public static function findPossibleDuplicates(Patient $record): Collection
    {
        $email = static::normaliseEmail($record->email ?? optional($record->user)->email);
        $phone = static::normalisePhone($record->phone ?? optional($record->user)->phone ?? optional($record->user)->phone_number);

        $first = strtolower(trim((string) ($record->first_name ?? optional($record->user)->first_name ?? optional($record->user)->firstName ?? '')));
        $last  = strtolower(trim((string) ($record->last_name ?? optional($record->user)->last_name ?? optional($record->user)->lastName ?? '')));

        $rawDob = $record->dob ?? optional($record->user)->dob ?? null;
        $dob = null;
        if ($rawDob) {
            try {
                $dob = ($rawDob instanceof \Carbon\Carbon)
                    ? $rawDob->toDateString()
                    : \Carbon\Carbon::parse($rawDob)->toDateString();
            } catch (\Throwable $e) {
                $dob = null;
            }
        }

        // --- Extract postcode and address1 from user (with fallbacks)
        $postcodeRaw = optional($record->user)->postcode
            ?? optional($record->user)->post_code
            ?? optional($record->user)->postal_code
            ?? optional($record->user)->postalcode
            ?? optional($record->user)->zip
            ?? optional($record->user)->zip_code
            ?? null;

        $postcode = strtoupper(trim((string) $postcodeRaw));

        $addr1Raw = optional($record->user)->address1
            ?? optional($record->user)->address_1
            ?? optional($record->user)->address_line1
            ?? optional($record->user)->address_line_1
            ?? optional($record->user)->addressLine1
            ?? null;

        $addr1 = strtolower(trim((string) $addr1Raw));

        return Patient::query()
            ->whereKeyNot($record->getKey())
            ->where(function ($q) use ($email, $phone, $first, $last, $dob, $postcode, $addr1) {
                if ($email !== '') {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }

                if ($phone !== '') {
                    $q->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') = ?", [$phone]);
                }

                if ($first !== '' && $last !== '' && $dob) {
                    $q->orWhere(function ($q2) use ($first, $last, $dob) {
                        $q2->whereRaw('LOWER(first_name) = ?', [$first])
                            ->whereRaw('LOWER(last_name) = ?', [$last])
                            ->whereDate('dob', $dob);
                    });
                }

                // Address matching (user fields)
                if ($postcode !== '' && $last !== '') {
                    $q->orWhereHas('user', function ($uq) use ($postcode, $last) {
                        $uq->whereRaw('UPPER(postcode) = ?', [$postcode])
                            ->whereRaw('LOWER(last_name) = ?', [$last]);
                    });
                }

                if ($addr1 !== '' && $postcode !== '') {
                    $q->orWhereHas('user', function ($uq) use ($addr1, $postcode) {
                        $uq->whereRaw('LOWER(address1) = ?', [$addr1])
                            ->whereRaw('UPPER(postcode) = ?', [$postcode]);
                    });
                }
            })
            ->limit(10)
            ->get();
    }

    public static function infolist(FilamentSchema $filamentSchema): FilamentSchema
    {
        return $filamentSchema->components([
            Section::make('Possible duplicates')
                ->schema([
                    TextEntry::make('duplicates_count')
                        ->label('Matches')
                        ->getStateUsing(function ($record) {
                            if (! $record) return 0;
                            return static::findPossibleDuplicates($record)->count();
                        })
                        ->badge()
                        ->color(fn ($state) => ((int) $state) > 0 ? 'danger' : 'success')
                        ->formatStateUsing(fn ($state) => ((int) $state) > 0 ? ((int) $state) . ' possible match' . (((int) $state) === 1 ? '' : 'es') : 'None'),

                    TextEntry::make('duplicates_list')
                        ->label('Matched records')
                        ->getStateUsing(function ($record) {
                            if (! $record) return '—';
                            $matches = static::findPossibleDuplicates($record);
                            if ($matches->isEmpty()) return '—';

                            return $matches->map(function ($p) {
                                $name = trim(trim((string) ($p->first_name ?? '')) . ' ' . trim((string) ($p->last_name ?? '')));
                                $name = $name !== '' ? $name : ('Patient #' . $p->getKey());
                                $email = is_string($p->email ?? null) && trim($p->email) !== '' ? trim($p->email) : null;
                                $phone = is_string($p->phone ?? null) && trim($p->phone) !== '' ? trim($p->phone) : null;

                                $bits = array_values(array_filter([
                                    'ID ' . ($p->internal_id ?: $p->getKey()),
                                    $name,
                                    $email,
                                    $phone,
                                ]));

                                return implode(' • ', $bits);
                            })->implode("\n");
                        })
                        ->formatStateUsing(fn ($state) => $state ? nl2br(e((string) $state)) : '—')
                        ->html()
                        ->columnSpanFull(),
                ])
                ->visible(function ($record) {
                    if (! $record) return false;
                    return static::findPossibleDuplicates($record)->count() > 0;
                })
                ->columns(2),
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

    public static function getGloballySearchableAttributes(): array
    {
        // Limit to real DB columns to avoid "unknown column" errors
        return [
            'internal_id',
            'first_name',
            'last_name',
            'email',
            'phone',
        ];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        // Prefer full name if available
        $first = is_string($record->first_name ?? null) ? trim($record->first_name) : '';
        $last  = is_string($record->last_name ?? null) ? trim($record->last_name) : '';
        $full  = trim(trim($first . ' ' . $last));

        if ($full !== '') {
            return $full;
        }

        if (is_string($record->internal_id ?? null) && trim($record->internal_id) !== '') {
            return 'Patient ' . trim($record->internal_id);
        }

        return 'Patient #' . $record->getKey();
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        $details = [];

        if (is_string($record->email ?? null) && trim($record->email) !== '') {
            $details['Email'] = trim($record->email);
        }

        if (is_string($record->phone ?? null) && trim($record->phone) !== '') {
            $details['Phone'] = trim($record->phone);
        }

        $rawDob = $record->dob ?? optional($record->user)->dob ?? null;
        if ($rawDob) {
            try {
                $dob = $rawDob instanceof \Carbon\Carbon
                    ? $rawDob
                    : \Carbon\Carbon::parse($rawDob);

                $details['DOB'] = $dob->format('d-m-Y');
            } catch (\Throwable $e) {
                // ignore parse errors
            }
        }

        if (is_string($record->internal_id ?? null) && trim($record->internal_id) !== '') {
            $details['ID'] = trim($record->internal_id);
        }

        return $details;
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