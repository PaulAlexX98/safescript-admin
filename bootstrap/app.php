<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
       
        // Keep the default web middleware, just tell Laravel which URIs to skip for CSRF
        // This is the Laravel 12+ way — no custom middleware class required.
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
        $middleware->validateCsrfTokens(except: [
            'livewire/*',     // Livewire update endpoint
            'admin/consultations/*/forms/*/save',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
