<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\Pages\Pages;
use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Models\Page;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;   // v4 uses Schema here
use Filament\Tables;
use Filament\Actions;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static \UnitEnum|string|null $navigationGroup = 'Front';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $recordTitleAttribute = 'title';
    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return PageForm::configure($schema);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => url('/private-services/' . $record->slug))
                    ->openUrlInNewTab(),
                Tables\Columns\TextColumn::make('template')->badge()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Created At')->date('d-m-Y')->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => fn ($state) => $state === 'draft',
                        'success' => fn ($state) => $state === 'published',
                    ])
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->label('Status')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'draft' => 'Draft', 'published' => 'Published',
                ]),
            ])
            ->actionsColumnLabel('Operations')
            ->actions([
                Actions\Action::make('view')
                    ->label('View')
                    ->url(fn ($record) => url('/private-services/' . $record->slug))
                    ->openUrlInNewTab(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }
}