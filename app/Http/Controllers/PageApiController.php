<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PageApiController extends Controller
{
    public function showBySlug(string $slug)
    {
        $page = Page::query()
            ->where('slug', $slug)
            ->whereRaw('LOWER(status) = ?', ['published'])
            ->where('active', true)
            ->where(function ($q) {
                $q->whereNull('visibility')->orWhere('visibility', 'public');
            })
            ->firstOrFail();

        return response()->json($this->transform($page));
    }

    public function showById(int $id)
    {
        $page = Page::query()
            ->where('id', $id)
            ->whereRaw('LOWER(status) = ?', ['published'])
            ->where('active', true)
            ->where(function ($q) {
                $q->whereNull('visibility')->orWhere('visibility', 'public');
            })
            ->firstOrFail();

        return response()->json($this->transform($page));
    }

    protected function transform(Page $p): array
    {
        return [
            'id' => $p->id,
            'title' => $p->title ?? $p->name ?? '',
            'slug' => $p->slug,
            'content' => $p->content ?? $p->description ?? '',
            'meta_title' => $p->meta_title,
            'meta_description' => $p->meta_description,
            'gallery' => is_array($p->gallery)
                ? $p->gallery
                : (is_string($p->gallery) && $p->gallery !== '' ? json_decode($p->gallery, true) ?: [] : []),
            'created_at' => $p->created_at,
            'updated_at' => $p->updated_at,
        ];
    }
}