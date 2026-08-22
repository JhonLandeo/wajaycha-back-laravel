<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => [
        'api/*',
        '/login',
        '/logout',
        '/sanctum/csrf-cookie',

    ],

    'allowed_methods' => ['*'],

    /*
     * Antes era `[env('FRONTEND_URL', ...)]`: un solo origen, y por lo tanto
     * ninguna forma de mudar la aplicacion de dominio sin una ventana en la que
     * algo esta roto. Ver App\Support\FrontendOrigins para el porque de las dos
     * variables.
     *
     * `FRONTEND_EXTRA_ORIGINS` acepta varios separados por coma y se vacia
     * cuando la mudanza termina. Dejarlo lleno de dominios viejos es ampliar la
     * superficie de la API sin que nadie lo haya decidido.
     */
    'allowed_origins' => App\Support\FrontendOrigins::allowed(
        env('FRONTEND_URL', 'http://localhost:5173'),
        env('FRONTEND_EXTRA_ORIGINS'),
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
