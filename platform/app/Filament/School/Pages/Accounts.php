<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\AccountController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

final class Accounts extends SchoolPage implements HasTable
{
    use InteractsWithTable;

    protected static bool $isDiscovered = true;
    protected static array $permissions = ['user.account.read'];
    protected static ?int $navigationSort = 10;
    protected static string|array $routeMiddleware = 'permission:user.account.read';
    protected string $view = 'filament.school.table';

    public static function getNavigationLabel(): string { return __('school.accounts'); }
    public function getTitle(): string { return __('school.accounts'); }

    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, array $filters, int $page, int $recordsPerPage) {
            $data = ['paginate' => true, 'page' => $page, 'per_page' => $recordsPerPage, 'q' => $search];
            if (!empty($filters['status']['value'])) $data['status'] = $filters['status']['value'];
            $result = SchoolScreen::call('user.account.read', AccountController::class, 'index', $data);
            return new LengthAwarePaginator(collect($result['data'])->keyBy('id'), $result['meta']['total'], $recordsPerPage, $page);
        })->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('name')->label(__('school.name'))->searchable(),
                TextColumn::make('email')->label(__('school.email')),
                TextColumn::make('username')->label(__('school.username')),
                TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('school.account_status.'.$state)),
                IconColumn::make('mfa_enabled')->label(__('school.mfa'))->boolean(),
                TextColumn::make('created_at')->label(__('school.created_at'))->formatStateUsing(fn ($state) => Formats::date($state)),
            ])->filters([SelectFilter::make('status')->label(__('school.status'))->options(__('school.account_status'))])
            ->headerActions([
                Action::make('create')->label(__('school.create_account'))->visible(fn () => $this->writable('user.account.create'))
                    ->schema([...$this->identity(), Select::make('method')->label(__('school.activation_method'))->options(__('school.activation_methods'))->default('link')->required()])
                    ->action(function (array $data) {
                        $result = SchoolScreen::change('user.account.create', fn () => SchoolScreen::call('user.account.create', AccountController::class, 'store', $data));
                        $this->delivery($result);
                    }),
            ])->recordActions([
                Action::make('edit')->label(__('school.edit'))->visible(fn (array $record) => $record['status'] !== 'revoked' && $this->writable('user.account.update'))
                    ->fillForm(fn (array $record) => $record)->schema($this->identity())
                    ->action(fn (array $record, array $data) => SchoolScreen::change('user.account.update',
                        fn () => SchoolScreen::call('user.account.update', AccountController::class, 'update', $data, ['user' => $record['id']]))),
                Action::make('invite')->label(__('school.resend_invitation'))
                    ->visible(fn (array $record) => $record['status'] === 'invited' && $this->writable('user.account.create'))->requiresConfirmation()
                    ->action(function (array $record) {
                        $result = SchoolScreen::change('user.account.create', fn () => SchoolScreen::call('user.account.create', AccountController::class, 'invitation', parameters: ['user' => $record['id']]));
                        $this->delivery($result);
                    }),
                Action::make('resetPassword')->label(__('school.reset_password'))->requiresConfirmation()
                    ->visible(fn (array $record) => !$record['email'] && $record['status'] === 'active' && $this->writable('user.account.update'))
                    ->action(function (array $record) {
                        $this->delivery(SchoolScreen::change('user.account.update', fn () => SchoolScreen::call('user.account.update', AccountController::class, 'password', parameters: ['user' => $record['id']])));
                    }),
                $this->statusAction('suspend', 'suspended', 'active'),
                $this->statusAction('reactivate', 'active', 'suspended'),
                $this->statusAction('revoke', 'revoked'),
            ]);
    }

    private function identity(): array
    {
        return [TextInput::make('name')->label(__('school.name'))->required()->maxLength(200),
            TextInput::make('email')->label(__('school.email'))->email()->maxLength(200),
            TextInput::make('username')->label(__('school.username'))->regex('/^[A-Za-z0-9][A-Za-z0-9._-]{2,39}$/'),
            TextInput::make('external_ref')->label(__('school.external_ref'))->maxLength(100)];
    }

    private function statusAction(string $name, string $status, ?string $from = null): Action
    {
        return Action::make($name)->label(__('school.'.$name))->color($status === 'active' ? 'success' : 'danger')->requiresConfirmation()
            ->visible(fn (array $record) => $record['id'] !== auth()->id() && ($from ? $record['status'] === $from : $record['status'] !== 'revoked')
                && $this->writable('user.account.suspend'))->schema([$this->reason()])
            ->action(fn (array $record, array $data) => SchoolScreen::change('user.account.suspend',
                fn () => SchoolScreen::call('user.account.suspend', AccountController::class, 'status', [...$data, 'status' => $status], ['user' => $record['id']])));
    }

    private function delivery(array $result): void
    {
        if (isset($result['provisional_password'])) Notification::make()->title(__('school.provisional_password'))->body($result['provisional_password'])->warning()->persistent()->send();
        elseif (isset($result['activation_url'])) Notification::make()->title(__('school.invitation_failed'))->body($result['activation_url'])->warning()->persistent()->send();
        elseif (($result['delivery'] ?? null) === 'failed') Notification::make()->title(__('school.invitation_failed_existing'))->warning()->send();
        elseif (($result['delivery'] ?? null) === 'sent') Notification::make()->title(__('school.invitation_sent'))->success()->send();
    }
}
