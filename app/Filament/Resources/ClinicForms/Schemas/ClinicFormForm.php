<?php

namespace App\Filament\Resources\ClinicForms\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use RuntimeException;
use Throwable;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
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
use Filament\Notifications\Notification;

class ClinicFormForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(12) // 8/4 layout
            ->components([
                // ===== LEFT COLUMN (8) =====
                Section::make()
                    ->schema([
                        Builder::make('schema')
                            ->label('Form canvas')
                            ->addActionLabel('Add to form structure')
        
                            ->blocks([
                                // Sections
                                Block::make('section')->label('Section')->schema([
                                    TextInput::make('label')
                                        ->label('Section title')
                                        ->reactive(),
                                    Textarea::make('summary')->label('Summary (optional)')->rows(2),
                                    TextInput::make('key')
                                        ->label('Section key')
                                        ->helperText('All fields after this block belong to this section until the next Section block')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? Str::slug($lbl) : null;
                                        }),
                                    Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                // Inputs
                                Block::make('text_input')->label('Text Input')->schema([
                                    TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive(),
                                    TextInput::make('placeholder')->label('Placeholder Text'),
                                    Textarea::make('help')->label('Help Text')->rows(2),
                                    Toggle::make('required')->label('Required')->default(false),
                                    Toggle::make('hidden')->label('Hidden')->default(false),
                                    Toggle::make('disabled')->label('Disabled')->default(false),
                                    TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? Str::slug($lbl) : null;
                                        }),
                                    Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('email')->label('Email')->schema([
                                    TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive(),
                                    TextInput::make('placeholder')->label('Placeholder Text'),
                                    Textarea::make('help')->label('Help Text')->rows(2),
                                    Toggle::make('required')->label('Required')->default(false),
                                    TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? Str::slug($lbl) : null;
                                        }),
                                    Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('number')->label('Number')->schema([
                                    TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive(),
                                    TextInput::make('placeholder')->label('Placeholder Text'),
                                    TextInput::make('min')->numeric()->label('Min'),
                                    TextInput::make('max')->numeric()->label('Max'),
                                    Textarea::make('help')->label('Help Text')->rows(2),
                                    Toggle::make('required')->label('Required')->default(false),
                                    TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? Str::slug($lbl) : null;
                                        }),
                                    Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('textarea')->label('Textarea')->schema([
                                    TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive(),
                                    Textarea::make('placeholder')->label('Placeholder Text')->rows(2),
                                    Textarea::make('help')->label('Help Text')->rows(2),
                                    Toggle::make('required')->label('Required')->default(false),
                                    Toggle::make('hidden')->label('Hidden')->default(false),
                                    Toggle::make('disabled')->label('Disabled')->default(false),
                                    TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? Str::slug($lbl) : null;
                                        }),
                                    Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('date')->label('Date')->schema([
                                    TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive(),
                                    TextInput::make('placeholder')->label('Placeholder Text')->default('dd-mm-yyyy'),
                                    Textarea::make('help')->label('Help Text')->rows(2),
                                    Toggle::make('required')->label('Required')->default(false),
                                    TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? Str::slug($lbl) : null;
                                        }),
                                    Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                // Choices
                                Block::make('select')->label('Select')->schema([
                                    TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive(),
                                    Repeater::make('options')->label('Options')->schema([
                                        TextInput::make('value')->label('Value')->required(),
                                        TextInput::make('label')->label('Label'),
                                    ])->addActionLabel('Add option')->reorderable()->default([]),
                                    Toggle::make('multiple')->label('Allow multiple')->default(false),
                                    Toggle::make('required')->label('Required')->default(false),
                                    Textarea::make('help')->label('Help Text')->rows(2),
                                    TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? Str::slug($lbl) : null;
                                        }),
                                    Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('radio')->label('Radio Buttons')->schema([
                                    TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive()
                                        ->afterStateUpdated(function ($set, $state) {
                                            $set('key', Str::slug((string) $state));
                                        }),
                                    Repeater::make('options')->label('Options')->schema([
                                        TextInput::make('value')->label('Value')->required(),
                                        TextInput::make('label')->label('Label')->required(),
                                    ])->addActionLabel('Add option')->reorderable()->default([]),
                                    Toggle::make('required')->label('Required')->default(false),
                                    Textarea::make('help')->label('Help Text')->rows(2),
                                    TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? Str::slug($lbl) : null;
                                        }),
                                    Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('checkbox')->label('Checkbox')->schema([
                                    TextInput::make('label')
                                        ->label('Label')
                                        ->reactive(),
                                    Textarea::make('help')->label('Help Text')->rows(2),
                                    Toggle::make('required')->label('Required')->default(false),
                                    TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? Str::slug($lbl) : null;
                                        }),
                                    Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                // Uploads & Signature
                                Block::make('file_upload')->label('File Upload')->schema([
                                    TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive(),
                                    Toggle::make('multiple')->label('Allow multiple')->default(false),
                                    Toggle::make('required')->label('Required')->default(false),
                                    Textarea::make('help')->label('Help Text')->rows(2),
                                    TextInput::make('accept')
                                        ->label('Allowed file types')
                                        ->placeholder('image/*,application/pdf')
                                        ->helperText('Comma-separated, e.g. application/pdf,image/*')
                                        ->default('image/*,application/pdf'),
                                    TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? Str::slug($lbl) : null;
                                        }),
                                    Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('signature')->label('Signature')->schema([
                                    // Live signature preview canvas (mouse / touch drawing)
                                    TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive(),
                                    ViewField::make('signature_pad')
                                        ->view('forms.components.signature-pad'),
                                    Textarea::make('help')->label('Help Text')->rows(2)->default('Draw your signature above'),
                                    Toggle::make('required')->label('Required')->default(true),
                                    TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? Str::slug($lbl) : null;
                                        }),
                                    Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                // Content
                                Block::make('text_block')->label('Text Block')->schema([
                                    TextInput::make('label')
                                        ->label('Block label')
                                        ->reactive(),
                                    RichEditor::make('content')
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
                                        ->required()
                                        ->afterStateHydrated(function ($component, $state) {
                                            if (is_string($state)) {
                                                $html = trim($state);
                                                if ($html === '') {
                                                    $component->state(null);
                                                    return;
                                                }
                                                // Minimal HTML → TipTap doc conversion for headings, paragraphs, and lists
                                                $toDoc = function (string $html) {
                                                    $buildText = function (string $text) {
                                                        $txt = trim(preg_replace('/\s+/u', ' ', $text));
                                                        return $txt === '' ? null : [['type' => 'text', 'text' => $txt]];
                                                    };
                                                    $content = [];
                                                    if (class_exists(\DOMDocument::class)) {
                                                        libxml_use_internal_errors(true);
                                                        $dom = new \DOMDocument();
                                                        $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
                                                        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                                                        $body = $dom->getElementsByTagName('body')->item(0);
                                                        if ($body) {
                                                            foreach (iterator_to_array($body->childNodes) as $node) {
                                                                if ($node->nodeType !== XML_ELEMENT_NODE) {
                                                                    $t = trim($node->textContent ?? '');
                                                                    if ($t !== '') {
                                                                        $c = $buildText($t);
                                                                        if ($c) $content[] = ['type' => 'paragraph', 'content' => $c];
                                                                    }
                                                                    continue;
                                                                }
                                                                $tag = strtolower($node->nodeName);
                                                                switch ($tag) {
                                                                    case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6': {
                                                                        $level = (int) substr($tag, 1);
                                                                        $c = $buildText($node->textContent ?? '');
                                                                        if ($c) $content[] = ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => $c];
                                                                        break;
                                                                    }
                                                                    case 'ul':
                                                                    case 'ol': {
                                                                        $items = [];
                                                                        foreach (iterator_to_array($node->childNodes) as $li) {
                                                                            if (strtolower($li->nodeName) !== 'li') continue;
                                                                            $c = $buildText($li->textContent ?? '');
                                                                            if (! $c) continue;
                                                                            $items[] = ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => $c]]];
                                                                        }
                                                                        if ($items) $content[] = ['type' => $tag === 'ol' ? 'orderedList' : 'bulletList', 'content' => $items];
                                                                        break;
                                                                    }
                                                                    case 'p': default: {
                                                                        $c = $buildText($node->textContent ?? '');
                                                                        if ($c) $content[] = ['type' => 'paragraph', 'content' => $c];
                                                                        break;
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        libxml_clear_errors();
                                                    }
                                                    if (! $content) {
                                                        $c = $buildText(strip_tags($html));
                                                        if ($c) $content[] = ['type' => 'paragraph', 'content' => $c];
                                                    }
                                                    return ['type' => 'doc', 'content' => $content ?: [['type' => 'paragraph']]];
                                                };
                                                $component->state($toDoc($html));
                                                return;
                                            }
                                        }),
                                    Select::make('align')->options([
                                        'left' => 'Left',
                                        'center' => 'Center',
                                        'right' => 'Right',
                                    ])->default('left')->label('Alignment'),
                                    TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? Str::slug($lbl) : null;
                                        }),
                                    Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('divider')->label('Divider')->schema([]),
                                Block::make('image')->label('Image')->schema([
                                    TextInput::make('label')
                                        ->label('Block label')
                                        ->reactive(),
                                    FileUpload::make('image')->image()->directory('clinic-forms/blocks')->required(),
                                    TextInput::make('alt')->label('Alt text'),
                                    TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? Str::slug($lbl) : null;
                                        }),
                                    Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('page_break')->label('Page Break')->schema([]),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->compact()
                    ->columnSpan(8),

                // ===== RIGHT COLUMN (4) =====
                Section::make()
                    ->schema([
                        Section::make('Information')
                            ->collapsible()
                            ->collapsed(false)
                            ->schema([
                                TextInput::make('name')->label('Form title')->required(),
                                Select::make('form_type')
                                    ->label('Form type')
                                    ->options([
                                        'raf' => 'RAF',
                                        'advice' => 'Consultation Advice',
                                        'pharmacist_declaration' => 'Pharmacist Declaration',
                                        'clinical_notes' => 'Clinical Notes',
                                        'reorder' => 'Reorder Form',
                                    ])
                                    ->required()
                                    ->native(false)
                                    ->helperText('Choose what kind of clinic form this is.'),
                                TextInput::make('service_slug')
                                    ->label('Service slug')
                                    ->placeholder('travel-clinic')
                                    ->helperText('Optional. Leave blank to make this form global. Use lowercase hyphenated slug'),
                                TextInput::make('treatment_slug')
                                    ->label('Treatment slug')
                                    ->placeholder('dengue-fever')
                                    ->helperText('Optional. Leave blank to apply to any treatment in the service'),
                                Textarea::make('description')->label('Description')->rows(3),
                            ]),
                        Section::make('Form Structure')
                            ->collapsible()
                            ->collapsed(false)
                            ->headerActions([
                                Action::make('import_json')
                                    ->label('Import JSON')
                                    ->icon('heroicon-o-arrow-down-tray')
                                    ->modalHeading('Import form from JSON')
                                    ->modalSubmitActionLabel('Import')
                                    ->schema([
                                        Textarea::make('json')
                                            ->label('Paste JSON here')
                                            ->rows(14)
                                            ->required()
                                            ->helperText('Paste the exported array (e.g. travelClinicRafForm).'),
                                    ])
                                    ->action(function (array $data, Set $set) {
                                        try {
                                            $raw = $data['json'] ?? '';
                                            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

                                            // Accept either an array of sections or direct fields
                                            $sections = [];
                                            if (is_array($decoded)) {
                                                if (isset($decoded[0]['fields'])) {
                                                    $sections = $decoded; // sections array
                                                } else {
                                                    $sections = [[
                                                        'id' => 'import', 'title' => 'Imported', 'fields' => $decoded,
                                                    ]]; // fields array
                                                }
                                            }

                                            $blocks = [];
                                            $slug = function (string $s) {
                                                $s = strtolower(trim($s));
                                                $s = preg_replace('/[^a-z0-9]+/', '-', $s);
                                                return trim($s, '-');
                                            };
                                            $toDocFromHtml = function ($input) {
                                                if ($input === null) return null;
                                                if (is_array($input)) return $input; // already a doc structure

                                                $html = trim((string) $input);
                                                if ($html === '') return null;

                                                $buildText = function (string $text) {
                                                    $txt = trim(preg_replace('/\s+/u', ' ', $text));
                                                    return $txt === '' ? null : [['type' => 'text', 'text' => $txt]];
                                                };

                                                $docContent = [];

                                                if (class_exists(\DOMDocument::class)) {
                                                    libxml_use_internal_errors(true);
                                                    $dom = new \DOMDocument();
                                                    $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
                                                    $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                                                    $body = $dom->getElementsByTagName('body')->item(0);
                                                    if ($body) {
                                                        foreach (iterator_to_array($body->childNodes) as $node) {
                                                            if ($node->nodeType !== XML_ELEMENT_NODE) {
                                                                $text = trim($node->textContent ?? '');
                                                                if ($text !== '') {
                                                                    $content = $buildText($text);
                                                                    if ($content) $docContent[] = ['type' => 'paragraph', 'content' => $content];
                                                                }
                                                                continue;
                                                            }
                                                            $tag = strtolower($node->nodeName);
                                                            switch ($tag) {
                                                                case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6': {
                                                                    $level = (int) substr($tag, 1);
                                                                    $content = $buildText($node->textContent ?? '');
                                                                    if ($content) {
                                                                        $docContent[] = [
                                                                            'type' => 'heading',
                                                                            'attrs' => ['level' => $level],
                                                                            'content' => $content,
                                                                        ];
                                                                    }
                                                                    break;
                                                                }
                                                                case 'ul':
                                                                case 'ol': {
                                                                    $items = [];
                                                                    foreach (iterator_to_array($node->childNodes) as $li) {
                                                                        if (strtolower($li->nodeName) !== 'li') continue;
                                                                        $content = $buildText($li->textContent ?? '');
                                                                        if (! $content) continue;
                                                                        $items[] = [
                                                                            'type' => 'listItem',
                                                                            'content' => [[
                                                                                'type' => 'paragraph',
                                                                                'content' => $content,
                                                                            ]],
                                                                        ];
                                                                    }
                                                                    if ($items) {
                                                                        $docContent[] = [
                                                                            'type' => $tag === 'ol' ? 'orderedList' : 'bulletList',
                                                                            'content' => $items,
                                                                        ];
                                                                    }
                                                                    break;
                                                                }
                                                                case 'p': {
                                                                    $content = $buildText($node->textContent ?? '');
                                                                    if ($content) $docContent[] = ['type' => 'paragraph', 'content' => $content];
                                                                    break;
                                                                }
                                                                case 'br': {
                                                                    // ignore top-level breaks
                                                                    break;
                                                                }
                                                                default: {
                                                                    $content = $buildText($node->textContent ?? '');
                                                                    if ($content) $docContent[] = ['type' => 'paragraph', 'content' => $content];
                                                                    break;
                                                                }
                                                            }
                                                        }
                                                    }
                                                    libxml_clear_errors();
                                                }

                                                if (! $docContent) {
                                                    $content = $buildText(strip_tags($html));
                                                    if ($content) $docContent[] = ['type' => 'paragraph', 'content' => $content];
                                                }

                                                return [
                                                    'type' => 'doc',
                                                    'content' => $docContent ?: [['type' => 'paragraph']],
                                                ];
                                            };
                                            $normValue = function ($v) use ($slug) {
                                                if (is_bool($v)) {
                                                    return $v ? 'yes' : 'no';
                                                }
                                                $s = strtolower(trim((string) $v));
                                                if ($s === '') return $s;
                                                if (in_array($s, ['yes','y','true','1'], true)) return 'yes';
                                                if (in_array($s, ['no','n','false','0'], true)) return 'no';
                                                return $slug($s);
                                            };
                                            $normalizeShowIf = function ($showIf) use ($normValue) {
                                                if (!is_array($showIf)) return null;
                                                $out = ['enabled' => true];
                                                if (isset($showIf['field'])) {
                                                    $out['field'] = trim((string) $showIf['field']);
                                                }
                                                if (array_key_exists('equals', $showIf)) {
                                                    $out['equals'] = $normValue($showIf['equals']);
                                                }
                                                if (array_key_exists('notEquals', $showIf)) {
                                                    $out['notEquals'] = $normValue($showIf['notEquals']);
                                                }
                                                if (isset($showIf['in'])) {
                                                    $vals = array_values(array_filter(array_map($normValue, (array) $showIf['in']), fn ($x) => $x !== ''));
                                                    if ($vals) $out['in'] = array_unique($vals);
                                                }
                                                if (isset($showIf['truthy'])) {
                                                    $out['truthy'] = (bool) $showIf['truthy'];
                                                }
                                                return $out;
                                            };
                                            $sectionIndex = 1;

                                            foreach ($sections as $section) {
                                                $fields = $section['fields'] ?? [];
                                                if (!is_array($fields)) continue;

                                                $sectionTitle = (string)($section['title'] ?? $section['name'] ?? ('Section ' . $sectionIndex));
                                                $sectionKey = $slug($sectionTitle);

                                                // Insert a Section header block
                                                $blocks[] = [
                                                    'type' => 'section',
                                                    'data' => [
                                                        'label' => $sectionTitle,
                                                        'key' => $sectionKey,
                                                        'summary' => $section['summary'] ?? ($section['description'] ?? null),
                                                    ],
                                                ];

                                                foreach ($fields as $f) {
                                                    $typeRaw = $f['type'] ?? 'text_input';
                                                    $type = strtolower(str_replace(['-', ' '], ['_', '_'], (string) $typeRaw));
                                                    // map common aliases
                                                    if (in_array($type, ['toggle','switch','boolean'], true)) {
                                                        $type = 'checkbox';
                                                    } elseif (in_array($type, ['yes_no','yesno'], true)) {
                                                        $type = 'radio';
                                                        if (empty($f['options'])) {
                                                            $f['options'] = [
                                                                ['value' => 'yes', 'label' => 'Yes'],
                                                                ['value' => 'no',  'label' => 'No'],
                                                            ];
                                                        }
                                                    }
                                                    $label = $f['label'] ?? ($f['id'] ?? ucfirst($type));
                                                    $required = (bool)($f['required'] ?? false);
                                                    $help = $f['help'] ?? null;

                                                    switch ($type) {
                                                        case 'select':
                                                        case 'multiselect': {
                                                            $options = [];
                                                            foreach ((array) ($f['options'] ?? []) as $opt) {
                                                                if (is_array($opt)) {
                                                                    $lab = (string) ($opt['label'] ?? ($opt['value'] ?? ''));
                                                                    $val = (string) ($opt['value'] ?? $lab);
                                                                } else {
                                                                    $lab = (string) $opt;
                                                                    $val = (string) $lab;
                                                                }
                                                                $val = $normValue($val);
                                                                if ($val !== '') {
                                                                    $options[] = ['value' => $val, 'label' => $lab];
                                                                }
                                                            }
                                                            $blocks[] = [
                                                                'type' => 'select',
                                                                'data' => [
                                                                    'label'    => $label,
                                                                    'key'      => $slug($f['key'] ?? $label),
                                                                    'section'  => $sectionKey,
                                                                    'options'  => $options,
                                                                    'multiple' => (bool) ($f['multiple'] ?? ($type === 'multiselect')),
                                                                    'required' => $required,
                                                                    'help'     => $help,
                                                                    'showIf'   => $normalizeShowIf($f['showIf'] ?? null),
                                                                ],
                                                            ];
                                                            break;
                                                        }

                                                        case 'radio': {
                                                            $options = [];
                                                            foreach ((array) ($f['options'] ?? []) as $opt) {
                                                                if (is_array($opt)) {
                                                                    $lab = (string) ($opt['label'] ?? ($opt['value'] ?? ''));
                                                                    $val = (string) ($opt['value'] ?? $lab);
                                                                } else {
                                                                    $lab = (string) $opt;
                                                                    $val = (string) $lab;
                                                                }
                                                                $val = $normValue($val);
                                                                if ($val !== '') {
                                                                    $options[] = ['value' => $val, 'label' => $lab];
                                                                }
                                                            }
                                                            $blocks[] = [
                                                                'type' => 'radio',
                                                                'data' => [
                                                                    'label'    => $label,
                                                                    'key'      => $slug($f['key'] ?? $label),
                                                                    'section'  => $sectionKey,
                                                                    'options'  => $options,
                                                                    'required' => $required,
                                                                    'help'     => $help,
                                                                    'showIf'   => $normalizeShowIf($f['showIf'] ?? null),
                                                                ],
                                                            ];
                                                            // Optional "details" textarea tied to selected radio values
                                                            if (!empty($f['details']['label'] ?? null)) {
                                                                $parentKey = $slug($f['key'] ?? $label);
                                                                $rawShow = $f['details']['showIfIn'] ?? null;
                                                                if ($rawShow) {
                                                                    $showVals = array_map($normValue, (array) $rawShow);
                                                                } else {
                                                                    // infer: if a Yes/No exists, default to showing on "yes"
                                                                    $hasYes = in_array('yes', array_map(fn ($o) => strtolower($o['value']), $options), true);
                                                                    $showVals = $hasYes ? ['yes'] : (isset($options[0]['value']) ? [$options[0]['value']] : []);
                                                                }
                                                                $blocks[] = [
                                                                    'type' => 'textarea',
                                                                    'data' => [
                                                                        'label'       => (string) $f['details']['label'],
                                                                        'key'         => $slug((string) ($f['details']['key'] ?? $f['details']['label'])),
                                                                        'section'     => $sectionKey,
                                                                        'placeholder' => $f['details']['placeholder'] ?? null,
                                                                        'help'        => $showVals ? ('Shown if selected: ' . implode(', ', $showVals)) : null,
                                                                        'showIf'      => ['field' => $parentKey, 'in' => $showVals],
                                                                    ],
                                                                ];
                                                            }
                                                            break;
                                                        }

                                                        case 'checkbox': {
                                                            $blocks[] = [
                                                                'type' => 'checkbox',
                                                                'data' => [
                                                                    'label'    => $label,
                                                                    'key'      => $slug($f['key'] ?? $label),
                                                                    'section'  => $sectionKey,
                                                                    'required' => $required,
                                                                    'help'     => $help,
                                                                    'showIf'   => $normalizeShowIf($f['showIf'] ?? null),
                                                                ],
                                                            ];
                                                            break;
                                                        }

                                                        case 'date': {
                                                            $blocks[] = [
                                                                'type' => 'date',
                                                                'data' => [
                                                                    'label'    => $label,
                                                                    'key'      => $slug($f['key'] ?? $label),
                                                                    'section'  => $sectionKey,
                                                                    'required' => $required,
                                                                    'help'     => $help,
                                                                    'showIf'   => $normalizeShowIf($f['showIf'] ?? null),
                                                                ],
                                                            ];
                                                            break;
                                                        }

                                                        case 'file':
                                                        case 'file_upload': {
                                                            $blocks[] = [
                                                                'type' => 'file_upload',
                                                                'data' => [
                                                                    'label'    => $label,
                                                                    'key'      => $slug($f['key'] ?? $label),
                                                                    'section'  => $sectionKey,
                                                                    'multiple' => (bool) ($f['multiple'] ?? false),
                                                                    'required' => $required,
                                                                    'help'     => $help,
                                                                    'accept'   => $f['accept'] ?? 'image/*,application/pdf',
                                                                    'showIf'   => $normalizeShowIf($f['showIf'] ?? null),
                                                                ],
                                                            ];
                                                            break;
                                                        }

                                                        case 'signature': {
                                                            $blocks[] = [
                                                                'type' => 'signature',
                                                                'data' => [
                                                                    'label'    => $label,
                                                                    'key'      => $slug($f['key'] ?? $label),
                                                                    'section'  => $sectionKey,
                                                                    'required' => $required,
                                                                    'help'     => $help ?: 'Draw your signature above',
                                                                    'showIf'   => $normalizeShowIf($f['showIf'] ?? null),
                                                                ],
                                                            ];
                                                            break;
                                                        }

                                                        case 'textarea': {
                                                            $blocks[] = [
                                                                'type' => 'textarea',
                                                                'data' => [
                                                                    'label'       => $label,
                                                                    'key'         => $slug($f['key'] ?? $label),
                                                                    'section'     => $sectionKey,
                                                                    'placeholder' => $f['placeholder'] ?? null,
                                                                    'required'    => $required,
                                                                    'help'        => $help,
                                                                    'showIf'      => $normalizeShowIf($f['showIf'] ?? null),
                                                                ],
                                                            ];
                                                            break;
                                                        }

                                                        case 'text_block':
                                                        case 'html':
                                                        case 'text': {
                                                            $blocks[] = [
                                                                'type' => 'text_block',
                                                                'data' => [
                                                                    'label'   => $label ?: 'Content',
                                                                    'key'     => $slug($f['key'] ?? ($label ?: 'content')),
                                                                    'section' => $sectionKey,
                                                                    'content' => $toDocFromHtml($f['content'] ?? ($f['html'] ?? ($f['text'] ?? null))),
                                                                    'align'   => $f['align'] ?? 'left',
                                                                    'showIf'  => $normalizeShowIf($f['showIf'] ?? null),
                                                                ],
                                                            ];
                                                            break;
                                                        }

                                                        case 'divider': {
                                                            $blocks[] = ['type' => 'divider', 'data' => ['section' => $sectionKey]];
                                                            break;
                                                        }

                                                        case 'image': {
                                                            $blocks[] = [
                                                                'type' => 'image',
                                                                'data' => [
                                                                    'label'   => $label ?: 'Image',
                                                                    'key'     => $slug($f['key'] ?? ($label ?: 'image')),
                                                                    'section' => $sectionKey,
                                                                    'image'   => $f['image'] ?? null,
                                                                    'alt'     => $f['alt'] ?? null,
                                                                    'showIf'  => $normalizeShowIf($f['showIf'] ?? null),
                                                                ],
                                                            ];
                                                            break;
                                                        }

                                                        case 'page_break': {
                                                            $blocks[] = ['type' => 'page_break', 'data' => ['section' => $sectionKey]];
                                                            break;
                                                        }

                                                        default: {
                                                            $blocks[] = [
                                                                'type' => 'text_input',
                                                                'data' => [
                                                                    'label'       => $label,
                                                                    'key'         => $slug($f['key'] ?? $label),
                                                                    'section'     => $sectionKey,
                                                                    'placeholder' => $f['placeholder'] ?? null,
                                                                    'required'    => $required,
                                                                    'help'        => $help,
                                                                    'showIf'      => $normalizeShowIf($f['showIf'] ?? null),
                                                                ],
                                                            ];
                                                            break;
                                                        }
                                                    }
                                                }

                                                $sectionIndex++;
                                            }

                                            if (empty($blocks)) {
                                                throw new RuntimeException('No fields found to import.');
                                            }

                                            $set('schema', $blocks);
                                            Notification::make()->title('Form imported')->success()->send();
                                        } catch (Throwable $e) {
                                            Notification::make()->title('Import failed')->body($e->getMessage())->danger()->send();
                                        }
                                    }),
                            ])
                            ->schema([
                                Placeholder::make('structure_help')
                                    ->label('Structure')
                                    ->hiddenLabel()
                                    ->content(function (Get $get) {
                                        try {
                                            $blocks = $get('schema');
                                            if ($blocks instanceof \Illuminate\Support\Collection) {
                                                $blocks = $blocks->all();
                                            }
                                            if (! is_array($blocks) || empty($blocks)) {
                                                return 'No fields yet.';
                                            }
                                            $max = 120; // cap preview lines to avoid heavy rendering
                                            $lines = [];
                                            $n = 1;
                                            foreach (array_slice(array_values($blocks), 0, $max) as $block) {
                                                $type = (string) \Illuminate\Support\Arr::get($block, 'type', 'field');
                                                $label = trim((string) \Illuminate\Support\Arr::get($block, 'data.label', ''));
                                                if ($label === '') {
                                                    $label = \Illuminate\Support\Str::headline($type) . ' ' . $n;
                                                }
                                                if (strlen($label) > 80) {
                                                    $label = substr($label, 0, 77) . '...';
                                                }
                                                $isReq = (bool) \Illuminate\Support\Arr::get($block, 'data.required', false);
                                                $reqBadge = $isReq ? ' <span style="display:inline-block;padding:.05rem .35rem;border-radius:.25rem;background:rgba(239,68,68,.12);color:#f87171;font-weight:600;margin-left:.35rem;">required</span>' : '';
                                                $lines[] = $n . '. ' . e($label) . $reqBadge . ' <small style="opacity:.7">(' . e($type) . ')</small>';
                                                $n++;
                                            }
                                            $extra = count($blocks) > $max ? '<br><small style="opacity:.7">+' . (count($blocks) - $max) . ' more</small>' : '';
                                            return new \Illuminate\Support\HtmlString(implode('<br>', $lines) . $extra);
                                        } catch (\Throwable $e) {
                                            return new \Illuminate\Support\HtmlString('<small style="opacity:.7">Preview temporarily unavailable</small>');
                                        }
                                    }),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->compact()
                    ->columnSpan(4),
            ]);
    }
}
