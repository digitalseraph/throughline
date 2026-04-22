<?php

use App\Services\Jobs\Drivers\AdzunaDriver;
use App\Services\Jobs\Drivers\AshbyDriver;
use App\Services\Jobs\Drivers\GreenhouseDriver;
use App\Services\Jobs\Drivers\LeverDriver;
use App\Services\Jobs\Drivers\RemoteOkDriver;
use App\Services\Jobs\Drivers\RemotiveDriver;
use App\Services\Jobs\Drivers\WorkableDriver;

return [
    'drivers' => [
        'adzuna' => AdzunaDriver::class,
        'ashby' => AshbyDriver::class,
        'greenhouse' => GreenhouseDriver::class,
        'lever' => LeverDriver::class,
        'remoteok' => RemoteOkDriver::class,
        'remotive' => RemotiveDriver::class,
        'workable' => WorkableDriver::class,
    ],
];
