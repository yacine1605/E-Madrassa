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
            Notification::make()->title($refusal[1] === 'duplicate' ? 'Cet identifiant court est déjà pris.' : $refusal[2])->danger()->send();
            throw new Halt();
        }
        if ($created['delivery'] === 'failed') {
            Notification::make()->title('Établissement créé, mais l’invitation n’a pas pu partir : transmettez ce lien de façon sûre, valable 72 heures.')
                ->body($created['activation_url'])->warning()->persistent()->send();
        }
        return Tenant::query()->findOrFail($created['tenant']);
    }

    protected function getRedirectUrl(): string
    {
        return TenantResource::getUrl('index');
    }
}
