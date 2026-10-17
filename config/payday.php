<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Kill Switch
    |--------------------------------------------------------------------------
    |
    | Ships dark. While false, the daily send and the reminder claim nothing and
    | send nothing. Deliberately NOT listed in compose's `x-backend-env`: it is
    | read from the application's own environment file.
    |
    */

    'enabled' => (bool) env('PAYDAY_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Retry Grace
    |--------------------------------------------------------------------------
    |
    | How many days after payday_on an unsent plan message (send_failed, or
    | claimed but never sent) keeps being retried by the daily run. After that
    | the cycle stays as it is and nothing more is sent.
    |
    */

    'grace_days' => 3,
];
