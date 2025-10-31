<?php

namespace App\Filament\Resources\ClinicForms\Pages;

use Log;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use App\Filament\Resources\ClinicForms\ClinicFormResource;
use Filament\Resources\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use App\Models\ClinicForm;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class RafBuilder extends Page
{
    use InteractsWithForms;
    protected string $view = 'clinic-forms.raf-builder';
    protected static string $resource = ClinicFormResource::class;
    public ?array $data = [];
    public ClinicForm $record;

    public function mount(ClinicForm $record): void
    {
        // Filament will route‑model‑bind the {record} parameter to ClinicForm
        $this->record = $record;

        // Only allow RAF builder on the Weight Management form
        Log::debug('RAF service slug check', ['id' => $this->record->id, 'service_slug' => $this->record->service_slug]);
        if ($this->record->service_slug !== 'weight-management-service') {
            abort(404);
        }

        $schema = $this->record->raf_schema;
        if (is_string($schema)) {
            $schema = json_decode($schema, true) ?: [];
        }
        if (! is_array($schema) || ! isset($schema['stages']) || ! is_array($schema['stages'])) {
            $schema = ['stages' => []];
        }

        $this->data = [
            'name' => $this->record->name,
            'service_slug' => $this->record->service_slug,
            'raf_schema' => $schema,
        ];
    }

    protected function getFormSchema(): array
    {
        return [
            TextInput::make('name')->label('Form title')->disabled(),
            TextInput::make('service_slug')->label('Service ID')->disabled(),

            Repeater::make('raf_schema.stages')
                ->label('Form sections')
                ->collapsed()
                ->addActionLabel('Add section')
                ->orderable()
                ->schema([
                    TextInput::make('title')->label('Section title')->required(),
                    TextInput::make('order')->numeric()->label('Order')->default(0),

                    Repeater::make('fields')
                        ->label('Questions')
                        ->collapsed()
                        ->addActionLabel('Add question')
                        ->orderable()
                        ->schema([
                            Select::make('type')->label('Type')->options([
                                'text' => 'Text',
                                'textarea' => 'Textarea',
                                'number' => 'Number',
                                'select' => 'Select',
                                'radio' => 'Radio',
                                'checkbox' => 'Checkbox',
                                'multiselect' => 'Multi-Select',
                                'date' => 'Date',
                                'notice' => 'Notice',
                            ])->required(),

                            TextInput::make('label')->label('Label')->required(),
                            TextInput::make('key')->label('Key')->helperText('Stable key used to save answers')->required(),
                            Textarea::make('help')->label('Help text')->rows(2),

                            TagsInput::make('options')
                                ->label('Options')
                                ->placeholder('Add an option and press Enter')
                                ->helperText('Used for select, radio, checkbox and multi-select fields')
                                ->visible(fn ($get) => in_array($get('type'), ['select','radio','multiselect','checkbox'])),

                            TextInput::make('validation')->label('Validation rule')->placeholder('numeric|min:0|max:100'),
                            Toggle::make('required')->label('Required')->default(false),
                            Toggle::make('pharmacist_editable')->label('Pharmacist editable')->default(false),
                            TextInput::make('order')->numeric()->label('Order')->default(0),
                            Textarea::make('show_if')->label('Show if JSON')->rows(2),
                            Textarea::make('meta')->label('Meta JSON')->rows(2),
                        ]),
                ]),
        ];
    }

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Form title')->disabled(),
            TextInput::make('service_slug')->label('Service ID')->disabled(),

            Repeater::make('raf_schema.stages')
                ->label('Form sections')
                ->collapsed()
                ->addActionLabel('Add section')
                ->orderable()
                ->schema([
                    TextInput::make('title')->label('Section title')->required(),
                    TextInput::make('order')->numeric()->label('Order')->default(0),

                    Repeater::make('fields')
                        ->label('Questions')
                        ->collapsed()
                        ->addActionLabel('Add question')
                        ->orderable()
                        ->schema([
                            Select::make('type')->label('Type')->options([
                                'text' => 'Text',
                                'textarea' => 'Textarea',
                                'number' => 'Number',
                                'select' => 'Select',
                                'radio' => 'Radio',
                                'checkbox' => 'Checkbox',
                                'multiselect' => 'Multi-Select',
                                'date' => 'Date',
                                'notice' => 'Notice',
                            ])->required(),

                            TextInput::make('label')->label('Label')->required(),
                            TextInput::make('key')->label('Key')->helperText('Stable key used to save answers')->required(),
                            Textarea::make('help')->label('Help text')->rows(2),

                            TagsInput::make('options')
                                ->label('Options')
                                ->placeholder('Add an option and press Enter')
                                ->helperText('Used for select, radio, checkbox and multi-select fields')
                                ->visible(fn ($get) => in_array($get('type'), ['select','radio','multiselect','checkbox'])),

                            TextInput::make('validation')->label('Validation rule')->placeholder('numeric|min:0|max:100'),
                            Toggle::make('required')->label('Required')->default(false),
                            Toggle::make('pharmacist_editable')->label('Pharmacist editable')->default(false),
                            TextInput::make('order')->numeric()->label('Order')->default(0),
                            Textarea::make('show_if')->label('Show if JSON')->rows(2),
                            Textarea::make('meta')->label('Meta JSON')->rows(2),
                        ]),
                ]),
        ])->statePath('data');
    }
    public function save(): void
    {
        // Use the bound page state instead of $this->form->getState() to avoid missing empty arrays
        $state = $this->data ?? [];
        $payload = $state['raf_schema'] ?? ['stages' => []];

        // Seed a default section if none provided
        if (empty($payload['stages'])) {
            $payload['stages'] = [[
                'title' => 'Initial Section',
                'order' => 1,
                'fields' => [[
                    'type' => 'text',
                    'label' => 'Sample question',
                    'key' => 'sample_question',
                    'help' => 'Example field to get started',
                    'required' => false,
                    'order' => 1,
                ]],
            ]];
        }

        // Ensure unique keys per stage
        foreach ($payload['stages'] as &$stage) {
            if (! empty($stage['fields'])) {
                $seen = [];
                foreach ($stage['fields'] as &$field) {
                    $key = (string) ($field['key'] ?? '');
                    if ($key === '' || in_array($key, $seen, true)) {
                        $field['key'] = trim($key . '_' . uniqid());
                    }
                    $seen[] = $field['key'];
                }
            }
        }
        unset($stage, $field);

        // Normalize options to arrays for choice fields
        foreach ($payload['stages'] as &$stage) {
            if (! empty($stage['fields'])) {
                foreach ($stage['fields'] as &$field) {
                    $isChoice = in_array(($field['type'] ?? ''), ['select','radio','checkbox','multiselect'], true);
                    if (! $isChoice) {
                        continue;
                    }
                    if (array_key_exists('options', $field)) {
                        if (is_string($field['options'])) {
                            $parts = array_map(static fn($s) => trim($s), explode(',', $field['options']));
                            $field['options'] = array_values(array_filter($parts, static fn($s) => $s !== ''));
                        } elseif (! is_array($field['options'])) {
                            $field['options'] = [];
                        }
                    } else {
                        $field['options'] = [];
                    }
                }
                unset($field);
            }
        }
        unset($stage);

        // Persist and bump version
        $newVersion = (int) ($this->record->raf_version ?? 1) + 1;
        $this->record->update([
            'raf_schema' => $payload,
            'raf_version' => $newVersion,
        ]);

        // Keep UI state in sync
        $this->data['raf_schema'] = $payload;

        Notification::make()->success()->title("RAF schema saved (v{$newVersion}).")->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportJson')
                ->label('Export JSON')
                ->icon('heroicon-m-arrow-up-tray')
                ->modalHeading('Export RAF JSON')
                ->modalSubmitAction(false)
                ->action(function (): void {
                    $payload = $this->data['raf_schema'] ?? ['stages' => []];
                    $file = 'backups/raf_export_' . $this->record->id . '.json';
                    Storage::disk('local')->put($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    Notification::make()->success()->title('Exported to storage/app/' . $file)->send();
                }),
            Action::make('importJson')
                ->label('Import JSON')
                ->icon('heroicon-m-arrow-down-tray')
                ->modalHeading('Import RAF JSON')
                ->schema([
                    Textarea::make('json')
                        ->rows(14)
                        ->required()
                        ->placeholder('Paste RAF JSON here (must contain a top-level \'stages\' array)')
                ])
                ->action(function (array $data): void {
                    $raw = (string) ($data['json'] ?? '');
                    $payload = json_decode($raw, true);
                    if (! is_array($payload)) {
                        Notification::make()->danger()->title('Invalid JSON.')->send();
                        return;
                    }
                    if (! isset($payload['stages']) || ! is_array($payload['stages'])) {
                        Notification::make()->danger()->title("JSON must contain a top-level 'stages' array.")->send();
                        return;
                    }

                    // Persist & bump version
                    $newVersion = (int) ($this->record->raf_version ?? 1) + 1;
                    $this->record->update([
                        'raf_schema' => $payload,
                        'raf_version' => $newVersion,
                    ]);

                    // Reflect in UI state
                    $this->data['raf_schema'] = $payload;

                    Notification::make()->success()->title("Imported RAF (v{$newVersion}).")->send();
                }),

            Action::make('importFromFile')
                ->label('Load from file')
                ->icon('heroicon-m-folder-arrow-down')
                ->modalHeading('Load RAF JSON from server file path')
                ->schema([
                    TextInput::make('path')
                        ->default(base_path('backups/raf_builder.json'))
                        ->required()
                        ->helperText('Absolute or project-relative path to a JSON file on the server')
                ])
                ->action(function (array $data): void {
                    $path = (string) ($data['path'] ?? '');
                    // Resolve relative paths against project base path
                    if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
                        $path = base_path(trim($path, '/'));
                    }

                    if (! is_file($path)) {
                        Notification::make()->danger()->title('File not found: ' . $path)->send();
                        return;
                    }

                    $raw = @file_get_contents($path);
                    if ($raw === false) {
                        Notification::make()->danger()->title('Unable to read file: ' . $path)->send();
                        return;
                    }

                    $payload = json_decode($raw, true);
                    if (! is_array($payload)) {
                        Notification::make()->danger()->title('Invalid JSON in file.')->send();
                        return;
                    }
                    if (! isset($payload['stages']) || ! is_array($payload['stages'])) {
                        Notification::make()->danger()->title("JSON must contain a top-level 'stages' array.")->send();
                        return;
                    }

                    // Persist & bump version
                    $newVersion = (int) ($this->record->raf_version ?? 1) + 1;
                    $this->record->update([
                        'raf_schema' => $payload,
                        'raf_version' => $newVersion,
                    ]);

                    // Reflect in UI state
                    $this->data['raf_schema'] = $payload;

                    Notification::make()->success()->title("Loaded from file and saved (v{$newVersion}).")->send();
                }),

            Action::make('save')
                ->label('Save')
                ->color('success')
                ->action('save'),
        ];
    }
}