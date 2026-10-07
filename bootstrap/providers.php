<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\SupportAttachmentServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    SupportAttachmentServiceProvider::class,
];
