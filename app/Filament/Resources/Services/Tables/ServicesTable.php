<?php

namespace App\Filament\Resources\Services\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\Tables;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->badge()->copyable()->toggleable(),
                TextColumn::make('category')->sortable()->toggleable(),
                TextColumn::make('price')->money('gbp', true)->sortable(),
                IconColumn::make('active')->boolean()->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('category')->options([
                    'vaccination' => 'Vaccination',
                    'consultation' => 'Consultation',
                    'screening' => 'Screening',
                    'other' => 'Other',
                ]),
                TernaryFilter::make('active')->nullable(),
            ])
            ->recordActions([
                
            ])
            ->toolbarActions([
         
            ]);
    }
}