<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * URIs that should be excluded from CSRF verification.
     * Kept local-only by checking app()->isLocal() in shouldSkipMiddleware().
     */
    protected $except = [
        'livewire/*',
    ];

    protected function shouldSkipMiddleware(): bool
    {
        // Only skip (i.e., allow $except) in local dev.
        // In non-local envs, fall back to the framework's default behavior.
        return app()->isLocal() ? false : parent::shouldSkipMiddleware();
    }
}