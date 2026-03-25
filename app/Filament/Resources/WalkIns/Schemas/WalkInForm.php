<?php

namespace App\Filament\Resources\WalkIns\Schemas;

use App\Models\Patient;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\Str;

class WalkInForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Patient details')
                    ->columnSpanFull()
                    ->headerActions([])
                    ->schema([
                        Grid::make(12)
                            ->schema([
                                Select::make('patient_id')
                                    ->label('Search patient')
                                    ->placeholder('Search by name, email, or phone')
                                    ->searchable()
                                    ->getSearchResultsUsing(function (string $search): array {
                                        $search = trim($search);

                                        if (mb_strlen($search) < 3) {
                                            return [];
                                        }

                                        return Patient::query()
                                            ->with('user')
                                            ->where(function ($query) use ($search) {
                                                $query
                                                    ->where('internal_id', 'like', "%{$search}%")
                                                    ->orWhere('first_name', 'like', "%{$search}%")
                                                    ->orWhere('last_name', 'like', "%{$search}%")
                                                    ->orWhereRaw("concat_ws(' ', first_name, last_name) like ?", ["%{$search}%"])
                                                    ->orWhere('email', 'like', "%{$search}%")
                                                    ->orWhere('phone', 'like', "%{$search}%")
                                                    ->orWhereHas('user', function ($userQuery) use ($search) {
                                                        $userQuery
                                                            ->where('first_name', 'like', "%{$search}%")
                                                            ->orWhere('last_name', 'like', "%{$search}%")
                                                            ->orWhereRaw("concat_ws(' ', first_name, last_name) like ?", ["%{$search}%"])
                                                            ->orWhere('email', 'like', "%{$search}%")
                                                            ->orWhere('phone', 'like', "%{$search}%");
                                                    });
                                            })
                                            ->limit(25)
                                            ->get()
                                            ->mapWithKeys(function ($patient) {
                                                $fullName = trim((string) (($patient->first_name ?: $patient->user?->first_name ?: '') . ' ' . ($patient->last_name ?: $patient->user?->last_name ?: '')));
                                                $email = $patient->email ?: $patient->user?->email ?: 'No email';
                                                $phone = $patient->phone ?: $patient->user?->phone ?: null;
                                                $label = $fullName !== '' ? $fullName : ('Patient #' . $patient->id);
                                                $meta = $email;
                                                if ($phone) {
                                                    $meta .= ' • ' . $phone;
                                                }
                                                return [$patient->id => $label . ' — ' . $meta];
                                            })
                                            ->toArray();
                                    })
                                    ->getOptionLabelUsing(function ($value): ?string {
                                        if (! $value) {
                                            return null;
                                        }

                                        $patient = Patient::query()->with('user')->find($value);
                                        if (! $patient) {
                                            return null;
                                        }

                                        $fullName = trim((string) (($patient->first_name ?: $patient->user?->first_name ?: '') . ' ' . ($patient->last_name ?: $patient->user?->last_name ?: '')));
                                        $email = $patient->email ?: $patient->user?->email ?: 'No email';
                                        $phone = $patient->phone ?: $patient->user?->phone ?: null;
                                        $label = $fullName !== '' ? $fullName : ('Patient #' . $patient->id);
                                        $meta = $email;
                                        if ($phone) {
                                            $meta .= ' • ' . $phone;
                                        }

                                        return $label . ' — ' . $meta;
                                    })
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set): void {
                                        if (! $state) {
                                            return;
                                        }

                                        $patient = Patient::query()->with('user')->find($state);
                                        if (! $patient) {
                                            return;
                                        }

                                        $user = $patient->user;
                                        $set('user_id', $user?->id ?: null);

                                        $set('first_name', $patient->first_name ?: $user?->first_name ?: null);
                                        $set('last_name', $patient->last_name ?: $user?->last_name ?: null);
                                        $set('dob', $patient->dob ?: $user?->dob ?: null);
                                        $rawGender = $patient->gender ?: $user?->gender ?: null;
                                        $normalisedGender = null;
                                        if (is_string($rawGender) && trim($rawGender) !== '') {
                                            $g = strtolower(trim($rawGender));
                                            $normalisedGender = match ($g) {
                                                'male', 'm' => 'male',
                                                'female', 'f' => 'female',
                                                'other' => 'other',
                                                'prefer_not_to_say', 'prefer not to say', 'prefer-not-to-say' => 'prefer_not_to_say',
                                                default => null,
                                            };
                                        }
                                        $set('gender', $normalisedGender);
                                        $set('email', $patient->email ?: $user?->email ?: null);
                                        $set('phone', $patient->phone ?: $user?->phone ?: null);
                                        $set('address_line_1', $patient->address_line_1 ?: $user?->address_line_1 ?: $user?->address1 ?: $user?->shipping_address1 ?: null);
                                        $set('address_line_2', $patient->address_line_2 ?: $user?->address_line_2 ?: $user?->address2 ?: $user?->shipping_address2 ?: null);
                                        $set('city', $patient->city ?: $user?->city ?: $user?->shipping_city ?: null);
                                        $set('county', $patient->county ?: $user?->county ?: null);
                                        $set('postcode', $patient->postcode ?: $user?->postcode ?: $user?->shipping_postcode ?: null);
                                        $set('country', $patient->country ?: $user?->country ?: $user?->shipping_country ?: 'United Kingdom');
                                    })
                                    ->columnSpan(10),
                                
                                Hidden::make('user_id'),

                               

                                TextInput::make('first_name')
                                    ->label('First name')
                                    ->required()
                                    ->columnSpan(6),

                                TextInput::make('last_name')
                                    ->label('Last name')
                                    ->required()
                                    ->columnSpan(6),

                                DatePicker::make('dob')
                                    ->label('Date of birth')
                                    ->native(false)
                                    ->displayFormat('d/m/Y')
                                    ->columnSpan(6),

                                Select::make('gender')
                                    ->label('Gender')
                                    ->placeholder('Select')
                                    ->options([
                                        'male' => 'Male',
                                        'female' => 'Female',
                                        'other' => 'Other',
                                        'prefer_not_to_say' => 'Prefer not to say',
                                    ])
                                    ->columnSpan(6),

                                TextInput::make('email')
                                    ->label('Email')
                                    ->email()
                                    ->columnSpan(6),

                                TextInput::make('phone')
                                    ->label('Phone')
                                    ->tel()
                                    ->columnSpan(6),

                                TextInput::make('address_line_1')
                                    ->label('Address line 1')
                                    ->columnSpan(12),

                                TextInput::make('address_line_2')
                                    ->label('Address line 2')
                                    ->columnSpan(12),

                                TextInput::make('city')
                                    ->label('City')
                                    ->columnSpan(6),

                                TextInput::make('county')
                                    ->label('County')
                                    ->columnSpan(6),

                                TextInput::make('postcode')
                                    ->label('Postcode')
                                    ->columnSpan(6),

                                TextInput::make('country')
                                    ->label('Country')
                                    ->default('United Kingdom')
                                    ->columnSpan(6),
                            ]),
                    ]),


                Section::make('Appointment')
                  ->columnSpanFull()
                    ->schema([
                        Grid::make(12)
                            ->schema([
                                DateTimePicker::make('appointment_at')
                                    ->label('Appointment date/time')
                                    ->native(false)
                                    ->seconds(false)
                                    ->columnSpan(12),
                            ]),
                    ]),

                Section::make('Order Details')
                   ->columnSpanFull()
                    ->schema([
                        Grid::make(12)
                            ->schema([
                                Select::make('service_id')
                                    ->label('Search service')
                                    ->placeholder('Search by service name')
                                    ->searchable()
                                    ->getSearchResultsUsing(function (string $search): array {
                                        $search = trim($search);

                                        if (mb_strlen($search) < 2) {
                                            return [];
                                        }

                                        if (! SchemaFacade::hasTable('services')) {
                                            return [];
                                        }

                                        return DB::table('services')
                                            ->when(SchemaFacade::hasColumn('services', 'name'), function ($query) use ($search) {
                                                $query->where('name', 'like', "%{$search}%");
                                            })
                                            ->limit(25)
                                            ->get()
                                            ->mapWithKeys(function ($service) {
                                                $name = $service->name ?? ('Service #' . $service->id);
                                                return [$service->id => $name];
                                            })
                                            ->toArray();
                                    })
                                    ->getOptionLabelUsing(function ($value): ?string {
                                        if (! $value || ! SchemaFacade::hasTable('services')) {
                                            return null;
                                        }

                                        $service = DB::table('services')->where('id', $value)->first();

                                        return $service->name ?? null;
                                    })
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set): void {
                                        if (! $state || ! SchemaFacade::hasTable('services')) {
                                            $set('service_name', null);
                                            $set('service_slug', null);
                                            return;
                                        }

                                        $service = DB::table('services')->where('id', $state)->first();
                                        if (! $service) {
                                            $set('service_name', null);
                                            $set('service_slug', null);
                                            return;
                                        }

                                        $set('service_name', $service->name ?? null);
                                        $set('service_slug', isset($service->slug) && is_string($service->slug) && trim($service->slug) !== ''
                                            ? trim((string) $service->slug)
                                            : Str::slug((string) ($service->name ?? '')));
                                        $set('items', [[
                                            'name' => null,
                                            'variation' => null,
                                            'qty' => 1,
                                            'unit_price' => null,
                                        ]]);
                                    })
                                    ->columnSpan(6),

                                TextInput::make('service_name')
                                
                                    ->label('Service name')
                                    ->readOnly()
                                    ->columnSpan(6),
                                Hidden::make('service_slug'),
                            ]),
                        Repeater::make('items')
                            ->label('')
                            ->defaultItems(1)
                            ->addActionLabel('Add item')
                            ->reorderable(false)
                            ->collapsible(false)
                            ->schema([
                                Grid::make(12)
                                    ->schema([
                                        Select::make('name')
                                            ->label('Item name')
                                            ->placeholder('Choose item')
                                            ->options(function (callable $get): array {
                                                $serviceId = $get('../../service_id') ?: $get('service_id');

                                                if (! $serviceId) {
                                                    return [];
                                                }

                                                if (SchemaFacade::hasTable('products')) {
                                                    $nameColumn = SchemaFacade::hasColumn('products', 'name')
                                                        ? 'name'
                                                        : (SchemaFacade::hasColumn('products', 'title') ? 'title' : null);

                                                    if ($nameColumn) {
                                                        if (SchemaFacade::hasTable('service_product')) {
                                                            return DB::table('products')
                                                                ->join('service_product', 'products.id', '=', 'service_product.product_id')
                                                                ->where('service_product.service_id', $serviceId)
                                                                ->orderBy("products.{$nameColumn}")
                                                                ->select(['products.id', DB::raw("products.{$nameColumn} as product_name")])
                                                                ->limit(500)
                                                                ->get()
                                                                ->mapWithKeys(fn ($row) => [(string) $row->product_name => (string) $row->product_name])
                                                                ->toArray();
                                                        }

                                                        if (SchemaFacade::hasTable('product_service')) {
                                                            return DB::table('products')
                                                                ->join('product_service', 'products.id', '=', 'product_service.product_id')
                                                                ->where('product_service.service_id', $serviceId)
                                                                ->orderBy("products.{$nameColumn}")
                                                                ->select(['products.id', DB::raw("products.{$nameColumn} as product_name")])
                                                                ->limit(500)
                                                                ->get()
                                                                ->mapWithKeys(fn ($row) => [(string) $row->product_name => (string) $row->product_name])
                                                                ->toArray();
                                                        }
                                                    }
                                                }

                                                if (SchemaFacade::hasTable('service_medicines')) {
                                                    $nameColumn = SchemaFacade::hasColumn('service_medicines', 'name')
                                                        ? 'name'
                                                        : (SchemaFacade::hasColumn('service_medicines', 'title') ? 'title' : null);

                                                    if ($nameColumn && SchemaFacade::hasColumn('service_medicines', 'service_id')) {
                                                        return DB::table('service_medicines')
                                                            ->where('service_id', $serviceId)
                                                            ->orderBy($nameColumn)
                                                            ->limit(500)
                                                            ->get()
                                                            ->mapWithKeys(function ($row) use ($nameColumn) {
                                                                $name = (string) ($row->{$nameColumn} ?? ('Item #' . $row->id));
                                                                return [$name => $name];
                                                            })
                                                            ->toArray();
                                                    }
                                                }

                                                return [];
                                            })
                                            ->searchable()
                                            ->preload()
                                            ->required()
                                            ->disabled(fn (callable $get): bool => blank($get('../../service_id')) && blank($get('service_id')))
                                            ->helperText(fn (callable $get): ?string => blank($get('../../service_id')) && blank($get('service_id')) ? 'Choose service first' : null)
                                            ->live()
                                            ->afterStateUpdated(function ($state, callable $set): void {
                                                $set('variation', null);
                                                $set('unit_price', null);
                                            })
                                            ->columnSpan(6),

                                        Select::make('variation')
                                            ->label('Variation')
                                            ->placeholder('Choose variation')
                                            ->searchable()
                                            ->preload()
                                            ->live()
                                            ->disabled(fn (callable $get): bool => blank($get('name')))
                                            ->helperText(fn (callable $get): ?string => blank($get('name')) ? 'Choose item name first' : null)
                                            ->options(function (callable $get): array {
                                                $itemName = trim((string) ($get('name') ?? ''));

                                                if ($itemName === '') {
                                                    return [];
                                                }

                                                $options = [];

                                                if (SchemaFacade::hasTable('product_variations') && SchemaFacade::hasTable('products')) {
                                                    $productNameColumn = SchemaFacade::hasColumn('products', 'name')
                                                        ? 'name'
                                                        : (SchemaFacade::hasColumn('products', 'title') ? 'title' : null);

                                                    $variationLabelColumn = SchemaFacade::hasColumn('product_variations', 'title')
                                                        ? 'title'
                                                        : (SchemaFacade::hasColumn('product_variations', 'label')
                                                            ? 'label'
                                                            : (SchemaFacade::hasColumn('product_variations', 'name')
                                                                ? 'name'
                                                                : (SchemaFacade::hasColumn('product_variations', 'variation')
                                                                    ? 'variation'
                                                                    : (SchemaFacade::hasColumn('product_variations', 'strength') ? 'strength' : null))));

                                                    $productIdColumn = SchemaFacade::hasColumn('product_variations', 'product_id')
                                                        ? 'product_id'
                                                        : null;

                                                    if ($productNameColumn && $variationLabelColumn && $productIdColumn) {
                                                        $rows = DB::table('product_variations')
                                                            ->join('products', "product_variations.{$productIdColumn}", '=', 'products.id')
                                                            ->where("products.{$productNameColumn}", $itemName)
                                                            ->select([
                                                                "product_variations.{$variationLabelColumn} as variation_label",
                                                                SchemaFacade::hasColumn('product_variations', 'price') ? 'product_variations.price as variation_price' : DB::raw('null as variation_price'),
                                                                SchemaFacade::hasColumn('product_variations', 'price_minor') ? 'product_variations.price_minor as variation_price_minor' : DB::raw('null as variation_price_minor'),
                                                            ])
                                                            ->orderBy("product_variations.{$variationLabelColumn}")
                                                            ->limit(100)
                                                            ->get();

                                                        foreach ($rows as $row) {
                                                            $label = trim((string) ($row->variation_label ?? ''));
                                                            if ($label !== '') {
                                                                $options[$label] = $label;
                                                            }
                                                        }
                                                    }
                                                }

                                                if (empty($options) && SchemaFacade::hasTable('products')) {
                                                    $nameColumn = SchemaFacade::hasColumn('products', 'name')
                                                        ? 'name'
                                                        : (SchemaFacade::hasColumn('products', 'title') ? 'title' : null);

                                                    if ($nameColumn) {
                                                        $product = DB::table('products')->where($nameColumn, $itemName)->first();

                                                        if ($product) {
                                                            foreach (['title', 'variation', 'variations', 'strength', 'dose', 'option', 'options', 'variant', 'variants'] as $key) {
                                                                $value = $product->{$key} ?? null;

                                                                if (is_string($value) && trim($value) !== '') {
                                                                    $decoded = json_decode($value, true);

                                                                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                                                        foreach ($decoded as $entry) {
                                                                            if (is_array($entry)) {
                                                                                $label = trim((string) ($entry['title'] ?? $entry['label'] ?? $entry['name'] ?? $entry['value'] ?? ''));
                                                                            } else {
                                                                                $label = trim((string) $entry);
                                                                            }

                                                                            if ($label !== '') {
                                                                                $options[$label] = $label;
                                                                            }
                                                                        }
                                                                    } else {
                                                                        $label = trim($value);
                                                                        if ($label !== '') {
                                                                            $options[$label] = $label;
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                }

                                                return $options;
                                            })
                                            ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                                                $itemName = trim((string) ($get('name') ?? ''));
                                                $variation = trim((string) ($state ?? ''));

                                                if ($itemName === '' || $variation === '') {
                                                    return;
                                                }

                                                if (SchemaFacade::hasTable('product_variations') && SchemaFacade::hasTable('products')) {
                                                    $productNameColumn = SchemaFacade::hasColumn('products', 'name')
                                                        ? 'name'
                                                        : (SchemaFacade::hasColumn('products', 'title') ? 'title' : null);

                                                    $variationLabelColumn = SchemaFacade::hasColumn('product_variations', 'title')
                                                        ? 'title'
                                                        : (SchemaFacade::hasColumn('product_variations', 'label')
                                                            ? 'label'
                                                            : (SchemaFacade::hasColumn('product_variations', 'name')
                                                                ? 'name'
                                                                : (SchemaFacade::hasColumn('product_variations', 'variation')
                                                                    ? 'variation'
                                                                    : (SchemaFacade::hasColumn('product_variations', 'strength') ? 'strength' : null))));

                                                    $productIdColumn = SchemaFacade::hasColumn('product_variations', 'product_id')
                                                        ? 'product_id'
                                                        : null;

                                                    if ($productNameColumn && $variationLabelColumn && $productIdColumn) {
                                                        $row = DB::table('product_variations')
                                                            ->join('products', "product_variations.{$productIdColumn}", '=', 'products.id')
                                                            ->where("products.{$productNameColumn}", $itemName)
                                                            ->where("product_variations.{$variationLabelColumn}", $variation)
                                                            ->select([
                                                                SchemaFacade::hasColumn('product_variations', 'price') ? 'product_variations.price as variation_price' : DB::raw('null as variation_price'),
                                                                SchemaFacade::hasColumn('product_variations', 'price_minor') ? 'product_variations.price_minor as variation_price_minor' : DB::raw('null as variation_price_minor'),
                                                            ])
                                                            ->first();

                                                        if ($row) {
                                                            if (isset($row->variation_price) && is_numeric($row->variation_price)) {
                                                                $set('unit_price', (float) $row->variation_price);
                                                                return;
                                                            }

                                                            if (isset($row->variation_price_minor) && is_numeric($row->variation_price_minor)) {
                                                                $set('unit_price', ((float) $row->variation_price_minor) / 100);
                                                                return;
                                                            }
                                                        }
                                                    }
                                                }

                                                $set('unit_price', null);
                                            })
                                            ->columnSpan(6),

                                        TextInput::make('qty')
                                            ->label('Qty')
                                            ->numeric()
                                            ->default(1)
                                            ->minValue(1)
                                            ->live()
                                            ->columnSpan(6),

                                        TextInput::make('unit_price')
                                            ->label('Unit price (£)')
                                            ->numeric()
                                            ->prefix('£')
                                            ->minValue(0)
                                            ->live()
                                            ->columnSpan(6),

                                        Placeholder::make('line_total')
                                            ->label(' ')
                                            ->content(function ($get) {
                                                $qty = (float) ($get('qty') ?: 0);
                                                $unit = (float) ($get('unit_price') ?: 0);
                                                return 'Line total: £' . number_format($qty * $unit, 2);
                                            })
                                            ->columnSpan(12),
                                    ]),
                            ])
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
