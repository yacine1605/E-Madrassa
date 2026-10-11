<?php

namespace App\Filament\Console\Resources\TenantResource\Pages;

use App\Filament\Console\Resources\TenantResource;
use App\Models\Console\Tenant;
use App\Support\Console;
use App\Support\DatabaseErrors;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/** FR-PLT-01: one form, one school, whatever the number of clicks (AC3). */
final class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;
    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $created = app(Console::class)->createSchool($data);
        } catch (QueryException $e) {
            $refusal = DatabaseErrors::classify($e) ?? throw $e;
            Notification::make()->title($refusal[1] === 'duplicate' ? __('errors.slug_taken') : $refusal[2])->danger()->send();
            throw new Halt();
        }
        if ($created['delivery'] === 'failed') {
            Notification::make()->title(__('console.tenant.created_not_sent'))->body($created['activation_url'])->warning()->persistent()->send();
        }
        return Tenant::query()->findOrFail($created['tenant']);
    }

    protected function getRedirectUrl(): string
    {
        return TenantResource::getUrl('index');
    }
}
