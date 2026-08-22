<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Quién tiene permitido llamar a esta API desde un navegador.
 *
 * `config/cors.php` tenía un solo origen: `[env('FRONTEND_URL')]`. Alcanzaba
 * mientras la aplicación vivió en un único dominio, y convierte cualquier mudanza
 * en una ventana rota — el día que un dominio nuevo empieza a servir la app, sus
 * llamadas mueren por CORS, y cambiar `FRONTEND_URL` para arreglarlo mata al
 * viejo en el mismo instante. No hay forma de hacer el salto sin que alguien vea
 * una pantalla que no anda.
 *
 * Por eso hay dos variables y no una lista sola, que sería más corta y peor:
 *
 * - `FRONTEND_URL` es **canónico y único**: dónde vive la aplicación. Los links
 *   que se mandan por mail se arman con este, y "el primero de la lista" no es
 *   una respuesta que alguien pueda deducir leyendo un `.env`.
 * - `FRONTEND_EXTRA_ORIGINS` son los que **además** pueden llamar. Existe para
 *   que una mudanza tenga solape en vez de corte, y para vaciarse cuando termina.
 *
 * Un origen es esquema + host + puerto. Sin ruta y sin barra final: el navegador
 * manda `https://app.wajaycha.com` en la cabecera `Origin`, y una barra de más
 * acá no matchea con nada.
 */
final class FrontendOrigins
{
    /**
     * @return list<string>
     */
    public static function allowed(?string $canonical, ?string $extra): array
    {
        $origins = array_merge(
            self::split($canonical),
            self::split($extra),
        );

        // `array_unique` y no un `array_flip`: repetir un origen en la cabecera
        // no rompe nada, pero deja un `.env` que parece decir dos cosas.
        return array_values(array_unique($origins));
    }

    /**
     * @return list<string>
     */
    private static function split(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $parts = array_map(
            // La barra final se saca acá y no se le pide al operador que la
            // recuerde: es el error más fácil de cometer y el más difícil de ver,
            // porque el navegador nunca la manda.
            static fn (string $origin): string => rtrim(trim($origin), '/'),
            explode(',', $raw),
        );

        return array_values(array_filter(
            $parts,
            static fn (string $origin): bool => $origin !== '',
        ));
    }
}
