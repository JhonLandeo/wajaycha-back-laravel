<?php

use App\Http\Middleware\Cors;
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
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'api/*', // Excluye todas las rutas de API
            'sanctum/csrf-cookie', // Excluye esta ruta para que Vue pueda obtener el token CSRF sin problema
            'login', // Excluye login si usas autenticación con tokens
            'logout',
        ]);
        
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
