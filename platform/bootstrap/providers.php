<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\ConsolePanelProvider;
use App\Providers\Filament\SchoolPanelProvider;
use App\Providers\PlatformServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    ConsolePanelProvider::class,
    SchoolPanelProvider::class,
];
