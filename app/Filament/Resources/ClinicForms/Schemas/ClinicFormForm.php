<?php

namespace App\Filament\Resources\ClinicForms\Schemas;

use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;

class ClinicFormForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(12) // 8/4 layout
            ->schema([
                // ===== LEFT COLUMN (8) =====
                \Filament\Schemas\Components\Section::make()
                    ->schema([
                        Builder::make('schema')
                            ->label('Form canvas')
                            ->addActionLabel('Add to form structure')
        
                            ->blocks([
                                // Inputs
                                Block::make('text_input')->label('Text Input')->schema([
                                    Forms\Components\TextInput::make('label')->label('Field Label'),
                                    Forms\Components\TextInput::make('placeholder')->label('Placeholder Text'),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                    Forms\Components\Toggle::make('hidden')->label('Hidden')->default(false),
                                    Forms\Components\Toggle::make('disabled')->label('Disabled')->default(false),
                                ]),
                                Block::make('email')->label('Email')->schema([
                                    Forms\Components\TextInput::make('label')->label('Field Label'),
                                    Forms\Components\TextInput::make('placeholder')->label('Placeholder Text'),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                ]),
                                Block::make('number')->label('Number')->schema([
                                    Forms\Components\TextInput::make('label')->label('Field Label'),
                                    Forms\Components\TextInput::make('placeholder')->label('Placeholder Text'),
                                    Forms\Components\TextInput::make('min')->numeric()->label('Min'),
                                    Forms\Components\TextInput::make('max')->numeric()->label('Max'),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                ]),
                                Block::make('date')->label('Date')->schema([
                                    Forms\Components\TextInput::make('label')->label('Field Label'),
                                    Forms\Components\DatePicker::make('date')->label('Select Date')->native(false)->displayFormat('d-m-Y'),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                ]),
                                // Choices
                                Block::make('select')->label('Select')->schema([
                                    Forms\Components\TextInput::make('label')->label('Field Label'),
                                    Forms\Components\Repeater::make('options')->label('Options')->schema([
                                        Forms\Components\TextInput::make('value')->label('Value')->required(),
                                        Forms\Components\TextInput::make('label')->label('Label'),
                                    ])->addActionLabel('Add option')->reorderable()->minItems(1),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                ]),
                                Block::make('radio')->label('Radio Buttons')->schema([
                                    Forms\Components\TextInput::make('label')->label('Field Label'),
                                    Forms\Components\Repeater::make('options')->label('Options')->schema([
                                        Forms\Components\TextInput::make('value')->label('Value')->required(),
                                        Forms\Components\TextInput::make('label')->label('Label')->required(),
                                    ])->addActionLabel('Add option')->reorderable()->minItems(1),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                ]),
                                Block::make('checkbox')->label('Checkbox')->schema([
                                    Forms\Components\TextInput::make('label')->label('Label'),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                ]),
                                // Uploads & Signature
                                Block::make('file_upload')->label('File Upload')->schema([
                                    Forms\Components\TextInput::make('label')->label('Field Label'),
                                    Forms\Components\Toggle::make('multiple')->label('Allow multiple')->default(false),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                ]),
                                Block::make('signature')->label('Signature')->schema([
                                    // Live signature preview canvas (mouse / touch drawing)
                                    Forms\Components\TextInput::make('label')->label('Field Label'),
                                    Forms\Components\ViewField::make('signature_pad')
                                        ->view('forms.components.signature-pad'),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2)->default('Draw your signature above'),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(true),
                                ]),
                                // Content
                                Block::make('text_block')->label('Text Block')->schema([
                                    Forms\Components\RichEditor::make('content')
                                        ->label('Content')
                                        ->toolbarButtons([
                                            'bold',
                                            'italic',
                                            'underline',
                                            'strike',
                                            'h2',
                                            'h3',
                                            'blockquote',
                                            'bulletList',
                                            'orderedList',
                                            'link',
                                            'undo',
                                            'redo',
                                        ])
                                        ->required(),
                                    Forms\Components\Select::make('align')->options([
                                        'left' => 'Left',
                                        'center' => 'Center',
                                        'right' => 'Right',
                                    ])->default('left')->label('Alignment'),
                                ]),
                                Block::make('divider')->label('Divider')->schema([]),
                                Block::make('image')->label('Image')->schema([
                                    Forms\Components\FileUpload::make('image')->image()->directory('clinic-forms/blocks')->required(),
                                    Forms\Components\TextInput::make('alt')->label('Alt text'),
                                ]),
                                Block::make('page_break')->label('Page Break')->schema([]),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->compact()
                    ->columnSpan(8),

                // ===== RIGHT COLUMN (4) =====
                \Filament\Schemas\Components\Section::make()
                    ->schema([
                        \Filament\Schemas\Components\Section::make('Information')
                            ->collapsible()
                            ->collapsed(false)
                            ->schema([
                                Forms\Components\TextInput::make('name')->label('Form title')->required(),
                                Forms\Components\Textarea::make('description')->label('Description')->rows(3),
                            ]),
                        \Filament\Schemas\Components\Section::make('Form Structure')
                            ->collapsible()
                            ->collapsed(false)
                            ->schema([
                                Forms\Components\Placeholder::make('structure_help')
                                    ->label('Structure')
                                    ->hiddenLabel()
                                    ->content(function (Get $get) {
                                        $blocks = $get('schema') ?? [];
                                        if (! is_array($blocks) || empty($blocks)) {
                                            return 'No fields yet.';
                                        }
                                    
                                        $lines = [];
                                        $n = 1;
                                        foreach (array_values($blocks) as $block) {
                                            $type = \Illuminate\Support\Arr::get($block, 'type', 'field');
                                            $label = trim((string) \Illuminate\Support\Arr::get($block, 'data.label', ''));
                                            if ($label === '') {
                                                $label = \Illuminate\Support\Str::headline((string) $type) . ' ' . $n;
                                            }
                                            $isReq = (bool) \Illuminate\Support\Arr::get($block, 'data.required', false);
                                            $reqBadge = $isReq ? ' <span style="display:inline-block;padding:.05rem .35rem;border-radius:.25rem;background:rgba(239,68,68,.12);color:#f87171;font-weight:600;margin-left:.35rem;">required</span>' : '';
                                            $lines[] = $n . '. ' . e($label) . $reqBadge . ' <small style="opacity:.7">(' . e((string) $type) . ')</small>';
                                            $n++;
                                        }
                                    
                                        return new \Illuminate\Support\HtmlString(implode('<br>', $lines));
                                    }),
                            ]),
                    ])
                    ->columns(1)
                    ->compact()
                    ->columnSpan(4),
            ]);
    }
}
