<?php

namespace App\Filament\School\Pages;

use App\Exceptions\Refusal;
use App\Http\Controllers\SessionController;
use App\Support\SchoolScreen;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/** Username or email, then the API's TOTP/recovery challenge and progressive lockout. */
final class SchoolLogin extends SimplePage
{
    protected string $view = 'filament.school.login';
    public ?array $data = [];

    public function getTitle(): string { return __('school.sign_in'); }

    public function mount(): void
    {
        if (auth()->check()) { $this->redirect('/school/home'); return; }
        $this->form->fill();
    }

    public function pending(): bool { return session()->has('auth.pending'); }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components($this->pending() ? [
            TextInput::make('code')->label(__('school.mfa_code'))->autocomplete('one-time-code')->maxLength(20),
            TextInput::make('recovery_code')->label(__('school.recovery_code'))->maxLength(40),
        ] : [
            TextInput::make('login')->label(__('school.login'))->required()->maxLength(200)->autocomplete('username'),
            TextInput::make('password')->label(__('school.password'))->password()->required()->maxLength(200)->autocomplete('current-password'),
            TextInput::make('school')->label(__('school.school_slug'))->maxLength(40)->visible(!config('platform.dedicated_school')),
        ]);
    }

    public function authenticate(): void
    {
        try {
            $data = array_filter($this->form->getState(), fn ($v) => $v !== null && $v !== '');
            $method = $this->pending() ? 'mfa' : 'login';
            $result = SchoolScreen::payload(app()->call([app(SessionController::class), $method], ['request' => SchoolScreen::request($data)]));
            $this->data = [];
            // The form is built once per request: the page comes again, with the code's field.
            if ($result['mfa_required'] ?? false) { $this->redirect('/school/login'); return; }
            $this->redirect(($result['password_change_required'] ?? false) ? '/school/credentials' : '/school/home');
        } catch (Refusal $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            if (!session()->has('auth.pending')) $this->form->fill();
        } catch (ValidationException $e) {
            Notification::make()->title(__('school.invalid'))->body(implode("\n", $e->validator->errors()->all()))->danger()->send();
        }
    }

    public function restart(): void
    {
        session()->forget('auth.pending');
        $this->redirect('/school/login');
    }
}
