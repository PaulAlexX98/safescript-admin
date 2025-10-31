<?php

namespace App\Filament\Resources\Pages\Tables;

use Filament\Forms\Components\RichEditor;
use Filament\Forms;

class PagesTable
{
    public static function schema(): array
    {
        return [
            // Other form fields...

            RichEditor::make('content')
                ->label('Content')
                ->extraAttributes([
                    'style' => 'height: 420px; overflow: auto;',
                ])
                ->helperText('Main page content.'),

            // Other form fields...
        ];
    }
}
