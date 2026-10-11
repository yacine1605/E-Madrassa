<?php

namespace App\Filament\School\Pages;

use App\Exceptions\Refusal;
use App\Http\Controllers\AccountLinkController;
use App\Support\SchoolScreen;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The pages an activation or recovery link opens, before sign-in (FR-IAM-01, FR-IAM-02). The
 * token travels in the address's fragment, which never reaches a server: the page reads it,
 * takes it out of the address bar, then checks it with the same controller as the API. The
 * same limits as the API's public routes (NFR-SEC-05).
 */
abstract class LinkPage extends SimplePage
{
    protected string $view = 'filament.school.link';

    /** reading, then ready or invalid; done once used. Recovery without a link: request, then requested. */
    #[Locked] public string $state = 'reading';
    #[Locked] public ?string $token = null;
    #[Locked] public ?array $link = null;
    public ?array $data = [];

    abstract public function purpose(): string;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function open(string $token): void
    {
        if ($this->state !== 'reading' || !$this->allowed(['link:'.request()->ip() => [60, 60]])) return;
        try {
            $this->link = SchoolScreen::payload(app()->call([app(AccountLinkController::class), 'show'],
                ['request' => SchoolScreen::request(['token' => $token]), 'purpose' => $this->purpose()]))['data'];
            $this->token = $token;
            $this->state = 'ready';
        } catch (HttpExceptionInterface|ValidationException) {
            $this->state = 'invalid';
        }
        $this->form->fill();
    }

    /** A page opened without a link: recovery asks for the email; activation has nothing to do. */
    public function withoutToken(): void
    {
        if ($this->state !== 'reading') return;
        $this->state = $this->purpose() === 'recovery' ? 'request' : 'invalid';
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components(fn () => match (true) {
            $this->state === 'request' => [TextInput::make('email')->label(__('school.email'))->email()->required()->maxLength(200)->autocomplete('email')],
            $this->state === 'ready' && ($this->link['needs_password'] ?? true) => [
                TextInput::make('password')->label(__('school.new_password'))->password()->revealable()->required()->maxLength(200)->autocomplete('new-password'),
                TextInput::make('password_confirmation')->label(__('school.confirm_password'))->password()->required()->maxLength(200)->autocomplete('new-password'),
            ],
            default => [],
        });
    }

    public function submit(): void
    {
        if ($this->state !== 'ready' || !$this->allowed(['link:'.request()->ip() => [60, 60]])) return;
        try {
            SchoolScreen::payload(app()->call([app(AccountLinkController::class), $this->purpose() === 'activation' ? 'activate' : 'reset'],
                ['request' => SchoolScreen::request(['token' => $this->token, ...$this->form->getState()])]));
            [$this->state, $this->token, $this->data] = ['done', null, []];
        } catch (Refusal $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            if ($e->reason === 'token_invalid') [$this->state, $this->token] = ['invalid', null];
        } catch (ValidationException $e) {
            Notification::make()->title(__('school.invalid'))->body(implode("\n", $e->validator->errors()->all()))->danger()->send();
        }
    }

    /** Recovery: the same answer whether or not the address has an account. */
    public function send(): void
    {
        if ($this->state !== 'request') return;
        $email = mb_strtolower(trim((string) $this->form->getState()['email']));
        if (!$this->allowed(['recovery:'.request()->ip() => [10, 3600], 'recovery:'.hash('sha256', $email) => [3, 3600]])) return;
        app()->call([app(AccountLinkController::class), 'forgot'], ['request' => SchoolScreen::request(['email' => $email])]);
        [$this->state, $this->data] = ['requested', []];
    }

    /** @param array<string, array{int, int}> $limits key => [attempts, seconds] */
    private function allowed(array $limits): bool
    {
        foreach ($limits as $key => [$attempts]) {
            if (RateLimiter::tooManyAttempts($key, $attempts)) {
                Notification::make()->title(__('errors.too_many_attempts'))->danger()->send();
                return false;
            }
        }
        foreach ($limits as $key => [, $seconds]) RateLimiter::hit($key, $seconds);
        return true;
    }
}
