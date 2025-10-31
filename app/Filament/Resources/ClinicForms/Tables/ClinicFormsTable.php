<?php

namespace App\Filament\Resources\ClinicForms\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\Tables;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteBulkAction;

class ClinicFormsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')
                    ->label('Title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Description')
                    ->limit(60)
                    ->toggleable()
                    ->wrap(),
                TextColumn::make('visibility')->badge()->sortable(),
                IconColumn::make('active')->boolean()->sortable(),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('visibility')->options([
                    'public' => 'Public',
                    'internal' => 'Internal',
                    'private' => 'Private',
                ]),
                TernaryFilter::make('active')->nullable(),
            ])
            ->recordActionsColumnLabel('Operations')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->groupedBulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}