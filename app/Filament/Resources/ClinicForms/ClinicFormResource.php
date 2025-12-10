<?php

namespace App\Filament\Resources\ClinicForms;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\ClinicForms\Pages\CreateClinicForm;
use App\Filament\Resources\ClinicForms\Pages\EditClinicForm;
use App\Filament\Resources\ClinicForms\Pages\ListClinicForms;
use App\Filament\Resources\ClinicForms\Pages\ViewClinicForm;
use App\Filament\Resources\ClinicForms\Pages\RafBuilder;
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

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static ?string $recordTitleAttribute = 'name';
    protected static string | \UnitEnum | null $navigationGroup = 'Forms';
    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return ClinicFormForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Minimal view to avoid component/version mismatches.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(function ($record) {
                return ($record->type === 'raf')
                    ? RafBuilder::getUrl(['record' => $record])
                    : EditClinicForm::getUrl(['record' => $record]);
            })
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('name')
                    ->label('Title')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('form_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('d-m-Y')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d-m-Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('visibility')
                    ->options([
                        'public'   => 'Public',
                        'internal' => 'Internal',
                        'private'  => 'Private',
                    ]),
                TernaryFilter::make('active')
                    ->label('Active')
                    ->nullable(),
            ])
            ->recordActionsColumnLabel('Operations')
            ->recordActions([
                EditAction::make(),
                Action::make('raf')
                    ->label('RAF')
                    ->icon('heroicon-m-wrench-screwdriver')
                    ->url(fn ($record) => RafBuilder::getUrl(['record' => $record]))
                    ->visible(fn ($record) => ($record->type ?? null) === 'raf'),
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-m-square-2-stack')
                    ->requiresConfirmation()
                    ->action(function (ClinicForm $record): void {
                        $copy = $record->replicate();

                        // Prefix the name so it is clear this is a copy
                        if (is_string($copy->name ?? null) && trim($copy->name) !== '') {
                            $copy->name = trim('Copy of ' . $copy->name);
                        }

                        // Persist the new record (keeps raf_version and raf_status identical to the source)
                        $copy->push();

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Form duplicated')
                            ->body('A copy of this form has been created.')
                            ->send();
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
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
            'raf-builder' => RafBuilder::route('/{record}/raf'),
        ];
    }
}