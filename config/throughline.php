<?php

use App\Services\Jobs\Drivers\GreenhouseDriver;
use App\Services\Jobs\Drivers\LeverDriver;
use App\Services\Jobs\Drivers\RemoteOkDriver;
use App\Services\Jobs\Drivers\WorkableDriver;

return [
    'drivers' => [
        'greenhouse' => GreenhouseDriver::class,
        'lever' => LeverDriver::class,
        'remoteok' => RemoteOkDriver::class,
        'workable' => WorkableDriver::class,
    ],
];
