<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\SupportGrantController;
use App\Support\Formats;
use App\Support\Permissions;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * FR-PLT-04: the school's consents to the publisher's support. Given for 72 hours at most,
 * sensitive data only by a distinct consent, withdrawn at any time; each with the consultations
 * it saw (AC3), which the journal details.
 */
final class SupportAccess extends SchoolPage implements HasTable
{
    use InteractsWithTable;

    protected static bool $isDiscovered = true;
    protected static array $permissions = ['support.access.grant', 'audit.log.read'];
    protected static ?string $section = 'administration';
    protected static ?int $navigationSort = 110;
    protected static ?string $slug = 'support-access';
    protected static string|array $routeMiddleware = 'permission:support.access.grant,audit.log.read';
    protected string $view = 'filament.school.table';

    public static function getNavigationLabel(): string { return __('admin.support'); }
    public function getTitle(): string { return __('admin.support'); }
    public function getSubheading(): ?string { return __('admin.support_help'); }

    public function table(Table $table): Table
    {
        return $table->records(function (int $page, int $recordsPerPage) {
            $rows = collect(SchoolScreen::call(self::$permissions, SupportGrantController::class, 'index')['data'])->keyBy('id');
            return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
        })->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
            TextColumn::make('starts_at')->label(__('admin.starts_at'))->formatStateUsing(fn ($state) => Formats::dateTime($state)),
            TextColumn::make('expires_at')->label(__('admin.expires_at'))->formatStateUsing(fn ($state) => Formats::dateTime($state)),
            TextColumn::make('state')->label(__('school.status'))->badge()->state(fn (array $record) => self::state($record))
                ->formatStateUsing(fn (string $state) => __('admin.grant_states.'.$state))
                ->color(fn (string $state) => ['active' => 'warning', 'expired' => 'gray', 'revoked' => 'gray'][$state]),
            TextColumn::make('granted_by')->label(__('admin.granted_by')),
            TextColumn::make('reason')->label(__('admin.reason'))->limit(80),
            IconColumn::make('include_sensitive')->label(__('admin.sensitive'))->boolean(),
            TextColumn::make('consultations')->label(__('admin.consultations'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
        ])->headerActions([
            Action::make('grant')->label(__('admin.grant'))->visible(fn () => $this->writable('support.access.grant'))
                ->schema([
                    TextInput::make('hours')->label(__('admin.hours'))->numeric()->integer()->required()->minValue(1)->maxValue(72)->default(24),
                    Toggle::make('include_sensitive')->label(__('admin.include_sensitive')),
                    Textarea::make('reason')->label(__('admin.grant_reason'))->required()->minLength(3)->maxLength(1000),
                ])
                ->action(fn (array $data) => SchoolScreen::change('support.access.grant',
                    fn () => SchoolScreen::call('support.access.grant', SupportGrantController::class, 'store', $data))),
        ])->recordActions([
            Action::make('consultations')->label(__('admin.see_consultations'))
                ->visible(fn () => app(Permissions::class)->can('audit.log.read'))
                ->url(fn (array $record) => Journal::getUrl(['tableFilters' => ['object' => ['object_id' => $record['id']]]])),
            Action::make('revoke')->label(__('admin.revoke'))->color('danger')->requiresConfirmation()->modalDescription(__('admin.revoke_help'))
                ->visible(fn (array $record) => self::state($record) === 'active' && $this->writable('support.access.grant'))
                ->action(fn (array $record) => SchoolScreen::change('support.access.grant',
                    fn () => SchoolScreen::call('support.access.grant', SupportGrantController::class, 'revoke', parameters: ['grant' => $record['id']]))),
        ]);
    }

    private static function state(array $record): string
    {
        if ($record['revoked_at']) return 'revoked';
        return strtotime($record['expires_at']) > time() ? 'active' : 'expired';
    }
}
