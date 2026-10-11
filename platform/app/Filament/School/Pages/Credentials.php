<?php

namespace App\Filament\School\Pages;

use App\Exceptions\Refusal;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\PreferenceController;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class Credentials extends Page
{
    protected static bool $shouldRegisterNavigation = false;
    protected string $view = 'filament.school.credentials';
    #[Locked] public ?string $mfaSecret = null;
    #[Locked] public array $recoveryCodes = [];

    public function getTitle(): string { return __('school.credentials'); }

    public function passwordAction(): Action
    {
        return Action::make('password')->label(__('school.change_password'))->schema([
            TextInput::make('current_password')->label(__('school.current_password'))->password()->required()->maxLength(200)->autocomplete('current-password'),
            TextInput::make('password')->label(__('school.new_password'))->password()->required()->minLength(10)->maxLength(200)->autocomplete('new-password'),
            TextInput::make('password_confirmation')->label(__('school.confirm_password'))->password()->required()->maxLength(200)->autocomplete('new-password'),
        ])->action(function (array $data) {
            $this->personal(fn () => app(CredentialController::class)->password(SchoolScreen::request($data)));
        });
    }

    public function languageAction(): Action
    {
        return Action::make('language')->label(__('school.language'))->visible(fn () => !auth()->user()->must_change_password)
            ->fillForm(fn () => ['locale' => auth()->user()->locale])
            ->schema([Select::make('locale')->label(__('school.language'))->options(array_intersect_key(Locales::NAMES, array_flip(Locales::AVAILABLE)))
                ->placeholder(__('school.follow_school'))])
            ->action(function (array $data) {
                $this->personal(fn () => app(PreferenceController::class)->locale(SchoolScreen::request(['locale' => $data['locale'] ?? null])));
                $this->redirect('/school/credentials');
            });
    }

    public function startMfaAction(): Action
    {
        return Action::make('startMfa')->label(__('school.enable_mfa'))->visible(fn () => !auth()->user()->mfa_enabled_at && !auth()->user()->must_change_password)
            ->action(function () {
                $result = $this->personal(fn () => app()->call([app(CredentialController::class), 'startMfa'], ['request' => SchoolScreen::request()]));
                $this->mfaSecret = $result['secret'];
            });
    }

    public function confirmMfaAction(): Action
    {
        return Action::make('confirmMfa')->label(__('school.confirm_mfa'))->visible(fn () => $this->mfaSecret !== null)
            ->schema([TextInput::make('code')->label(__('school.mfa_code'))->required()->maxLength(20)->autocomplete('one-time-code')])
            ->action(function (array $data) {
                $result = $this->personal(fn () => app()->call([app(CredentialController::class), 'confirmMfa'], ['request' => SchoolScreen::request($data)]));
                $this->recoveryCodes = $result['recovery_codes'];
                $this->mfaSecret = null;
            });
    }

    private function personal(callable $change): array
    {
        try {
            $result = SchoolScreen::payload($change());
            Notification::make()->title(__('school.saved'))->success()->send();
            return $result;
        } catch (Refusal|ValidationException $e) {
            Notification::make()->title($e instanceof Refusal ? $e->getMessage() : __('school.invalid'))
                ->body($e instanceof ValidationException ? implode("\n", $e->validator->errors()->all()) : null)->danger()->send();
            throw new \Filament\Support\Exceptions\Halt();
        }
    }
}
