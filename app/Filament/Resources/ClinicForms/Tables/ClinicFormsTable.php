<?php

namespace App\Filament\Resources\ClinicForms\Tables;

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
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Title')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(60)
                    ->toggleable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('visibility')->badge()->sortable(),
                Tables\Columns\IconColumn::make('active')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Created')->since()->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('visibility')->options([
                    'public' => 'Public',
                    'internal' => 'Internal',
                    'private' => 'Private',
                ]),
                Tables\Filters\TernaryFilter::make('active')->nullable(),
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