<?php

use App\Services\Jobs\Drivers\AshbyDriver;
use App\Services\Jobs\Drivers\GreenhouseDriver;
use App\Services\Jobs\Drivers\LeverDriver;
use App\Services\Jobs\Drivers\RemoteOkDriver;
use App\Services\Jobs\Drivers\WorkableDriver;

return [
    'drivers' => [
        'ashby' => AshbyDriver::class,
        'greenhouse' => GreenhouseDriver::class,
        'lever' => LeverDriver::class,
        'remoteok' => RemoteOkDriver::class,
        'workable' => WorkableDriver::class,
    ],
];
