<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Presupuestos de las llamadas salientes
|--------------------------------------------------------------------------
|
| Cada dependencia externa del sistema entra por App\Support\OutboundHttp, y
| ese es el unico lugar que lee este archivo. Los perfiles viven aca y no
| adentro de cada servicio por una razon operativa concreta: cuando Gemini se
| pone lento a las once de la noche, subir un timeout tiene que ser una
| variable de entorno y un `config:cache`, no un deploy.
|
| Antes de esto ningun servicio declaraba timeout ni reintento. El default de
| Guzzle igual cortaba, pero nadie lo habia elegido, y un 429 transitorio de
| Gemini se convertia directamente en "no pude leer ese envio" para el usuario
| — que en un bot de captura no se lee como un error de red, se lee como que el
| producto no anda.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Base comun
    |--------------------------------------------------------------------------
    |
    | Todo perfil arranca de aca y sobreescribe solo lo que necesita distinto.
    | Un perfil nuevo que no declara nada queda protegido igual, que es el punto
    | de tener defaults: olvidarse tiene que ser seguro.
    |
    */

    'defaults' => [
        'timeout' => (int) env('HTTP_TIMEOUT', 15),
        'connect_timeout' => (int) env('HTTP_CONNECT_TIMEOUT', 5),

        /** Reintentos DESPUES del primer intento: 2 significa 3 llamadas como maximo. */
        'retries' => (int) env('HTTP_RETRIES', 2),

        /**
         * Backoff exponencial: 250 ms, 500 ms, 1 s... Se pone en 0 en el entorno
         * de testing (phpunit.xml) para que la suite ejercite los reintentos sin
         * pagarlos en reloj.
         */
        'retry_base_delay_ms' => (int) env('HTTP_RETRY_BASE_DELAY_MS', 250),

        /**
         * Techo del backoff, y tambien del `Retry-After` que mande el servidor.
         * Telegram puede pedir esperas de minutos cuando lo limitan; obedecerlas
         * al pie de la letra dejaria un worker de la cola bloqueado todo ese
         * rato, que es peor que perder el mensaje.
         */
        'retry_max_delay_ms' => (int) env('HTTP_RETRY_MAX_DELAY_MS', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Perfiles
    |--------------------------------------------------------------------------
    |
    | Nombrados por proposito, no por proveedor: `telegram` y `telegram_send`
    | hablan con la misma API y tienen presupuestos distintos porque uno lee y
    | el otro escribe.
    |
    | ADVERTENCIA AL TOCAR ESTOS NUMEROS. Un job encolado no tiene el presupuesto
    | de una llamada, tiene el de TODAS las que hace. La ruta de captura con foto
    | son cuatro: getFile, la descarga, Gemini y la respuesta, mas otra respuesta
    | si ademas habla el coach. `OutboundHttp::worstCaseSecondsFor()` calcula el
    | peor caso de un perfil y `ProcessTelegramCapture::$timeout` esta puesto en
    | funcion de esa suma; `OutboundHttpBudgetTest` afirma la relacion. Subir un
    | timeout o un reintento aca sin revisar el job hace fallar la suite, que es
    | exactamente lo que NO pasaba cuando este archivo se escribio.
    |
    */

    'profiles' => [

        /**
         * Inferencia con una imagen en base64 adentro del cuerpo. Es la llamada
         * mas lenta del sistema y la que mas caro sale abandonar antes de
         * tiempo: cortarla temprano tira a la basura tokens que ya se facturaron.
         *
         * Bajo de 45 s con 2 reintentos a 30 s con 1. La revision acotada midio
         * que 45 x 3 son 135 s de presupuesto para UNA llamada, cuando el worker
         * entero vive 60 s. Reintentar dos veces una inferencia de medio minuto
         * no es resiliencia: es ocupar un worker hasta que lo maten. El segundo
         * intento captura casi todo el beneficio de un fallo transitorio.
         */
        'gemini' => [
            'timeout' => 25,
            'connect_timeout' => 10,
            'retries' => 1,
        ],

        /**
         * Lecturas de Telegram: getFile y la descarga del archivo. Idempotentes.
         *
         * 10 s alcanza de sobra para una llamada de archivo, y la ruta de captura
         * con foto hace DOS de estas antes de siquiera llamar a Gemini. Conserva
         * los 2 reintentos del default: son idempotentes, que es justamente la
         * diferencia con `telegram_send`.
         */
        'telegram' => [
            'timeout' => 10,
        ],

        /**
         * sendMessage, que NO es idempotente y no acepta clave de idempotencia.
         *
         * El reintento se deja en uno solo y la razon es un intercambio real,
         * no un descuido: si Telegram acepta el mensaje y la respuesta se pierde
         * en el camino, el reintento manda un segundo "✅ Registrado: S/ 50" y el
         * usuario puede creer que se guardo dos veces. Sin reintento, en cambio,
         * cualquier hipo de red le deja una captura sin ninguna confirmacion.
         *
         * Se eligio el duplicado ocasional por sobre el silencio: un mensaje
         * repetido se entiende leyendolo, una confirmacion que nunca llega no.
         * Un reintento en vez de dos acota la ventana.
         */
        'telegram_send' => [
            'timeout' => 10,
            'retries' => 1,
        ],

        /** Lecturas de Meta Graph API: resolucion y descarga de media. Idempotentes. */
        'meta' => [],

        /**
         * Envio de mensajes de WhatsApp. Mismo razonamiento que `telegram_send`
         * y por el mismo motivo: es una escritura sin clave de idempotencia, y
         * un reintento de mas le duplica la confirmacion al usuario.
         */
        'meta_send' => [
            'retries' => 1,
        ],
    ],

];
