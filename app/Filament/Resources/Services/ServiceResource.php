<?php

namespace App\Filament\Resources\Services;

use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use Filament\Resources\Resource;
use App\Models\ClinicForm;
use App\Models\Service;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Tables\Table;
use Filament\Tables;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-group';
    protected static \UnitEnum|string|null $navigationGroup = 'Operations';
    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            // Basic Information full width
            Section::make('Basic Information')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->live(onBlur: true)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),

            // Two-column row: Booking Flow + Forms Assignment (should match page width)
            Grid::make()->columns(2)->schema([
                Section::make('Booking Flow Steps')
                    ->schema(self::bookingFlowFields()),

                Section::make('Forms Assignment')
                    ->schema(self::formsAssignmentFields()),
            ])->columnSpanFull(),
        ]);
    }

    /**
     * Booking flow fields rendered inside the "Booking Flow Steps" section.
     */
    protected static function bookingFlowFields(): array
    {
        return [
            Grid::make()->columns(3)->schema([
                Forms\Components\Select::make('booking_flow.step1')
                    ->label('Step 1')
                    ->options(self::flowOptions())
                    ->default('treatments')
                    ->native(false)
                    ->placeholder('Select a step…'),

                Forms\Components\Select::make('booking_flow.step2')
                    ->label('Step 2')
                    ->options(self::flowOptions())
                    ->native(false)
                    ->placeholder('Select a step…'),

                Forms\Components\Select::make('booking_flow.step3')
                    ->label('Step 3')
                    ->options(self::flowOptions())
                    ->native(false)
                    ->placeholder('Select a step…'),

                Forms\Components\Select::make('booking_flow.step4')
                    ->label('Step 4')
                    ->options(self::flowOptions())
                    ->native(false)
                    ->placeholder('Select a step…'),

                Forms\Components\Select::make('booking_flow.step5')
                    ->label('Step 5')
                    ->options(self::flowOptions())
                    ->native(false)
                    ->placeholder('Select a step…'),

                Forms\Components\Select::make('booking_flow.step6')
                    ->label('Step 6')
                    ->options(self::flowOptions())
                    ->native(false)
                    ->placeholder('Select a step…'),
            ]),

            Forms\Components\Placeholder::make('flow_preview')
                ->label('Flow preview')
                ->content('Workflow preview coming soon…'),
        ];
    }

    /**
     * Form assignment fields rendered in the "Forms Assignment" section.
     */
    protected static function formsAssignmentFields(): array
    {
        return [
            Grid::make()->columns(2)->schema([
                // RAF Form  -> 'raf'
            Forms\Components\Select::make('raf_form_id')
                ->label('RAF Form')
                ->options(fn () => self::clinicFormOptionsByType('raf'))
                ->searchable()->preload()->native(false),

            // Consultation Advice Form -> 'advice'
            Forms\Components\Select::make('advice_form_id')
                ->label('Consultation Advice Form')
                ->options(fn () => self::clinicFormOptionsByType('advice'))
                ->searchable()->preload()->native(false),

            // Pharmacist Declaration Form -> 'declaration'
            Forms\Components\Select::make('pharmacist_declaration_form_id')
                ->label('Pharmacist Declaration Form')
                ->options(fn () => self::clinicFormOptionsByType('pharmacist_declaration'))
                ->searchable()->preload()->native(false),

            // Clinical Notes Form -> use 'clinical_notes'
            Forms\Components\Select::make('clinical_notes_form_id')
                ->label('Clinical Notes Form')
                ->options(fn () => self::clinicFormOptionsByType('clinical_notes'))
                ->searchable()->preload()->native(false),

            // Reorder Form -> keep 'reorder' (change only if you store a different type)
            Forms\Components\Select::make('reorder_form_id')
                ->label('Reorder Form')
                ->options(fn () => self::clinicFormOptionsByType('reorder'))
                ->searchable()->preload()->native(false),
            ]),
        ];
    }

    /**
     * Options for clinic form selects: [id => title]
     */
    private static function clinicFormOptions(): array
    {
        try {
            return ClinicForm::query()->orderBy('name')->pluck('name', 'id')->all();
        } catch (\Throwable $e) {
            // In case the table/migration isn't ready yet, fail gracefully
            return [];
        }
    }

    /**
     * Return [id => title] for ClinicForm records filtered by a given `type`.
     * Supports both a dedicated `type` column and JSON-based storage inside the `schema` column.
     * If the column or table is missing, fail gracefully with an empty array.
     */
    private static function extractFormType($form): ?string
    {
        // $form->schema may be array (cast) or JSON string or null
        $schema = is_array($form->schema)
            ? $form->schema
            : (json_decode($form->schema ?? '', true) ?: []);

        return strtolower(
            $form->form_type
            ?? ($schema['form_type'] ?? null)
            ?? ($schema['type'] ?? null)
            ?? data_get($schema, 'meta.type')
            ?? ''
        ) ?: null;
    }

    private static function clinicFormOptionsByType(string $type): array
    {
        try {
            $all = ClinicForm::query()
                ->select('id', 'name', 'form_type', 'schema')
                ->orderBy('name')
                ->get();

            $filtered = $all->filter(function ($f) use ($type) {
                return self::extractFormType($f) === strtolower($type);
            });

            return $filtered->pluck('name', 'id')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected static function flowOptions(): array
    {
        return [
            'treatments'  => 'Treatments',
            'login'    => 'Login',
            'raf'      => 'RAF Form',
            'calendar' => 'Calendar',
            'payment'  => 'Payment',
            'custom'   => 'Select a step',
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at','desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable()->sortable(),
                Tables\Columns\BadgeColumn::make('status')->colors(['success'=>'published','warning'=>'draft'])->sortable(),
                Tables\Columns\IconColumn::make('active')->boolean()->label('Active'),
                Tables\Columns\TextColumn::make('updated_at')->date('d-m-Y')->label('Updated')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->date('d-m-Y')->label('Created')->sortable(),
                Tables\Columns\TextColumn::make('view_link')
                    ->label('View')
                    ->formatStateUsing(fn () => 'View')
                    ->url(fn ($record) => $record?->slug ? url("/private-services/{$record->slug}") : null)
                    ->openUrlInNewTab()
                    ->visible(fn ($record) => filled($record?->slug))
                    ->icon('heroicon-m-arrow-top-right-on-square'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Active'),
                Tables\Filters\SelectFilter::make('status')->options(['draft'=>'Draft','published'=>'Published']),
            ])
            ->recordUrl(fn ($record) => static::getUrl('edit', ['record' => $record]));
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\Services\RelationManagers\ProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit'   => EditService::route('/{record}/edit'),
        ];
    }
}