<?php

namespace App\Filament\Resources\PatientEmailTemplates;

use App\Filament\Resources\PatientEmailTemplates\Pages;
use App\Models\PatientEmailTemplate;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class PatientEmailTemplateResource extends Resource
{
    protected static ?string $model = PatientEmailTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';
    protected static ?string $navigationLabel = 'Email Templates';
    protected static ?string $modelLabel = 'Email Template';
    protected static ?string $pluralModelLabel = 'Email Templates';
    protected static string|\UnitEnum|null $navigationGroup = 'Notifications';
    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Template name')
                ->required()
                ->maxLength(120),
            TextInput::make('subject')
                ->required()
                ->maxLength(200)
                ->helperText('Variables: {{patient_name}}, {{reference}}, {{service_name}}, {{pharmacy_name}}, {{booking_link}}'),
            Textarea::make('message')
                ->rows(12)
                ->required()
                ->maxLength(10000)
                ->helperText('Variables: {{patient_name}}, {{reference}}, {{service_name}}, {{pharmacy_name}}, {{booking_link}}'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('subject')->searchable()->limit(70),
                Tables\Columns\TextColumn::make('updated_at')->label('Last updated')->dateTime('d M Y, H:i')->sortable(),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPatientEmailTemplates::route('/'),
            'create' => Pages\CreatePatientEmailTemplate::route('/create'),
            'edit' => Pages\EditPatientEmailTemplate::route('/{record}/edit'),
        ];
    }
}
