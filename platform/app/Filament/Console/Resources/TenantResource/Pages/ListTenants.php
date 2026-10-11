<?php

namespace App\Filament\Console\Resources\TenantResource\Pages;

use App\Filament\Console\Resources\TenantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('console.tenant.create'))];
    }
}
