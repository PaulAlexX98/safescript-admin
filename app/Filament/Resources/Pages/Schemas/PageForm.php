<?php

namespace App\Filament\Resources\Pages\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\RichEditor;
use App\Models\Page;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\Repeater;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;
use Filament\Forms\Components\RichEditor\TextColor;
use Malzariey\FilamentLexicalEditor\FilamentLexicalEditor;
use Malzariey\FilamentLexicalEditor\Enums\ToolbarItem;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(12) // 8/4 layout
            ->components([

                // ===== LEFT COLUMN (8) =====
                Section::make()
                    ->schema([

                        TextInput::make('title')
                            ->label('Title')
                            ->required()
                            ->maxLength(190)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get, ?string $state) {
                                $set('slug', $get('slug') ?: Str::slug($state ?? ''));
                            }),

                        TextInput::make('slug')
                            ->label('Permalink')
                            ->prefix(url('/'))
                            ->helperText('Used in the page URL')
                            ->unique(ignoreRecord: true)
                            ->required(),

                        Textarea::make('description')
                            ->rows(4)
                            ->helperText('Short summary used in listings.'),

                        // Optional visual editor (non-persistent) that live-updates the HTML textarea below
                        (function () {
                            return RichEditor::make('content')
                                ->label('Visual editor')
                                ->columnSpanFull()
                                ->extraAlpineAttributes([
                                    'x-init' => '(() => { const root = $el; const CAP = "1520px"; const cap = (() => { if (root.style.maxHeight) return root; const el = root.querySelector("[style*=\\"max-height\\"]"); return el || root; })(); const getFullscreenOverlays = () => Array.from(document.querySelectorAll(".fi-editor-fullscreen,.tiptap-editor-fullscreen,.is-fullscreen,.ProseMirror-fullscreen,[data-fullscreen=true]")); const getTargets = (on) => { const t = new Set([cap]); if (on) { getFullscreenOverlays().forEach(o => { t.add(o); o.querySelectorAll(\'[style*="max-height"], .tiptap-editor, .ProseMirror, [contenteditable]\').forEach(x => t.add(x)); }); } else { root.querySelectorAll(\'[style*="max-height"], .tiptap-editor, .ProseMirror, [contenteditable]\').forEach(x => t.add(x)); } return Array.from(t); }; const setFS = on => { const tgts = getTargets(on); tgts.forEach(el => { try { if (on) { el.style.maxHeight = ""; el.style.height = \'calc(100vh - 180px)\'; el.style.overflow = \'auto\'; } else { if (el === cap) { el.style.maxHeight = CAP; el.style.overflow = \'auto\'; } else { el.style.maxHeight = \'\'; } el.style.height = \'\'; } } catch(e){} }); document.documentElement.classList.toggle(\'prevent-scroll\', on); document.body.classList.toggle(\'prevent-scroll\', on); }; const isFS = () => { if (document.fullscreenElement) return true; if (getFullscreenOverlays().length) return true; let el = root; while (el) { const cls = ((el.getAttribute(\'class\')||\'\')+\' \'+(el.getAttribute(\'data-state\')||\'\')); if (/fullscreen/i.test(cls) || el.hasAttribute(\'data-fullscreen\')) return true; el = el.parentElement; } return false; }; const update = () => setFS(isFS()); update(); document.addEventListener(\'fullscreenchange\', update); const mo = new MutationObserver(update); mo.observe(document.body, {attributes:true, childList:true, subtree:true}); root.addEventListener(\'click\', e => { const t = e.target && e.target.closest(\'[data-fullscreen-toggle],[aria-label=fullscreen],.ri-fullscreen-fill,.ri-fullscreen-exit-fill,.fi-icon-fullscreen,button[title=Fullscreen]\'); if (t) setTimeout(update, 0); }); })()'
                                ])
                                ->extraAttributes(['style' => 'max-height: 1000px; overflow: auto;'])
                                ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                    // Normalise editor state to HTML string
                                    $html = is_string($state)
                                        ? $state
                                        : (is_array($state)
                                            ? ($state['html'] ?? $state['content'] ?? '')
                                            : (string) $state);

                                    // If the editor provided attachment metadata, replace preview-file URLs
                                    if (is_array($state) && ! empty($state['attachments']) && is_array($state['attachments'])) {
                                        foreach ($state['attachments'] as $attachment) {
                                            $id = $attachment['id'] ?? null;
                                            $url = $attachment['url'] ?? ($attachment['path'] ?? null);

                                            if (! $id || ! $url) {
                                                continue;
                                            }

                                            // Normalise URL – ensure it is a web path
                                            if (str_starts_with($url, 'storage/')) {
                                                $url = '/' . $url;
                                            } elseif (! str_starts_with($url, 'http') && ! str_starts_with($url, '/')) {
                                                $url = '/' . ltrim($url, '/');
                                            }

                                            // Replace src on any <img> with matching data-id in either raw or entity-encoded HTML, in any attribute order
                                            $patterns = [
                                                // Normal HTML data-id then src
                                                '/(<img[^>]*data-id="' . preg_quote($id, '/') . '"[^>]*src=")[^"]*("[^>]*>)/i',
                                                // Normal HTML src then data-id
                                                '/(<img[^>]*src=")[^"]*("(?=[^>]*data-id="' . preg_quote($id, '/') . '")[^>]*>)/i',
                                                // Entity-encoded HTML data-id then src
                                                '/(&lt;img[^&gt;]*data-id=&quot;' . preg_quote($id, '/') . '&quot;[^&gt;]*src=&quot;)[^&quot;]*(&quot;[^&gt;]*&gt;)/i',
                                                // Entity-encoded HTML src then data-id
                                                '/(&lt;img[^&gt;]*src=&quot;)[^&quot;]*(&quot;(?=[^&gt;]*data-id=&quot;' . preg_quote($id, '/') . '&quot;)[^&gt;]*&gt;)/i',
                                            ];

                                            foreach ($patterns as $pattern) {
                                                $html = preg_replace($pattern, '$1' . $url . '$2', $html) ?? $html;
                                            }
                                        }
                                    }

                                    $set('content', $html);
                                })
                                ->fileAttachmentsDisk('public')
                                ->fileAttachmentsDirectory('clinic-forms/content')
                                ->fileAttachmentsVisibility('public')
                                ->fileAttachmentsAcceptedFileTypes(['image/png','image/jpeg','image/gif','image/webp'])
                                ->fileAttachmentsMaxSize(5120)
                                ->toolbarButtons([
                                    ['bold', 'italic', 'underline', 'strike', 'subscript', 'superscript'],
                                    ['h2', 'h3', 'alignStart', 'alignCenter', 'alignEnd'],
                                    ['blockquote', 'codeBlock', 'bulletList', 'orderedList', 'horizontalRule'],
                                    ['table', 'tableAddRowBefore', 'tableAddRowAfter', 'tableDeleteRow', 'attachFiles', 'textColor', 'link', 'fullscreen'],
                                    ['undo', 'redo'],
                                ])
                                ->placeholder('Edit visually — this will live-update the HTML field below.');
                        })(),

                        FileUpload::make('gallery')
                            ->label('Gallery images')
                            ->image()
                            ->multiple()
                            ->downloadable()
                            ->reorderable()
                            ->directory('clinic-forms/gallery')
                            ->helperText('Optional image gallery.')
                            ->columnSpanFull(),

                        Section::make('Service Sections')
                            ->collapsible()
                            ->schema([
                                Section::make('Top banner')
                                    ->statePath('meta.sections.topbar')
                                    ->schema([
                                        Toggle::make('enabled')
                                            ->label('Show top banner')
                                            ->default(true)
                                            ->columnSpan(3),
                                        TextInput::make('heading')
                                            ->label('Heading text')
                                            ->helperText('Shown in the rounded chip row above the hero')
                                            ->columnSpan(9),

                                        Repeater::make('items')
                                            ->label('Badges')
                                            ->schema([
                                                TextInput::make('value')
                                                    ->label('Text')
                                                    ->required(),
                                            ])
                                            ->columns(1)
                                            ->reorderable()
                                            ->collapsible()
                                            ->columnSpanFull(),

                                        Section::make('Primary button')
                                            ->schema([
                                                TextInput::make('primary.label')
                                                    ->label('Button label')
                                                    ->maxLength(80)
                                                    ->columnSpan(5),
                                                TextInput::make('primary.href')
                                                    ->label('Button link')
                                                    ->helperText('Paste a URL. If you upload a file below we will auto-fill this field.')
                                                    ->columnSpan(7),
                                                FileUpload::make('primary.upload')
                                                    ->label('Upload file for primary button')
                                                    ->disk('public')
                                                    ->directory('pages/topbar')
                                                    ->visibility('public')
                                                    ->openable()
                                                    ->downloadable()
                                                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                                        $path = is_array($state) ? ($state['path'] ?? $state['url'] ?? $state['name'] ?? '') : (string) $state;
                                                        if ($path) {
                                                            $path = ltrim($path, '/');
                                                            if (! str_starts_with($path, 'storage/')) {
                                                                $path = 'storage/' . $path;
                                                            }
                                                            $set('primary.href', '/' . $path);
                                                        }
                                                    })
                                                    ->columnSpanFull(),
                                            ])
                                            ->columns(12)
                                            ->columnSpan(6)
                                            ->collapsible(),

                                        Section::make('Secondary button')
                                            ->schema([
                                                TextInput::make('secondary.label')
                                                    ->label('Button label')
                                                    ->maxLength(80)
                                                    ->columnSpan(5),
                                                TextInput::make('secondary.href')
                                                    ->label('Button link')
                                                    ->helperText('Paste a URL. If you upload a file below we will auto-fill this field.')
                                                    ->columnSpan(7),
                                                FileUpload::make('secondary.upload')
                                                    ->label('Upload file for secondary button')
                                                    ->disk('public')
                                                    ->directory('pages/topbar')
                                                    ->visibility('public')
                                                    ->openable()
                                                    ->downloadable()
                                                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                                        $path = is_array($state) ? ($state['path'] ?? $state['url'] ?? $state['name'] ?? '') : (string) $state;
                                                        if ($path) {
                                                            $path = ltrim($path, '/');
                                                            if (! str_starts_with($path, 'storage/')) {
                                                                $path = 'storage/' . $path;
                                                            }
                                                            $set('secondary.href', '/' . $path);
                                                        }
                                                    })
                                                    ->columnSpanFull(),
                                            ])
                                            ->columns(12)
                                            ->columnSpan(6)
                                            ->collapsible(),
                                    ])
                                    ->columns(12)
                                    ->columnSpanFull()
                                    ->collapsible(),
                                Section::make('Hero')
                                    ->statePath('meta.sections.hero')
                                    ->schema([
                                        Toggle::make('enabled')->default(true)->label('Show hero'),

                                        TextInput::make('kicker')
                                            ->label('Kicker eg NHS repeat prescriptions')
                                            ->columnSpan(4),

                                        TextInput::make('title')
                                            ->label('Hero title')
                                            ->maxLength(190)
                                            ->required()
                                            ->columnSpan(8),

                                        TextInput::make('subtext')
                                            ->label('Hero strapline')
                                            ->maxLength(190)
                                            ->columnSpanFull(),

                                        // primary button
                                        TextInput::make('primary.label')
                                            ->label('Primary button label')
                                            ->maxLength(80)
                                            ->columnSpan(5),
                                        TextInput::make('primary.href')
                                            ->label('Primary button link')
                                            ->helperText('Optional. If set, button will open the link; otherwise it opens the NHS modal.')
                                            ->columnSpan(7),

                                        // secondary button
                                        TextInput::make('secondary.label')
                                            ->label('Secondary button label')
                                            ->maxLength(80)
                                            ->columnSpan(5),
                                        TextInput::make('secondary.href')
                                            ->label('Secondary button link')
                                            ->helperText('Optional. If set, button will open the link; otherwise it opens the NHS modal.')
                                            ->columnSpan(7),

                                        // hero image
                                        FileUpload::make('hero_upload')
                                            ->label('Hero image')
                                            ->image()
                                            ->disk('public')
                                            ->directory('pages/hero')
                                            ->visibility('public')
                                            ->imageEditor()
                                            ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                                $path = is_array($state) ? ($state['path'] ?? $state['url'] ?? $state['name'] ?? '') : (string) $state;
                                                if ($path) {
                                                    $path = ltrim($path, '/');
                                                    if (! str_starts_with($path, 'storage/')) {
                                                        $path = 'storage/' . $path;
                                                    }
                                                    // frontend reads image_url
                                                    $set('image_url', '/' . $path);
                                                }
                                            })
                                            ->columnSpan(6),
                                        TextInput::make('image_url')
                                            ->label('Image URL')
                                            ->placeholder('/storage/pages/hero/your-image.webp')
                                            ->dehydrated(true)
                                            ->columnSpan(6),

                                        // badges row
                                        Repeater::make('badges')
                                            ->label('Badges')
                                            ->schema([
                                                TextInput::make('value')
                                                    ->label('Text')
                                                    ->required(),
                                            ])
                                            ->collapsible()
                                            ->reorderable()
                                            ->columns(1)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(12)
                                    ->collapsible(),

                                Section::make('About section')
                                    ->statePath('meta.sections.about')
                                    ->schema([
                                        Toggle::make('enabled')->default(true),
                                        TextInput::make('title')->label('Heading')->maxLength(190),
                                        Textarea::make('paragraph')->label('Paragraph')->rows(4),
                                        Repeater::make('bullets')
                                            ->label('Bullets')
                                            ->schema([
                                                TextInput::make('value')->label('Text')->required(),
                                            ])
                                            ->columns(1)
                                            ->reorderable()
                                            ->collapsible()
                                            ->columnSpanFull(),
                                        RichEditor::make('html')
                                            ->label('Extra details rich text')
                                            ->helperText('Optional extra copy shown below the bullets')
                                            ->columnSpanFull(),
                                        // Optional image (not currently used by frontend, but kept for future)
                                        FileUpload::make('image_upload')
                                            ->label('Side image')
                                            ->image()
                                            ->disk('public')
                                            ->directory('pages/about')
                                            ->visibility('public')
                                            ->imageEditor()
                                            ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                                $path = is_array($state) ? ($state['path'] ?? $state['url'] ?? $state['name'] ?? '') : (string) $state;
                                                if ($path) {
                                                    $path = ltrim($path, '/');
                                                    if (! str_starts_with($path, 'storage/')) {
                                                        $path = 'storage/' . $path;
                                                    }
                                                    $set('image_url', '/' . $path);
                                                }
                                            }),
                                        TextInput::make('image_url')->label('Image URL'),
                                    ])
                                    ->columns(2)
                                    ->collapsible(),

                                Section::make('Pricing table')
                                    ->statePath('meta.sections.prices')
                                    ->schema([
                                        Toggle::make('enabled')
                                            ->default(true)
                                            ->label('Enabled'),

                                        TextInput::make('title')
                                            ->label('Heading')
                                            ->maxLength(190),

                                        Toggle::make('has_col_header')
                                            ->label('Show column header row')
                                            ->default(true),

                                        Toggle::make('has_row_header')
                                            ->label('Show row header column')
                                            ->default(false),

                                        Repeater::make('columns')
                                            ->label('Column headers')
                                            ->schema([
                                                TextInput::make('label')
                                                    ->label('Header label')
                                                    ->required(),
                                            ])
                                            ->columns(1)
                                            ->reorderable()
                                            ->collapsible()
                                            ->visible(fn (Get $get) => (bool) $get('has_col_header'))
                                            ->columnSpanFull(),

                                        Repeater::make('rows')
                                            ->label('Rows')
                                            ->schema([
                                                TextInput::make('label')
                                                    ->label('Row header')
                                                    ->helperText('Only used when Show row header column is on.'),

                                                Repeater::make('cells')
                                                    ->label('Cells')
                                                    ->schema([
                                                        TextInput::make('value')
                                                            ->label('Cell text')
                                                            ->required(),
                                                    ])
                                                    ->columns(1)
                                                    ->reorderable()
                                                    ->collapsible()
                                                    ->columnSpanFull(),
                                            ])
                                            ->columns(1)
                                            ->reorderable()
                                            ->addActionLabel('Add row')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(1)
                                    ->columnSpanFull()
                                    ->collapsible(),

                                Section::make('Why choose us')
                                    ->statePath('meta.sections.why')
                                    ->schema([
                                        Toggle::make('enabled')->default(true)->label('Enabled'),
                                        TextInput::make('title')->label('Heading')->maxLength(190)->default('Why choose our NHS service'),

                                        // Left column bullet list
                                        Repeater::make('bullets')
                                            ->label('Bullet points')
                                            ->schema([
                                                TextInput::make('value')
                                                    ->label('Text')
                                                    ->required()
                                                    ->columnSpanFull(),
                                            ])
                                            ->columns(1)
                                            ->reorderable()
                                            ->addActionLabel('Add bullet')
                                            ->columnSpanFull(),

                                        // Right column feature cards
                                        Repeater::make('features')
                                            ->label('Feature cards')
                                            ->schema([
                                            

                                                FileUpload::make('icon_upload')
                                                    ->label('Custom icon upload')
                                                    ->image()
                                                    ->disk('public')
                                                    ->directory('pages/features')
                                                    ->visibility('public')
                                                    ->imageEditor()
                                                    ->openable()
                                                    ->downloadable()
                                                    ->helperText('Optional. If set, this image is used instead of the selected icon.')
                                                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                                        $path = is_array($state) ? ($state['path'] ?? $state['url'] ?? $state['name'] ?? '') : (string) $state;
                                                        if ($path) {
                                                            $path = ltrim($path, '/');
                                                            if (! str_starts_with($path, 'storage/')) $path = 'storage/'.$path;
                                                            $set('icon_url', '/'.$path);
                                                        }
                                                    })
                                                    ->columnSpanFull(),

                                                TextInput::make('icon_url')
                                                    ->label('Icon URL')
                                                    ->helperText('Filled automatically on upload or paste a /storage path')
                                                    ->columnSpanFull(),

                                                TextInput::make('title')
                                                    ->label('Title')
                                                    ->required()
                                                    ->maxLength(120)
                                                    ->columnSpanFull(),
                                            ])
                                            ->columns(1)
                                            ->reorderable()
                                            ->addActionLabel('Add feature')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(1)
                                    ->columnSpanFull()
                                    ->extraAttributes(['class' => 'fi-w-full'])
                                    ->collapsible(),

                                Section::make('How it works')
                                    ->statePath('meta.sections.how')
                                    ->schema([
                                        Toggle::make('enabled')->default(true),
                                        TextInput::make('title')->label('Heading')->maxLength(190)->default('How it works'),
                                        Repeater::make('steps')
                                            ->label('Steps')
                                            ->schema([
                                                TextInput::make('title')
                                                    ->label('Step title')
                                                    ->required()
                                                    ->maxLength(150)
                                                    ->columnSpanFull(),
                                                Textarea::make('text')
                                                    ->label('Step text')
                                                    ->rows(3)
                                                    ->columnSpanFull(),
                                                FileUpload::make('image_upload')
                                                    ->label('Step image')
                                                    ->image()
                                                    ->disk('public')
                                                    ->directory('pages/steps')
                                                    ->visibility('public')
                                                    ->imageEditor()
                                                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                                        $path = is_array($state) ? ($state['path'] ?? $state['name'] ?? '') : (string) $state;
                                                        if ($path) {
                                                            $path = ltrim($path, '/');
                                                            if (! str_starts_with($path, 'storage/')) {
                                                                $path = 'storage/' . $path;
                                                            }
                                                            $set('image_url', '/' . $path);
                                                        }
                                                    })
                                                    ->columnSpanFull(),
                                                TextInput::make('image_url')
                                                    ->label('Image URL')
                                                    ->columnSpanFull(),
                                            ])
                                            ->columns(1)
                                            ->reorderable()
                                            ->addActionLabel('Add step')
                                            ->columnSpanFull(),
                                    ])
                                    ->collapsible(),

                                Section::make('FAQs')
                                    ->statePath('meta.sections.faqs')
                                    ->schema([
                                        Toggle::make('enabled')->default(true),
                                        TextInput::make('title')->label('Heading')->maxLength(190)->default('Frequently asked questions'),
                                        Repeater::make('items')
                                            ->schema([
                                                TextInput::make('q')->label('Question')->required()->maxLength(190),
                                                Textarea::make('a')->label('Answer')->rows(4)->required(),
                                            ])
                                            ->columnSpanFull()
                                            ->reorderable(),
                                    ])
                                    ->collapsible(),

                                Section::make('Videos')
                                    ->statePath('meta.sections.videos')
                                    ->schema([
                                        Toggle::make('enabled')->default(true),
                                        TextInput::make('title')->label('Heading')->maxLength(190)->default('Watch how NHS nomination works'),
                                        Repeater::make('items')
                                            ->schema([
                                                TextInput::make('url')
                                                    ->label('YouTube URL')
                                                    ->helperText('Paste a YouTube link. We will store both url and id.')
                                                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                                        $u = (string) $state;
                                                        $id = null;
                                                        if (preg_match('~(?:youtu\.be/|v=|embed/)([A-Za-z0-9_-]{6,})~', $u, $m)) {
                                                            $id = $m[1] ?? null;
                                                        }
                                                        if ($id) $set('id', $id);
                                                    }),
                                                TextInput::make('id')->label('Video ID')->helperText('Autofilled from URL if possible.'),
                                                TextInput::make('title')->label('Video title')->maxLength(190),
                                            ])
                                            ->columnSpanFull()
                                            ->reorderable(),
                                    ])
                                    ->collapsible(),
                            ])
                            ->columnSpanFull(),

                    ])
                    ->columns(1)
                    ->compact()
                    ->columnSpan(8),

                // ===== RIGHT COLUMN (4) =====
                Section::make()
                    ->schema([

                        Section::make('Publish')
                            ->schema([
                                Select::make('status')
                                    ->options([
                                        'draft'     => 'Draft',
                                        'published' => 'Published',
                                    ])
                                    ->default('published')
                                    ->required(),

                                Select::make('template')
                                    ->options([
                                        'default' => 'Default',
                                        'WeightLossV1' => 'Weight Loss (V1)',
                                    ])
                                    ->default('default')
                                    ->required(),

                                Select::make('visibility')
                                    ->options([
                                        'public'   => 'Public',
                                        'internal' => 'Internal',
                                        'private'  => 'Private',
                                    ])
                                    ->required(),

                                Toggle::make('active')
                                    ->label('Active')
                                    ->default(true),
                            ])
                            ->collapsible(),

                        Section::make('Search Engine Optimize')
                            ->schema([
                                // Google-style preview
                                Placeholder::make('seo_preview')
                                    ->label('Preview')
                                    ->content(function (Get $get) {
                                        $title = trim($get('meta_title') ?: $get('title') ?: '');
                                        $slug  = trim($get('slug') ?: '');
                                        $url   = rtrim(url('/'), '/') . '/' . ltrim($slug, '/');
                                        $desc  = trim($get('meta_description') ?: $get('description') ?: '');

                                        // Soft limits
                                        $title = mb_substr($title, 0, 60);
                                        $desc  = mb_substr($desc, 0, 160);

                                        // Precompute display values to avoid complex expressions in heredoc interpolation
                                        $titleOut = ($title !== '') ? $title : 'Untitled page';
                                        $descOut  = ($desc !== '') ? $desc  : 'Add a compelling summary up to 160 characters.';

                                        // Simple Google-like snippet
                                        $htmlOut = <<<HTML
<div style="border:1px solid #e5e7eb;border-radius:10px;padding:14px;background:#fff">
  <div style="font-size:18px;line-height:1.2;color:#1a0dab;margin-bottom:4px;">{$titleOut}</div>
  <div style="font-size:14px;color:#006621;margin-bottom:6px;">{$url}</div>
  <div style="font-size:13px;color:#4b5563;">{$descOut}</div>
</div>
HTML;
                                        return new HtmlString($htmlOut);
                                    })
                                    ->extraAttributes(['style' => 'padding-top:0'])
                                    ->columnSpanFull(),

                                // Meta fields with live counters
                                TextInput::make('meta_title')
                                    ->label('Meta title')
                                    ->maxLength(60)
                                    ->live(onBlur: true)
                                    ->hint(fn (Get $get) => mb_strlen((string) $get('meta_title')) . '/60'),

                                Textarea::make('meta_description')
                                    ->label('Meta description')
                                    ->rows(3)
                                    ->maxLength(160)
                                    ->live(onBlur: true)
                                    ->hint(fn (Get $get) => mb_strlen((string) $get('meta_description')) . '/160'),

                                // Quick action to jump cursor to meta fields
                                Placeholder::make('edit_seo_meta_button')
                                    ->label('Edit SEO meta')
                                    ->content(new HtmlString('<button type="button" class="fi-btn fi-color-gray fi-size-md">Edit SEO meta</button>'))
                                    ->extraAttributes([
                                        // Hide only the label element to remove spacing while keeping the button visible
                                        'x-init' => '(function(){ const field = $el.closest(".fi-fo-field-wrp"); const lab = field ? field.querySelector("label") : null; if(lab) lab.classList.add("sr-only"); })()',
                                        'x-on:click' => "(() => { const t = document.querySelector('[name=\"meta_title\"]'); const d = document.querySelector('[name=\"meta_description\"]'); (t ?? d)?.scrollIntoView({behavior:'smooth', block:'center'}); (t ?? d)?.focus(); })()",
                                        'style' => 'margin-top: -8px;'
                                    ])
                                    ->columnSpanFull(),
                            ])
                            ->collapsible(),

                        Section::make('Background Image')
                            ->statePath('meta.background')
                            ->schema([
                                Toggle::make('enabled')
                                    ->label('Use background')
                                    ->default(true)
                                    ->inline(false)
                                    ->helperText('Turn off to render this page without a background image.'),

                                FileUpload::make('background_upload')
                                    ->label('Upload image')
                                    ->image()
                                    ->directory('pages/backgrounds')
                                    ->disk('public')
                                    ->visibility('public')
                                    ->imageEditor()
                                    ->acceptedFileTypes(['image/webp','image/avif','image/jpeg','image/png'])
                                    ->maxSize(4096)
                                    ->openable()
                                    ->downloadable()
                                    ->helperText('Upload once to auto-fill Image URL. Stored in public storage pages/backgrounds.')
                                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                        $path = '';

                                        if (is_string($state)) {
                                            $path = $state; // e.g. 'pages/backgrounds/abc.webp' or '/storage/pages/backgrounds/abc.webp'
                                        } elseif (is_array($state)) {
                                            // Filament can return various shapes
                                            $path = $state['path'] ?? $state['url'] ?? $state['name'] ?? '';
                                        } elseif ($state instanceof TemporaryUploadedFile) {
                                            $path = $state->getFilename();
                                        }

                                        if ($path) {
                                            // Ensure it points at public storage and is prefixed with '/storage/'
                                            $path = ltrim($path, '/');
                                            if (!str_starts_with($path, 'storage/')) {
                                                $path = 'storage/' . $path;
                                            }
                                            $set('url', '/' . $path);
                                        }
                                    }),

                                TextInput::make('url')
                                    ->label('Image URL')
                                    ->placeholder('https://... or /storage/...')
                                    ->helperText('WebP or AVIF recommended. 200–300 KB target.')
                                    ->live(onBlur: true)
                                    ->dehydrated(true),

                                Slider::make('blur')
                                    ->label('Blur')
                                    ->minValue(0)
                                    ->maxValue(24)
                                    ->default(12)
                                    ->helperText('Backdrop blur strength for the glass card.'),

                                Slider::make('overlay')
                                    ->label('Dark overlay')
                                    ->minValue(0)
                                    ->maxValue(80)
                                    ->default(30)
                                    ->helperText('Darken the background image to improve text contrast.'),
                            ])
                            ->collapsible(),
                    ])
                    ->columns(1)
                    ->compact()
                    ->columnSpan(4),
            ]);
    }
}