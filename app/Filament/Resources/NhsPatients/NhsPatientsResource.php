<?php

namespace App\Filament\Resources\NhsPatients;

use App\Filament\Resources\NhsPatients\Pages\CreateNhsPatients;
use App\Filament\Resources\NhsPatients\Pages\EditNhsPatients;
use App\Filament\Resources\NhsPatients\Pages\ListNhsPatients;
use App\Filament\Resources\NhsPatients\Pages\ViewNhsPatients;
use App\Filament\Resources\NhsPatients\Schemas\NhsPatientsForm;
use App\Filament\Resources\NhsPatients\Schemas\NhsPatientsInfolist;
use App\Filament\Resources\NhsPatients\Tables\NhsPatientsTable;
use App\Models\NhsApplication;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Arr;
use Filament\Tables;

class NhsPatientsResource extends Resource
{
    protected static ?string $model = NhsApplication::class;

    protected static UnitEnum|string|null $navigationGroup = 'People';
    protected static ?string $navigationLabel = 'NHS Patients';
    protected static ?int $navigationSort = 7;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->where('status', 'approved');
    }

    public static function form(Schema $schema): Schema
    {
        return NhsPatientsForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return NhsPatientsInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received')
                    ->since()
                    ->sortable(),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('Name')
                    ->getStateUsing(fn (\App\Models\NhsApplication $r) => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: '—')
                    ->searchable(['first_name','last_name'])
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->copyable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Phone')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('nhs_number')
                    ->label('NHS no')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('postcode')
                    ->label('Postcode')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'approved'=> 'approved',
                    'pending' => 'pending',
                    'rejected'=> 'rejected',
                ])->default('approved'),
            ])
            ->actionsColumnLabel('View')
            ->actions([
                Action::make('viewApplication')
                    ->label('View')
                    ->button()
                    ->color('primary')
                    ->modalHeading(fn (\App\Models\NhsApplication $r) => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'NHS Application')
                    ->modalDescription(fn (\App\Models\NhsApplication $r) => new HtmlString(
                        '<span class="text-xs text-gray-400">Received ' . e(optional($r->created_at)->format('d-m-Y H:i')) . '</span>'
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('5xl')
                    ->schema([
                        Grid::make(12)->schema([
                            Section::make('Patient')
                                ->columnSpan(8)
                                ->schema([
                                    Grid::make(2)->schema([
                                        TextEntry::make('first_name')->label('First name'),
                                        TextEntry::make('last_name')->label('Last name'),
                                        TextEntry::make('dob')->date('d M Y'),
                                        TextEntry::make('gender'),
                                        TextEntry::make('nhs_number')->label('NHS no'),
                                        TextEntry::make('email'),
                                        TextEntry::make('phone'),
                                    ]),
                                ]),
                            Section::make('Status')
                                ->columnSpan(4)
                                ->schema([
                                    TextEntry::make('status')
                                        ->badge()
                                        ->color(function ($state) {
                                            $s = strtolower((string) $state);
                                            return match ($s) {
                                                'approved' => 'success',
                                                'rejected' => 'danger',
                                                default    => 'warning',
                                            };
                                        }),
                                    TextEntry::make('created_at')->label('Received')->dateTime('d-m-Y H:i'),
                                ]),
                        ]),
                        Section::make('Address')
                            ->schema([
                                Grid::make(3)->schema([
                                    TextEntry::make('address')->label('Address')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->address ?? Arr::get($meta, 'address');
                                        }),
                                    TextEntry::make('address1')->label('Address 1')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->address1 ?? Arr::get($meta, 'address1');
                                        }),
                                    TextEntry::make('address2')->label('Address 2')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->address2 ?? Arr::get($meta, 'address2');
                                        }),
                                    TextEntry::make('city')->label('City')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->city ?? Arr::get($meta, 'city');
                                        }),
                                    TextEntry::make('postcode')->label('Postcode')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->postcode ?? Arr::get($meta, 'postcode');
                                        }),
                                    TextEntry::make('country')->label('Country')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->country ?? Arr::get($meta, 'country');
                                        }),
                                ]),
                            ])
                            ->columnSpanFull(),
                        Section::make('Delivery')
                            ->schema([
                                Grid::make(3)->schema([
                                    TextEntry::make('delivery_address')->label('Delivery address')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->delivery_address ?? Arr::get($meta, 'delivery_address');
                                        }),
                                    TextEntry::make('delivery_address1')->label('Delivery address 1')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->delivery_address1 ?? Arr::get($meta, 'delivery_address1');
                                        }),
                                    TextEntry::make('delivery_address2')->label('Delivery address 2')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->delivery_address2 ?? Arr::get($meta, 'delivery_address2');
                                        }),
                                    TextEntry::make('delivery_city')->label('Delivery city')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->delivery_city ?? Arr::get($meta, 'delivery_city');
                                        }),
                                    TextEntry::make('delivery_postcode')->label('Delivery postcode')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->delivery_postcode ?? Arr::get($meta, 'delivery_postcode');
                                        }),
                                    TextEntry::make('delivery_country')->label('Delivery country')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->delivery_country ?? Arr::get($meta, 'delivery_country');
                                        }),
                                ]),
                            ])
                            ->columnSpanFull(),
                        Section::make('Exemption')
                            ->schema([
                                Grid::make(4)->schema([
                                    TextEntry::make('exemption')->label('Exemption')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->exemption ?? Arr::get($meta, 'exemption');
                                        })
                                        ->formatStateUsing(fn ($state) => self::formatExemption($state)),
                                    TextEntry::make('exemption_number')->label('Exemption number'),
                                    TextEntry::make('exemption_expiry')->label('Exemption expiry')->date('d M Y'),
                                ]),
                            ])
                            ->columnSpanFull(),
                    ]),
            ]);
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
            'index' => ListNhsPatients::route('/'),
            'view' => ViewNhsPatients::route('/{record}'),
        ];
    }

    private static function metaArray($raw): array
    {
        if (is_array($raw)) return $raw;
        if (is_string($raw) && $raw !== '') {
            $d = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($d)) return $d;
        }
        return [];
    }

    private static function boolish($v): ?bool
    {
        if ($v === null || $v === '') return null;
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int) $v) === 1;
        if (is_string($v)) {
            $lx = strtolower(trim($v));
            if (in_array($lx, ['1','true','yes','y'], true)) return true;
            if (in_array($lx, ['0','false','no','n'], true))  return false;
        }
        return null;
    }

    private static function pickFirstTruthy(array $candidates, $default = null)
    {
        foreach ($candidates as $x) {
            if ($x === null) continue;
            if (is_string($x) && trim($x) === '') continue;
            return $x;
        }
        return $default;
    }

    private static function resolveUseAltDelivery(\App\Models\NhsApplication $r): bool
    {
        $meta = self::metaArray($r->meta ?? []);
        $candidates = [
            $r->use_alt_delivery ?? null,
            Arr::get($meta, 'use_alt_delivery'),
            Arr::get($meta, 'use_alt_delivery_flag'),
            Arr::get($meta, 'use_alt_delivery.value'),
            Arr::get($meta, 'consents.flags.use_alt_delivery'),
        ];
        foreach ($candidates as $x) {
            $b = self::boolish($x);
            if ($b !== null) return $b;
        }
        return false;
    }

    private static function formatExemption($value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) $value = (string) $value;
        $map = [
            'pays' => 'The patient pays for their prescriptions',
            'over60_or_under16' => 'The patient is 60 years or over or under 16',
            '16to18_education' => 'The patient is 16, 17 or 18 and in full-time education',
            'maternity' => 'Maternity exemption certificate',
            'medical' => 'Medical exemption certificate',
            'ppc' => 'Prescription prepayment certificate',
            'hrt_ppc' => 'HRT only prescription prepayment certificate',
            'mod' => 'Ministry of Defence prescription exemption certificate',
            'hc2' => 'HC2 certificate',
            'income_support' => 'Income Support or Income related Employment and Support Allowance',
            'jobseekers' => 'Income based Jobseekers Allowance',
            'tax_credit' => 'Tax Credit exemption certificate',
            'pension_credit' => 'Pension Credit Guarantee Credit',
            'universal_credit' => 'Universal Credit and meets the eligibility criteria',
        ];
        $k = strtolower(trim($value));
        if (array_key_exists($k, $map)) return $map[$k];
        return ucwords(str_replace(['_', '-'], ' ', $k));
    }
}
