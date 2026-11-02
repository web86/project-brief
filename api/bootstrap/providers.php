<?php

use App\Providers\AppServiceProvider;
use App\Providers\DemoServiceProvider;

return [
    AppServiceProvider::class,
    ...(class_exists(DemoServiceProvider::class) ? [DemoServiceProvider::class] : []),
];
