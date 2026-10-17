<?php

declare(strict_types=1);

it('ships the payday feature off with a three day grace window', function () {
    $config = require base_path('config/payday.php');

    expect($config['enabled'])->toBeFalse()
        ->and($config['grace_days'])->toBe(3);
});
