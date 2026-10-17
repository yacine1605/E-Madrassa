<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\NotificationController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** FR-NOT-01: the person's notifications, the unread ones counted in the menu, and which events also come by email. */
final class Notifications extends SchoolPage implements HasTable
{
    use InteractsWithTable;

    protected static bool $isDiscovered = true;
    protected static ?string $section = 'personal';
    protected static ?int $navigationSort = 2;
    protected string $view = 'filament.school.table';

    public static function getNavigationLabel(): string { return __('services.notifications'); }
    public function getTitle(): string { return __('services.notifications'); }

    /** The bell: unread notifications, as row-level security counts them for this person. */
    public static function getNavigationBadge(): ?string
    {
        $unread = (int) DB::selectOne('SELECT count(*) AS n FROM school.notification WHERE read_at IS NULL')->n;
        return $unread > 0 ? Formats::number($unread) : null;
    }

    public function table(Table $table): Table
    {
        return $table->records(fn (int $page, int $recordsPerPage) => $this->apiPage([], NotificationController::class, 'index', [], $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->poll('30s')->columns([
                TextColumn::make('labels')->label(__('services.notification'))->state(fn (array $record) => Locales::label($record['labels']))
                    ->weight(fn (array $record) => $record['read_at'] ? null : 'bold'),
                TextColumn::make('read_at')->label(__('school.status'))->badge()
                    ->state(fn (array $record) => __('services.states.'.($record['read_at'] ? 'read' : 'unread')))
                    ->color(fn (array $record) => $record['read_at'] ? 'gray' : 'primary'),
                TextColumn::make('created_at')->label(__('services.received_at'))->formatStateUsing(fn ($state) => Formats::dateTime($state)),
            ])->headerActions([
                Action::make('readAll')->label(__('services.mark_all_read'))->visible(fn () => SchoolScreen::writable(null))
                    ->action(fn () => SchoolScreen::change(null, fn () => SchoolScreen::call([], NotificationController::class, 'readAll'))),
                Action::make('preferences')->label(__('services.email_preferences'))->visible(fn () => SchoolScreen::writable(null))
                    ->fillForm(fn () => ['events' => array_column(array_filter($this->preferences(), fn ($event) => $event['email']), 'event')])
                    ->schema([CheckboxList::make('events')->label(__('services.email_preferences'))->helperText(__('services.email_help'))
                        ->options(fn () => collect($this->preferences())->mapWithKeys(fn ($event) => [$event['event'] => Locales::label($event['labels'])])->all())])
                    ->action(fn (array $data) => SchoolScreen::change(null, fn () => SchoolScreen::call([], NotificationController::class, 'updatePreferences',
                        ['email' => collect($this->preferences())->mapWithKeys(fn ($event) => [$event['event'] => in_array($event['event'], $data['events'] ?? [], true)])->all()]))),
            ])->recordActions([
                Action::make('task')->label(__('services.see_task'))->visible(fn (array $record) => isset($record['data']['job_id']))->url(Tasks::getUrl()),
                // FR-CRM-01 AC1: a due reminder opens its prospect, which the reader reads with their own rights.
                Action::make('lead')->label(__('admission.see_lead'))->visible(fn (array $record) => isset($record['data']['lead_id']))
                    ->url(fn (array $record) => LeadSheet::getUrl(['lead' => $record['data']['lead_id']])),
                // FR-GRP-02: a group's composition changed; its lead teacher opens it.
                Action::make('group')->label(__('services.see_group'))->visible(fn (array $record) => isset($record['data']['group_id']))
                    ->url(fn (array $record) => GroupMembers::getUrl(['group' => $record['data']['group_id']])),
                // FR-FIN-06: instalments falling due open the arrears.
                // FR-PRE-01: a pupil reached the absence threshold; the alerts open.
                Action::make('absences')->label(__('attendance.see_absence_alerts'))->visible(fn (array $record) => isset($record['data']['alert']))
                    ->url(AbsenceAlerts::getUrl()),
                Action::make('arrears')->label(__('finance.see_arrears'))->visible(fn (array $record) => isset($record['data']['arrears']))->url(Arrears::getUrl()),
                Action::make('read')->label(__('services.mark_read'))->visible(fn (array $record) => !$record['read_at'] && SchoolScreen::writable(null))
                    ->action(fn (array $record) => SchoolScreen::change(null,
                        fn () => SchoolScreen::call([], NotificationController::class, 'read', parameters: ['notification' => $record['id']]))),
            ]);
    }

    private function preferences(): array
    {
        return SchoolScreen::call([], NotificationController::class, 'preferences')['data'];
    }
}
