<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders()
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Production runs behind a reverse proxy (DirectAdmin) that terminates
        // HTTPS and forwards plain HTTP to PHP. Without this, Laravel reads the
        // scheme as http://, so Livewire upload endpoints / Storage::url() /
        // asset() are generated as http://... and the host's HTTP→HTTPS redirect
        // turns Livewire's POST into a GET → MethodNotAllowedHttpException.
        // Trusting the proxy lets Laravel honor X-Forwarded-Proto and emit https.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
