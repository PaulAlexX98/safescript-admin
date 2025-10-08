<?php

namespace App\Filament\Resources\Services\Tables;

use Filament\Tables\Table;
use Filament\Tables;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('code')->badge()->copyable()->toggleable(),
                Tables\Columns\TextColumn::make('category')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('price')->money('gbp', true)->sortable(),
                Tables\Columns\IconColumn::make('active')->boolean()->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('category')->options([
                    'vaccination' => 'Vaccination',
                    'consultation' => 'Consultation',
                    'screening' => 'Screening',
                    'other' => 'Other',
                ]),
                Tables\Filters\TernaryFilter::make('active')->nullable(),
            ])
            ->actions([
                
            ])
            ->bulkActions([
         
            ]);
    }
}