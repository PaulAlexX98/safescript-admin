<?php

namespace App\Filament\Resources\Services\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use Filament\Actions;
use Filament\Resources\RelationManagers\RelationManager;
use App\Models\Product;
use App\Models\ProductVariation;
use Filament\Forms\Components\FileUpload;

class ProductsRelationManager extends RelationManager
{
    // This must match the relationship name on Service model
    protected static string $relationship = 'products';

    protected static ?string $title = 'Products';
    protected static ?string $recordTitleAttribute = 'name';

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            // Drag to reorder by pivot.sort_order
            ->reorderable('pivot.sort_order')
            ->modifyQueryUsing(fn ($query) => $query->withCount('variations'))

            // Header actions: Add product, Remove product
            ->headerActions([
                Actions\Action::make('create_and_attach_product')
                    ->label('Add product')
                    ->icon('heroicon-m-plus')
                    ->modalHeading('Add Product')
                    ->modalWidth('7xl')
                    ->form([
                        // Basic product fields
                        Forms\Components\TextInput::make('name')
                            ->label('Product Name')
                            ->required(),

                        Forms\Components\RichEditor::make('description')
                            ->label('Description')
                            ->toolbarButtons(['bold','italic','link','bulletList','orderedList','blockquote','redo','undo']),

                        FileUpload::make('image_path')
                            ->label('Image')
                            ->image()
                            ->disk('public')               // stores in storage/app/public
                            ->directory('products')        // e.g., storage/app/public/products/...
                            ->maxSize(3_000)               // ~3 MB
                            ->imageEditor()                // optional: allow crop/resize
                            ->hint('PNG/JPG, up to ~3MB'),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options(['draft' => 'Draft', 'published' => 'Published'])
                            ->default('draft'),


                        // Variations table-style editor
                        Forms\Components\Repeater::make('variations')
                            ->label('Product Variations')
                            ->defaultItems(1)
                            ->minItems(1)
                            ->columns(6)
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->label('Title')
                                    ->required()
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('price')
                                    ->label('Price')
                                    ->prefix('£')
                                    ->numeric()
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('stock')
                                    ->label('Stock')
                                    ->numeric()
                                    ->default(0)
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('max_qty')
                                    ->label('Max Qty')
                                    ->numeric()
                                    ->default(0)
                                    ->columnSpan(1),

                                Forms\Components\Select::make('status')
                                    ->label('Status')
                                    ->options(['draft' => 'Draft', 'published' => 'Published'])
                                    ->default('published')
                                    ->columnSpan(1),
                            ])
                            ->addActionLabel('Add Variation')
                            ->itemLabel(fn ($state) => $state['title'] ?? 'Variation')
                            ->columnSpanFull(),
                    ])
                    ->action(function (array $data): void {
                        // 1) Create the product
                        /** @var \App\Models\Product $product */
                        $product = Product::create([
                            'name'        => $data['name'],
                            'description' => $data['description'] ?? null,
                            'image_path'  => $data['image_path'] ?? null,
                            'status'      => $data['status'] ?? 'draft',
                        ]);

                        // 2) Create variations
                        foreach (($data['variations'] ?? []) as $v) {
                            $product->variations()->create([
                                'title'      => $v['title'],
                                'price'      => $v['price'] ?? null,
                                'sort_order' => $v['sort_order'] ?? 0,
                                'stock'      => $v['stock'] ?? 0,
                                'max_qty'    => $v['max_qty'] ?? 0,
                                'status'     => $v['status'] ?? 'published',
                            ]);
                        }

                        // Determine a sensible default max for this service: use highest variation max_qty if provided, else product max, else 1.
                        $maxForService = (int) (collect($data['variations'] ?? [])->max('max_qty') ?? ($data['max_qty'] ?? 1));

                        $this->getRelationship()->attach($product->id, [
                            'active'     => true,
                            'min_qty'    => 1,
                            'max_qty'    => max(1, $maxForService),
                            'price'      => null,
                            'sort_order' => 0,
                        ]);

                        $this->dispatch('refresh');
                    }),

                Actions\Action::make('remove_product')
                    ->label('Remove product')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->form([
                        Forms\Components\Select::make('product_id')
                            ->label('Product')
                            ->options(fn () => $this->getRelationship()->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        if (! isset($data['product_id'])) return;
                        $this->getRelationship()->detach($data['product_id']);
                        $this->dispatch('refresh');
                    }),
            ])

            // Columns
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('pivot.active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('variations_count')
                    ->label('Variations')
                    ->sortable(),
            ])

            // Row actions (using \Filament\Actions\Action)
            ->actions([
                Actions\Action::make('manage_product')
                    ->label('Edit product')
                    ->icon('heroicon-m-pencil-square')
                    ->modalHeading('Edit Product')
                    ->modalWidth('7xl')
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->label('Product Name')
                            ->required(),


                        Forms\Components\RichEditor::make('description')
                            ->label('Description')
                            ->toolbarButtons(['bold','italic','link','bulletList','orderedList','blockquote','redo','undo']),
                        
                        Forms\Components\FileUpload::make('image_path')
                            ->label('Image')
                            ->image()
                            ->disk('public')
                            ->directory('products')
                            ->visibility('public') // OK to include; safe on Filament v3
                            ->openable()
                            ->downloadable()
                            ->imageEditor()
                            ->columnSpanFull(),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options(['draft' => 'Draft', 'published' => 'Published'])
                            ->default('draft'),

                        Forms\Components\Repeater::make('variations')
                            ->label('Product Variations')
                            ->reorderable('sort_order')
                            ->defaultItems(0)
                            ->columns(6)
                            ->schema([
                                Forms\Components\Hidden::make('id'),

                                Forms\Components\TextInput::make('title')
                                    ->label('Title')
                                    ->required()
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('price')
                                    ->label('Price')
                                    ->prefix('£')
                                    ->numeric()
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('stock')
                                    ->label('Stock')
                                    ->numeric()
                                    ->default(0)
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('max_qty')
                                    ->label('Max Qty')
                                    ->numeric()
                                    ->default(0)
                                    ->columnSpan(1),

                                Forms\Components\Select::make('status')
                                    ->label('Status')
                                    ->options(['draft'=>'Draft','published'=>'Published'])
                                    ->default('draft')
                                    ->columnSpan(1),
                            ])
                            ->addActionLabel('Add Variation')
                            ->itemLabel(fn ($state) => $state['title'] ?? 'Variation')
                            ->columnSpanFull(),
                    ])
                    ->fillForm(function ($record) {
                        return [
                            'name'        => $record->name,
                            'description' => $record->description,
                            'image_path'  => $record->image_path,
                            'status'      => $record->status ?? 'draft',
                            'variations'  => $record->variations()->get()->map(fn($v) => [
                                'id'         => $v->id,
                                'title'      => $v->title,
                                'price'      => $v->price,
                                'sort_order' => $v->sort_order,
                                'stock'      => $v->stock,
                                'max_qty'    => $v->max_qty,
                                'status'     => $v->status,
                            ])->all(),
                        ];
                    })
                    ->action(function ($record, array $data) {
                        // Update product fields
                        $record->update([
                            'name'        => $data['name'],
                            'description' => $data['description'] ?? null,
                            'status'      => $data['status'] ?? 'draft',
                            'image_path'  => $data['image_path'] ?? $record->image_path,
                        ]);

                        // Upsert variations
                        $ids = [];
                        foreach (($data['variations'] ?? []) as $v) {
                            $payload = [
                                'title'      => $v['title'] ?? '',
                                'price'      => $v['price'] ?? null,
                                'sort_order' => $v['sort_order'] ?? 0,
                                'stock'      => $v['stock'] ?? 0,
                                'max_qty'    => $v['max_qty'] ?? 0,
                                'status'     => $v['status'] ?? 'draft',
                            ];

                            if (!empty($v['id'])) {
                                $variation = ProductVariation::query()
                                    ->whereKey($v['id'])
                                    ->where('product_id', $record->id)
                                    ->first();

                                if ($variation) {
                                    $variation->update($payload);
                                    $ids[] = $variation->id;
                                    continue;
                                }
                            }

                            $variation = new ProductVariation($payload);
                            $variation->product()->associate($record);
                            $variation->save();
                            $ids[] = $variation->id;
                        }

                        // Delete removed variations
                        $record->variations()->whereNotIn('id', $ids)->delete();

                        $this->dispatch('refresh');
                    }),
                Actions\Action::make('remove_from_service')
                    ->label('Remove')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $this->getRelationship()->detach($record->id);
                        $this->dispatch('refresh');
                    }),
            ])

            ->filters([]);
    }
}