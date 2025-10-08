<?php

namespace App\Filament\Resources\ClinicForms\Schemas;

use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\Grid as InfoGrid;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\Str;

class ClinicFormInfolist
{
    public static function build(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make('Overview')
                ->schema([
                    InfoGrid::make(2)->schema([
                        TextEntry::make('name')
                            ->label('Title'),
                        TextEntry::make('visibility')
                            ->label('Visibility')
                            ->badge(),
                    ]),
                ])
                ->collapsible(),

            InfoSection::make('Form Structure')
                ->schema([
                    TextEntry::make('schema_count')
                        ->label('Total fields')
                        ->state(function ($record) {
                            $s = $record->schema;
                            return is_array($s) ? count($s) : 0;
                        }),
                    TextEntry::make('schema_preview')
                        ->label('Fields')
                        ->state(function ($record) {
                            $s = $record->schema;
                            if (! is_array($s) || empty($s)) {
                                return 'No fields added yet.';
                            }
                            $lines = [];
                            $n = 1;
                            foreach ($s as $block) {
                                $type = Str::headline((string) ($block['type'] ?? 'field'));
                                $label = trim((string) ($block['data']['label'] ?? ''));
                                $lines[] = $n . '. ' . $type . ' • ' . ($label !== '' ? $label : '—');
                                $n++;
                            }
                            return implode("<br>", $lines);
                        })
                        ->html()
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed(false),
        ]);
    }
}