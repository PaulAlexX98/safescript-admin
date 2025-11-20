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
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;
use Filament\Forms\Components\RichEditor\TextColor;
use Malzariey\FilamentLexicalEditor\FilamentLexicalEditor;
use Malzariey\FilamentLexicalEditor\Enums\ToolbarItem;

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
                            return RichEditor::make('content_rich_ui')
                                ->label('Visual editor')
                                ->columnSpanFull()
                                ->extraAlpineAttributes([
                                    'x-init' => '(() => { const root = $el; const CAP = "1520px"; const cap = (() => { if (root.style.maxHeight) return root; const el = root.querySelector("[style*=\\"max-height\\"]"); return el || root; })(); const getFullscreenOverlays = () => Array.from(document.querySelectorAll(".fi-editor-fullscreen,.tiptap-editor-fullscreen,.is-fullscreen,.ProseMirror-fullscreen,[data-fullscreen=true]")); const getTargets = (on) => { const t = new Set([cap]); if (on) { getFullscreenOverlays().forEach(o => { t.add(o); o.querySelectorAll(\'[style*="max-height"], .tiptap-editor, .ProseMirror, [contenteditable]\').forEach(x => t.add(x)); }); } else { root.querySelectorAll(\'[style*="max-height"], .tiptap-editor, .ProseMirror, [contenteditable]\').forEach(x => t.add(x)); } return Array.from(t); }; const setFS = on => { const tgts = getTargets(on); tgts.forEach(el => { try { if (on) { el.style.maxHeight = ""; el.style.height = \'calc(100vh - 180px)\'; el.style.overflow = \'auto\'; } else { if (el === cap) { el.style.maxHeight = CAP; el.style.overflow = \'auto\'; } else { el.style.maxHeight = \'\'; } el.style.height = \'\'; } } catch(e){} }); document.documentElement.classList.toggle(\'prevent-scroll\', on); document.body.classList.toggle(\'prevent-scroll\', on); }; const isFS = () => { if (document.fullscreenElement) return true; if (getFullscreenOverlays().length) return true; let el = root; while (el) { const cls = ((el.getAttribute(\'class\')||\'\')+\' \'+(el.getAttribute(\'data-state\')||\'\')); if (/fullscreen/i.test(cls) || el.hasAttribute(\'data-fullscreen\')) return true; el = el.parentElement; } return false; }; const update = () => setFS(isFS()); update(); document.addEventListener(\'fullscreenchange\', update); const mo = new MutationObserver(update); mo.observe(document.body, {attributes:true, childList:true, subtree:true}); root.addEventListener(\'click\', e => { const t = e.target && e.target.closest(\'[data-fullscreen-toggle],[aria-label=fullscreen],.ri-fullscreen-fill,.ri-fullscreen-exit-fill,.fi-icon-fullscreen,button[title=Fullscreen]\'); if (t) setTimeout(update, 0); }); })()'
                                ])
                                ->extraAttributes(['style' => 'max-height: 1000px; overflow: auto;'])
                                ->dehydrated(false)
                                ->afterStateHydrated(function (Set $set, Get $get, $state) {
                                    $current = (string) ($get('content') ?? '');
                                    if ($current !== '' && $current !== '<p></p>') {
                                        $set('content_rich_ui', $current);
                                    }
                                })
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

                                            // Replace src on any &lt;img&gt; with matching data-id
                                            $pattern = '/(&lt;img[^&gt;]*data-id=&quot;'
                                                . preg_quote($id, '/')
                                                . '&quot;[^&gt;]*src=&quot;)[^&quot;]*(&quot;)/';

                                            $html = preg_replace($pattern, '$1' . addcslashes($url, '&amp;') . '$2', $html) ?? $html;
                                        }
                                    }

                                    $set('content', $html);
                                })
                                ->fileAttachmentsDisk('public')
                                ->fileAttachmentsDirectory('clinic-forms/content')
                                ->fileAttachmentsVisibility('public')
                                ->fileAttachmentsAcceptedFileTypes(['image/png','image/jpeg','image/gif','image/webp'])
                                ->fileAttachmentsMaxSize(12 * 1024)
                                ->toolbarButtons([
                                    ['bold', 'italic', 'underline', 'strike', 'subscript', 'superscript'],
                                    ['h2', 'h3', 'alignStart', 'alignCenter', 'alignEnd'],
                                    ['blockquote', 'codeBlock', 'bulletList', 'orderedList', 'horizontalRule'],
                                    ['table', 'tableAddRowBefore', 'tableAddRowAfter', 'tableDeleteRow', 'attachFiles', 'textColor', 'link', 'fullscreen'],
                                    ['undo', 'redo'],
                                ])
                                ->placeholder('Edit visually — this will live-update the HTML field below.');
                        })(),

                        Textarea::make('content')
                            ->label('Page HTML')
                            ->rows(24)
                            ->default(fn (?Page $record) => is_string($record?->content) ? $record->content : '')
                            ->dehydrated(true)
                            ->columnSpanFull()
                            ->helperText('This saves directly to pages.content. Paste your full HTML here.'),

                        FileUpload::make('gallery')
                            ->label('Gallery images')
                            ->image()
                            ->multiple()
                            ->downloadable()
                            ->reorderable()
                            ->directory('clinic-forms/gallery')
                            ->helperText('Optional image gallery.')
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
                                TextInput::make('meta_title')
                                    ->label('Meta title')
                                    ->maxLength(60),

                                Textarea::make('meta_description')
                                    ->label('Meta description')
                                    ->rows(3)
                                    ->maxLength(160),
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