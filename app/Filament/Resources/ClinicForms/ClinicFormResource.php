<?php

namespace App\Filament\Resources\ClinicForms;

use App\Filament\Resources\ClinicForms\Pages\CreateClinicForm;
use App\Filament\Resources\ClinicForms\Pages\EditClinicForm;
use App\Filament\Resources\ClinicForms\Pages\ListClinicForms;
use App\Filament\Resources\ClinicForms\Pages\ViewClinicForm;
use App\Filament\Resources\ClinicForms\Schemas\ClinicFormForm;
use App\Filament\Resources\ClinicForms\Tables\ClinicFormsTable;
use App\Models\ClinicForm;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Tables;
use Filament\Actions;

class ClinicFormResource extends Resource
{
    protected static ?string $model = ClinicForm::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static ?string $recordTitleAttribute = 'name';
    protected static \UnitEnum|string|null $navigationGroup = 'Forms';
    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return ClinicFormForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Minimal view to avoid component/version mismatches.
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('name')
                    ->label('Title')
                    ->searchable()
                    ->sortable(),
    
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('d-m-Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d-m-Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('visibility')
                    ->options([
                        'public'   => 'Public',
                        'internal' => 'Internal',
                        'private'  => 'Private',
                    ]),
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Active')
                    ->nullable(),
            ])
            ->actionsColumnLabel('Operations')
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListClinicForms::route('/'),
            'create' => CreateClinicForm::route('/create'),
            'view'   => ViewClinicForm::route('/{record}'),
            'edit'   => EditClinicForm::route('/{record}/edit'),
        ];
    }
}